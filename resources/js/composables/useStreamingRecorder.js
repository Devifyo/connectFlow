import axios from 'axios';
import { pickRecorderMimeType, isWebmMime } from '@/composables/recorderMime';

// Live streaming recorder for the punch camera.
//
// ONE MediaRecorder for the whole punch (camera start → Close). This matters on iOS/WebKit:
// the first recorder on a MediaStream works, but any recorder created on that same stream after
// it records nothing at all (diagnosed on iPhone, Oct 2026). So we never stop/restart; we start
// with a timeslice and ship each slice as it is produced. Slices are persisted to IndexedDB
// first and appended server-side in order, so the server file is playable at every point and
// a locked phone only loses the last slice. Leftover slices are re-sent on the next app open.

const DB_NAME = 'faceStreamQueue';
const STORE = 'chunks';
const CHUNK_MS = 2000;
const MAX_RETRIES = 4;
const QUEUE_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

let dbPromise = null;

function openDB() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => { dbPromise = null; reject(req.error); };
    });
    return dbPromise;
}

function withTimeout(promise, ms, fallback) {
    return Promise.race([promise, new Promise(r => setTimeout(() => r(fallback), ms))]);
}

async function persistChunk(record) {
    try {
        const db = await openDB();
        const tx = db.transaction(STORE, 'readwrite');
        const req = tx.objectStore(STORE).add(record);
        return await new Promise((resolve) => {
            tx.oncomplete = () => resolve(req.result);
            tx.onerror = () => resolve(null);
            tx.onabort = () => resolve(null);
        });
    } catch { return null; }
}

async function deleteChunk(id) {
    try {
        const db = await openDB();
        db.transaction(STORE, 'readwrite').objectStore(STORE).delete(id);
    } catch {}
}

async function getAllChunks() {
    try {
        const db = await openDB();
        return await new Promise((resolve) => {
            const req = db.transaction(STORE, 'readonly').objectStore(STORE).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => resolve([]);
        });
    } catch { return []; }
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function postChunk(item) {
    const blob = item.buffer instanceof Blob ? item.buffer : new Blob([item.buffer], { type: 'video/webm' });
    const form = new FormData();
    form.append('chunk', blob, `${item.uploadId}_${item.seq}.webm`);
    form.append('upload_id', item.uploadId);
    form.append('seq', String(item.seq));
    form.append('type', item.meta.type);
    // Sent on EVERY slice: the punch is verified mid-recording, and the row must get linked even
    // if the phone dies before finalize.
    if (item.meta.timeLogId) form.append('time_log_id', String(item.meta.timeLogId));
    form.append('verified', item.meta.verified ? '1' : '0');
    if (item.seq === 0 && item.meta.location) {
        form.append('latitude', String(item.meta.location.lat));
        form.append('longitude', String(item.meta.location.lng));
        if (item.meta.location.address) form.append('address', item.meta.location.address);
    }
    await axios.post('/api/face/stream-video-chunk', form, { timeout: 120000 });
}

// In-memory record of chunks that could not be persisted (IndexedDB unavailable/stalled) so
// they still upload in this session.
const memoryQueue = [];
let memoryId = 0;

let drainPromise = null;

// Uploads every queued slice in order. Shared promise: concurrent callers wait for the SAME
// drain, and the drain loops until the queue is empty, so stop() can't finalize ahead of the
// last slice.
function drainQueue(ownerId = null) {
    if (drainPromise) return drainPromise;
    drainPromise = (async () => {
        try {
            for (let round = 0; round < 50; round++) {
                const persisted = await getAllChunks();
                const items = [...persisted, ...memoryQueue];
                if (!items.length) break;
                items.sort((a, b) => (a.uploadId === b.uploadId ? a.seq - b.seq : (a.uploadId < b.uploadId ? -1 : 1)));
                let progressed = false;
                for (const item of items) {
                    if (item.ownerId && ownerId && item.ownerId !== ownerId) {
                        // Another account's recording (admin impersonation in this browser): leave it
                        // for that account unless it is ancient.
                        if (Date.now() - (item.createdAt || 0) > QUEUE_MAX_AGE_MS) await removeItem(item);
                        continue;
                    }
                    let done = false;
                    for (let attempt = 0; attempt <= MAX_RETRIES && !done; attempt++) {
                        try {
                            await postChunk(item);
                            done = true;
                        } catch (e) {
                            const status = e?.response?.status;
                            // 409 = server is already past this seq (dup/gap); other 4xx = unrecoverable.
                            // Drop either way so one bad slice can't wedge the queue forever.
                            if (status === 409 || (status >= 400 && status < 500 && status !== 408 && status !== 429)) {
                                done = true;
                            } else if (attempt < MAX_RETRIES) {
                                await sleep(1000 * Math.pow(2, attempt));
                            }
                        }
                    }
                    if (done) { await removeItem(item); progressed = true; }
                }
                if (!progressed) break;
            }
        } finally {
            drainPromise = null;
        }
    })();
    return drainPromise;
}

async function removeItem(item) {
    if (item.memory) {
        const i = memoryQueue.indexOf(item);
        if (i >= 0) memoryQueue.splice(i, 1);
    } else {
        await deleteChunk(item.id);
    }
}

async function finalizeStream(uploadId, meta) {
    try {
        const form = new FormData();
        form.append('upload_id', uploadId);
        if (meta && meta.timeLogId) form.append('time_log_id', String(meta.timeLogId));
        form.append('verified', meta && meta.verified ? '1' : '0');
        await axios.post('/api/face/stream-video-finalize', form, { timeout: 60000 });
    } catch {}
}

export function resumeStreamQueue(ownerId = null) {
    drainQueue(ownerId);
}

export function useStreamingRecorder(ownerId = null) {
    let session = null;

    function start(stream, meta) {
        if (!stream) return null;
        // Ordered append only works for webm; a browser that can only record mp4 returns null and
        // the caller keeps a single whole-clip recorder instead.
        const mimeType = pickRecorderMimeType();
        if (!isWebmMime(mimeType)) return null;
        const uploadId = `${meta.type}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
        let recorder;
        try {
            recorder = new MediaRecorder(stream, { mimeType, videoBitsPerSecond: 500000 });
        } catch {
            return null;
        }

        const mySession = { uploadId, recorder, meta: { ...meta }, seq: 0, bytes: 0, pending: Promise.resolve(), error: '' };

        recorder.ondataavailable = (e) => {
            if (!e.data || !e.data.size) return;
            const mySeq = mySession.seq++;
            mySession.bytes += e.data.size;
            const snapshot = { ...mySession.meta };
            mySession.pending = mySession.pending.then(async () => {
                const buffer = await e.data.arrayBuffer();
                const record = { uploadId, seq: mySeq, buffer, meta: snapshot, ownerId, createdAt: Date.now() };
                const id = await withTimeout(persistChunk(record), 4000, null);
                if (id == null) memoryQueue.push({ ...record, memory: true, id: `m${memoryId++}` });
                drainQueue(ownerId);
            }).catch(() => {});
        };
        recorder.onerror = (e) => { mySession.error = e?.error?.name || e?.name || 'recorder error'; };

        try {
            recorder.start(CHUNK_MS);
        } catch (e) {
            return null;
        }
        session = mySession;
        return uploadId;
    }

    // Update what later slices and the finalize call carry (e.g. once the punch is verified).
    function setMeta(patch) {
        if (session) Object.assign(session.meta, patch);
    }

    // onRecorderStopped fires as soon as the recorder itself has stopped (before the upload drain),
    // so the caller can release the camera immediately while slices finish uploading.
    async function stop(onRecorderStopped = null) {
        const s = session;
        session = null;
        if (!s) return null;
        const t0 = Date.now();

        await new Promise((resolve) => {
            if (s.recorder.state === 'inactive') { resolve(); return; }
            const guard = setTimeout(() => { s.error = s.error || 'onstop timeout'; resolve(); }, 4000);
            const finish = () => { clearTimeout(guard); resolve(); };
            s.recorder.onstop = finish;
            s.recorder.onerror = finish;
            try { s.recorder.requestData(); } catch {}
            try { s.recorder.stop(); } catch { finish(); }
        });

        if (onRecorderStopped) { try { onRecorderStopped(); } catch {} }

        try { await s.pending; } catch {}
        await drainQueue(ownerId);
        await finalizeStream(s.uploadId, s.meta);
        return { uploadId: s.uploadId, chunks: s.seq, bytes: s.bytes, ms: Date.now() - t0, error: s.error };
    }

    function isActive() {
        return !!session;
    }

    function stats() {
        return session ? { chunks: session.seq, bytes: session.bytes, error: session.error } : null;
    }

    return { start, stop, setMeta, isActive, stats };
}
