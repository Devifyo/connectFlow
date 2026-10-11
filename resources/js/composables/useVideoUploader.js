import { ref, onUnmounted } from 'vue';
import axios from 'axios';

const CHUNK_SIZE = 512 * 1024;
const MAX_RETRIES = 5;
const BASE_DELAY = 2000;
const DB_NAME = 'faceVideoQueue';
const STORE_NAME = 'pending';

let dbPromise = null;

function openDB() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, 2);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => {
            dbPromise = null;
            reject(req.error);
        };
    });
    return dbPromise;
}

// Recordings are validated SERVER-side (ffprobe) after upload. Checking playability in the
// browser proved unreliable on iOS (webm blobs may never fire loadedmetadata) and silently
// dropped good clips, so the client only rejects obviously empty blobs.
const MIN_BLOB_BYTES = 1024;
const QUEUE_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

function newUploadId(type) {
    return `${type}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
}

async function persistToQueue(blob, type, timeLogId, verified, location, uploadId, ownerId) {
    try {
        const db = await openDB();
        const arrayBuffer = await blob.arrayBuffer();
        const tx = db.transaction(STORE_NAME, 'readwrite');
        const req = tx.objectStore(STORE_NAME).add({
            buffer: arrayBuffer,
            mimeType: blob.type,
            type,
            timeLogId: timeLogId || null,
            verified: verified !== false,
            location: location || null,
            // Stable id so an interrupted upload RESUMES on the server instead of creating a duplicate.
            uploadId,
            // Who recorded it. The queue lives in the browser, so after admin impersonation the next
            // logged-in user must not upload someone else's clip under their own name.
            ownerId: ownerId || null,
            createdAt: Date.now(),
        });
        return new Promise((resolve, reject) => {
            tx.oncomplete = () => resolve(req.result);
            tx.onerror = () => reject(tx.error);
        });
    } catch {
        return null;
    }
}

async function removeFromQueue(id) {
    try {
        const db = await openDB();
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).delete(id);
    } catch {}
}

async function getAllQueued() {
    try {
        const db = await openDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            const req = tx.objectStore(STORE_NAME).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => reject(req.error);
        });
    } catch {
        return [];
    }
}

function sleep(ms) {
    return new Promise(r => setTimeout(r, ms));
}

async function uploadWithRetry(blob, type, timeLogId, verified, onProgress, location, uploadId) {
    const useChunked = blob.size > CHUNK_SIZE * 2;

    for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
        try {
            if (useChunked) {
                await uploadChunked(blob, type, timeLogId, verified, onProgress, location, uploadId);
            } else {
                await uploadWhole(blob, type, timeLogId, verified, onProgress, location);
            }
            return true;
        } catch (e) {
            const status = e?.response?.status;
            if (status && status >= 400 && status < 500 && status !== 408 && status !== 429) {
                return false;
            }
            if (attempt < MAX_RETRIES) {
                const delay = BASE_DELAY * Math.pow(2, attempt) + Math.random() * 1000;
                if (onProgress) onProgress({ status: 'retrying', attempt: attempt + 1, maxRetries: MAX_RETRIES });
                await sleep(delay);
            }
        }
    }
    return false;
}

function extFor(blob) {
    return blob && blob.type && blob.type.indexOf('mp4') !== -1 ? 'mp4' : 'webm';
}

async function uploadWhole(blob, type, timeLogId, verified, onProgress, location) {
    const form = new FormData();
    form.append('video', blob, `${type}_${Date.now()}.${extFor(blob)}`);
    form.append('type', type);
    if (timeLogId) form.append('time_log_id', String(timeLogId));
    form.append('verified', verified ? '1' : '0');
    if (location) {
        form.append('latitude', String(location.lat));
        form.append('longitude', String(location.lng));
        if (location.address) form.append('address', location.address);
    }

    await axios.post('/api/face/upload-video', form, {
        timeout: 120000,
        onUploadProgress: (e) => {
            if (onProgress && e.total) {
                onProgress({ status: 'uploading', progress: Math.round((e.loaded / e.total) * 100) });
            }
        },
    });
}

async function uploadChunked(blob, type, timeLogId, verified, onProgress, location, uploadId) {
    const totalChunks = Math.ceil(blob.size / CHUNK_SIZE);
    const ext = extFor(blob);
    uploadId = uploadId || newUploadId(type);

    for (let i = 0; i < totalChunks; i++) {
        const start = i * CHUNK_SIZE;
        const end = Math.min(start + CHUNK_SIZE, blob.size);
        const chunk = blob.slice(start, end);

        const form = new FormData();
        form.append('chunk', chunk, `${uploadId}_part${i}.${ext}`);
        form.append('upload_id', uploadId);
        form.append('chunk_index', String(i));
        form.append('total_chunks', String(totalChunks));
        form.append('ext', ext);
        form.append('type', type);
        if (timeLogId) form.append('time_log_id', String(timeLogId));
        form.append('verified', verified ? '1' : '0');
        if (location) {
            form.append('latitude', String(location.lat));
            form.append('longitude', String(location.lng));
            if (location.address) form.append('address', location.address);
        }

        let chunkAttempt = 0;
        while (true) {
            try {
                const { data } = await axios.post('/api/face/upload-video-chunk', form, { timeout: 60000 });
                // Resuming: the server already holds this chunk (and maybe more). Skip ahead to the
                // first missing one instead of re-sending everything.
                if (data && data.status === 'duplicate' && Number.isInteger(data.last_seq) && data.last_seq > i) {
                    i = data.last_seq;
                }
                if (data && (data.status === 'complete' || data.status === 'dropped')) {
                    if (onProgress) onProgress({ status: 'uploading', progress: 100 });
                    return;
                }
                break;
            } catch (e) {
                const status = e?.response?.status;
                if (status && status >= 400 && status < 500 && status !== 408 && status !== 429) throw e;
                chunkAttempt++;
                if (chunkAttempt > MAX_RETRIES) throw e;
                await sleep(BASE_DELAY * Math.pow(2, chunkAttempt - 1) + Math.random() * 1000);
            }
        }

        if (onProgress) {
            onProgress({ status: 'uploading', progress: Math.round(((i + 1) / totalChunks) * 100) });
        }
    }
}

let processingQueue = false;

// Number of clips currently being sent (shared across all uploader instances on the page),
// so the punch modal can hold its Close button until everything recorded is on the server.
const activeUploads = ref(0);

function waitForUploads(timeoutMs = 120000) {
    return new Promise((resolve) => {
        const started = Date.now();
        const tick = () => {
            if (activeUploads.value <= 0 || Date.now() - started >= timeoutMs) { resolve(activeUploads.value <= 0); return; }
            setTimeout(tick, 200);
        };
        tick();
    });
}

async function processQueue(onProgress, ownerId) {
    if (processingQueue) return;
    processingQueue = true;
    try {
        const items = await getAllQueued();
        for (const item of items) {
            // Recorded by a different account in this same browser (admin impersonation): leave it for
            // that account, unless it is so old nobody will come back for it.
            if (item.ownerId && ownerId && item.ownerId !== ownerId) {
                if (Date.now() - (item.createdAt || 0) > QUEUE_MAX_AGE_MS) await removeFromQueue(item.id);
                continue;
            }
            const blob = new Blob([item.buffer], { type: item.mimeType || 'video/webm' });
            if (blob.size < MIN_BLOB_BYTES) {
                await removeFromQueue(item.id);
                continue;
            }
            const verified = item.verified !== false;
            activeUploads.value++;
            let ok = false;
            try {
                ok = await uploadWithRetry(blob, item.type, item.timeLogId, verified, onProgress, item.location, item.uploadId || newUploadId(item.type));
            } finally {
                activeUploads.value--;
            }
            if (ok) {
                await removeFromQueue(item.id);
            }
        }
    } finally {
        processingQueue = false;
    }
}

export function useVideoUploader(ownerId = null) {
    const uploadStatus = ref('idle');
    const uploadProgress = ref(0);
    let active = true;

    function handleProgress({ status, progress }) {
        if (!active) return;
        if (status === 'uploading') {
            uploadStatus.value = 'uploading';
            uploadProgress.value = progress ?? 0;
        } else if (status === 'retrying') {
            uploadStatus.value = 'retrying';
        }
    }

    async function queueUpload(blob, type, timeLogId, verified = true, location = null) {
        if (!blob || blob.size < MIN_BLOB_BYTES) return;

        // Count it as in flight immediately (before any await) so a waitForUploads() started
        // right after this call sees it.
        activeUploads.value++;
        const uploadId = newUploadId(type);

        try {
            // Persist FIRST so a fast browser-close (right after punch) can't lose the clip —
            // it stays queued and resumes (same upload id) next time the app opens. IndexedDB on
            // some phones can stall, so never let persistence block the upload itself.
            const queueId = await Promise.race([
                persistToQueue(blob, type, timeLogId, verified, location, uploadId, ownerId),
                new Promise(r => setTimeout(() => r(null), 5000)),
            ]);

            uploadWithRetry(blob, type, timeLogId, verified, handleProgress, location, uploadId)
                .then(async (ok) => {
                    if (ok && queueId != null) {
                        await removeFromQueue(queueId);
                    }
                })
                .finally(() => { activeUploads.value--; });
        } catch {
            activeUploads.value--;
        }
    }

    onUnmounted(() => {
        active = false;
    });

    processQueue(handleProgress, ownerId);

    return { uploadStatus, uploadProgress, queueUpload, activeUploads, waitForUploads };
}
