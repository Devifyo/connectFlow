import { ref, onUnmounted } from 'vue';
import { pickRecorderMimeType } from '@/composables/recorderMime';

export function useWebcam() {
    const videoRef = ref(null);
    const isStreaming = ref(false);
    const error = ref('');
    let stream = null;
    // Each recording is a self-contained { recorder, chunks } session. Keeping the
    // chunks per-session (not in shared state) prevents a stop/restart race — e.g. the
    // 10s continuous-upload timer firing while a browser-close handler also stops the
    // recorder — from mixing two recordings into a headerless, unplayable blob.
    let activeSession = null;

    async function startCamera() {
        error.value = '';
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
                audio: true,
            });
            if (videoRef.value) {
                videoRef.value.srcObject = stream;
                await videoRef.value.play();
                isStreaming.value = true;
            }
        } catch (e) {
            if (e.name === 'NotAllowedError') {
                error.value = 'Camera access was denied. Please allow camera access in your browser settings.';
            } else if (e.name === 'NotFoundError') {
                error.value = 'No camera found on this device.';
            } else {
                error.value = 'Could not access camera: ' + e.message;
            }
        }
    }

    function stopCamera() {
        if (activeSession && activeSession.recorder.state !== 'inactive') {
            try { activeSession.recorder.stop(); } catch {}
        }
        activeSession = null;
        if (stream) {
            stream.getTracks().forEach(t => t.stop());
            stream = null;
        }
        if (videoRef.value) {
            videoRef.value.srcObject = null;
        }
        isStreaming.value = false;
    }

    function captureFrame() {
        if (!videoRef.value || !isStreaming.value) return null;
        const canvas = document.createElement('canvas');
        canvas.width = videoRef.value.videoWidth;
        canvas.height = videoRef.value.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(videoRef.value, 0, 0);
        return canvas.toDataURL('image/jpeg', 0.85);
    }

    // Last recorder failure, for diagnostics ("" when the last start succeeded).
    const recorderError = ref('');

    function startRecording() {
        if (!stream) { recorderError.value = 'no stream'; return false; }
        // Discard any still-running session so two recorders never share the stream.
        if (activeSession && activeSession.recorder.state !== 'inactive') {
            try { activeSession.recorder.stop(); } catch {}
        }
        activeSession = null;
        const mimeType = pickRecorderMimeType();
        try {
            const chunks = [];
            const options = { videoBitsPerSecond: 500000 };
            if (mimeType) options.mimeType = mimeType;
            const recorder = new MediaRecorder(stream, options);
            recorder.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) chunks.push(e.data);
            };
            recorder.onerror = (e) => { recorderError.value = 'recorder error: ' + (e?.error?.name || e?.name || 'unknown'); };
            recorder.start(100);
            activeSession = { recorder, chunks, type: recorder.mimeType || mimeType || 'video/webm', startedAt: Date.now() };
            recorderError.value = '';
            return true;
        } catch (e) {
            activeSession = null;
            recorderError.value = (e && (e.name + ': ' + e.message)) || 'start failed';
            return false;
        }
    }

    function stopRecording() {
        return new Promise((resolve) => {
            const session = activeSession;
            activeSession = null;

            if (!session) {
                resolve(null);
                return;
            }

            const { recorder, chunks } = session;
            const blobType = (session.type || 'video/webm').split(';')[0];
            const buildBlob = () => (chunks.length > 0 ? new Blob(chunks, { type: blobType }) : null);
            let settled = false;
            const finish = () => { if (settled) return; settled = true; clearTimeout(guard); resolve(buildBlob()); };

            if (recorder.state === 'inactive') {
                finish();
                return;
            }

            // Some mobile recorders never fire onstop; return whatever was captured instead of hanging.
            const guard = setTimeout(() => { recorderError.value = 'onstop timeout'; finish(); }, 3000);
            recorder.onstop = finish;
            recorder.onerror = finish;

            try {
                recorder.requestData();
            } catch {}

            try {
                recorder.stop();
            } catch {
                finish();
            }
        });
    }

    onUnmounted(() => stopCamera());

    return { videoRef, isStreaming, error, recorderError, startCamera, stopCamera, captureFrame, startRecording, stopRecording, getStream: () => stream };
}
