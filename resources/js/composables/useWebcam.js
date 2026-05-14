import { ref, onUnmounted } from 'vue';

export function useWebcam() {
    const videoRef = ref(null);
    const isStreaming = ref(false);
    const error = ref('');
    let stream = null;
    let mediaRecorder = null;
    let recordedChunks = [];

    async function startCamera() {
        error.value = '';
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
                audio: false,
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

    function startRecording() {
        if (!stream) return;
        recordedChunks = [];
        const mimeType = MediaRecorder.isTypeSupported('video/webm;codecs=vp9')
            ? 'video/webm;codecs=vp9'
            : 'video/webm';
        try {
            mediaRecorder = new MediaRecorder(stream, {
                mimeType,
                videoBitsPerSecond: 500000,
            });
            mediaRecorder.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) recordedChunks.push(e.data);
            };
            mediaRecorder.start(200);
        } catch (e) {
            // MediaRecorder not supported — video recording silently skipped
        }
    }

    function stopRecording() {
        return new Promise((resolve) => {
            if (!mediaRecorder || mediaRecorder.state === 'inactive') {
                resolve(null);
                return;
            }
            mediaRecorder.onstop = () => {
                if (recordedChunks.length === 0) {
                    resolve(null);
                    return;
                }
                const blob = new Blob(recordedChunks, { type: 'video/webm' });
                recordedChunks = [];
                resolve(blob);
            };
            mediaRecorder.stop();
        });
    }

    onUnmounted(() => stopCamera());

    return { videoRef, isStreaming, error, startCamera, stopCamera, captureFrame, startRecording, stopRecording };
}
