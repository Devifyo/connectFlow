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

async function persistToQueue(blob, type, timeLogId, verified) {
    try {
        const db = await openDB();
        const arrayBuffer = await blob.arrayBuffer();
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).add({
            buffer: arrayBuffer,
            mimeType: blob.type,
            type,
            timeLogId: timeLogId || null,
            verified: verified !== false,
            createdAt: Date.now(),
        });
        return new Promise((resolve, reject) => {
            tx.oncomplete = resolve;
            tx.onerror = () => reject(tx.error);
        });
    } catch {}
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

async function uploadWithRetry(blob, type, timeLogId, verified, onProgress) {
    const useChunked = blob.size > CHUNK_SIZE * 2;

    for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
        try {
            if (useChunked) {
                await uploadChunked(blob, type, timeLogId, verified, onProgress);
            } else {
                await uploadWhole(blob, type, timeLogId, verified, onProgress);
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

async function uploadWhole(blob, type, timeLogId, verified, onProgress) {
    const form = new FormData();
    form.append('video', blob, `${type}_${Date.now()}.webm`);
    form.append('type', type);
    if (timeLogId) form.append('time_log_id', String(timeLogId));
    form.append('verified', verified ? '1' : '0');

    await axios.post('/api/face/upload-video', form, {
        timeout: 120000,
        onUploadProgress: (e) => {
            if (onProgress && e.total) {
                onProgress({ status: 'uploading', progress: Math.round((e.loaded / e.total) * 100) });
            }
        },
    });
}

async function uploadChunked(blob, type, timeLogId, verified, onProgress) {
    const totalChunks = Math.ceil(blob.size / CHUNK_SIZE);
    const uploadId = `${type}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

    for (let i = 0; i < totalChunks; i++) {
        const start = i * CHUNK_SIZE;
        const end = Math.min(start + CHUNK_SIZE, blob.size);
        const chunk = blob.slice(start, end);

        const form = new FormData();
        form.append('chunk', chunk, `${uploadId}_part${i}.webm`);
        form.append('upload_id', uploadId);
        form.append('chunk_index', String(i));
        form.append('total_chunks', String(totalChunks));
        form.append('type', type);
        if (timeLogId) form.append('time_log_id', String(timeLogId));
        form.append('verified', verified ? '1' : '0');

        let chunkAttempt = 0;
        while (true) {
            try {
                await axios.post('/api/face/upload-video-chunk', form, { timeout: 60000 });
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

async function processQueue(onProgress) {
    if (processingQueue) return;
    processingQueue = true;
    try {
        const items = await getAllQueued();
        for (const item of items) {
            const blob = new Blob([item.buffer], { type: item.mimeType || 'video/webm' });
            const verified = item.verified !== false;
            const ok = await uploadWithRetry(blob, item.type, item.timeLogId, verified, onProgress);
            if (ok) {
                await removeFromQueue(item.id);
            }
        }
    } finally {
        processingQueue = false;
    }
}

export function useVideoUploader() {
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

    async function queueUpload(blob, type, timeLogId, verified = true) {
        if (!blob) return;

        await persistToQueue(blob, type, timeLogId, verified);
        uploadWithRetry(blob, type, timeLogId, verified, handleProgress).then(async (ok) => {
            if (ok) {
                const items = await getAllQueued();
                const match = items.find(i => i.type === type && i.timeLogId === (timeLogId || null) && i.verified === verified);
                if (match) await removeFromQueue(match.id);
            }
        });
    }

    onUnmounted(() => {
        active = false;
    });

    processQueue(handleProgress);

    return { uploadStatus, uploadProgress, queueUpload };
}
