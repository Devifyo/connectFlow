import axios from 'axios';
import { pickRecorderMimeType, isWebmMime } from '@/composables/recorderMime';

// Live streaming recorder: each MediaRecorder chunk is persisted to IndexedDB the moment
// it is produced and uploaded (appended server-side) in order. The server file therefore
// stays playable at every point, so a fast browser-close only ever loses the last ~2s.
// Any chunks that didn't upload before the tab closed stay queued and are re-sent the next
// time the app opens (call resumeStreamQueue on mount).

const DB_NAME = 'faceStreamQueue';
const STORE = 'chunks';
const CHUNK_MS = 2000;
const MAX_RETRIES = 4;

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

async function persistChunk(record) {
    try {
        const db = await openDB();
        const tx = db.transaction(STORE, 'readwrite');
        const req = tx.objectStore(STORE).add(record);
        return new Promise((resolve) => {
            tx.oncomplete = () => resolve(req.result);
            tx.onerror = () => resolve(null);
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
    if (item.seq === 0) {
        if (item.meta.timeLogId) form.append('time_log_id', String(item.meta.timeLogId));
        form.append('verified', item.meta.verified === false ? '0' : '1');
        if (item.meta.location) {
            form.append('latitude', String(item.meta.location.lat));
            form.append('longitude', String(item.meta.location.lng));
            if (item.meta.location.address) form.append('address', item.meta.location.address);
        }
    }
    await axios.post('/api/face/stream-video-chunk', form, { timeout: 30000 });
}

let draining = false;

async function drainQueue() {
    if (draining) return;
    draining = true;
    try {
        const items = await getAllChunks();
        // Same upload's chunks must go in seq order; the server rejects gaps.
        items.sort((a, b) => (a.uploadId === b.uploadId ? a.seq - b.seq : (a.uploadId < b.uploadId ? -1 : 1)));
        for (const item of items) {
            let done = false;
            for (let attempt = 0; attempt <= MAX_RETRIES && !done; attempt++) {
                try {
                    await postChunk(item);
                    done = true;
                } catch (e) {
                    const status = e?.response?.status;
                    // 409 = server is already ahead (dup/gap); 4xx = unrecoverable. Drop either
                    // way so one bad chunk can't wedge the queue forever.
                    if (status === 409 || (status >= 400 && status < 500 && status !== 408 && status !== 429)) {
                        done = true;
                    } else if (attempt < MAX_RETRIES) {
                        await sleep(1000 * Math.pow(2, attempt));
                    }
                }
            }
            if (done) await deleteChunk(item.id);
        }
    } finally {
        draining = false;
    }
}

async function finalizeStream(uploadId, meta) {
    try {
        const form = new FormData();
        form.append('upload_id', uploadId);
        if (meta && meta.timeLogId) form.append('time_log_id', String(meta.timeLogId));
        await axios.post('/api/face/stream-video-finalize', form, { timeout: 15000 });
    } catch {}
}

export function resumeStreamQueue() {
    drainQueue();
}

export function useStreamingRecorder() {
    let session = null;

    function start(stream, meta) {
        if (!stream) return null;
        // Live append only works for webm (ordered chunk concatenation). iOS records mp4,
        // which can't be appended this way — return null so the caller uses whole-clip upload.
        const mimeType = pickRecorderMimeType();
        if (!isWebmMime(mimeType)) return null;
        const uploadId = `${meta.type}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
        let recorder;
        try {
            recorder = new MediaRecorder(stream, { mimeType, videoBitsPerSecond: 500000 });
        } catch {
            return null;
        }

        let seq = 0;
        let pending = Promise.resolve();

        recorder.ondataavailable = (e) => {
            if (!e.data || !e.data.size) return;
            const mySeq = seq++;
            pending = pending.then(async () => {
                const buffer = await e.data.arrayBuffer();
                await persistChunk({ uploadId, seq: mySeq, buffer, meta });
                drainQueue();
            });
        };

        recorder.start(CHUNK_MS);
        session = { uploadId, recorder, meta, pending: () => pending };
        return uploadId;
    }

    async function stop() {
        const s = session;
        session = null;
        if (!s) return;

        await new Promise((resolve) => {
            if (s.recorder.state === 'inactive') { resolve(); return; }
            s.recorder.onstop = resolve;
            try { s.recorder.requestData(); } catch {}
            try { s.recorder.stop(); } catch { resolve(); }
        });

        try { await s.pending(); } catch {}
        await drainQueue();
        await finalizeStream(s.uploadId, s.meta);
    }

    function isActive() {
        return !!session;
    }

    return { start, stop, isActive };
}
