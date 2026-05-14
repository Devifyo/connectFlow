import { ref, onUnmounted } from 'vue';
import { FaceLandmarker, FilesetResolver } from '@mediapipe/tasks-vision';

let sharedLandmarker = null;
let initPromise = null;

async function getSharedLandmarker() {
    if (sharedLandmarker) return sharedLandmarker;
    if (initPromise) return initPromise;
    initPromise = (async () => {
        const vision = await FilesetResolver.forVisionTasks(
            'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@latest/wasm'
        );
        sharedLandmarker = await FaceLandmarker.createFromOptions(vision, {
            baseOptions: {
                modelAssetPath: 'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task',
                delegate: 'GPU',
            },
            runningMode: 'VIDEO',
            numFaces: 1,
            outputFaceBlendshapes: false,
            outputFacialTransformationMatrixes: false,
        });
        return sharedLandmarker;
    })();
    return initPromise;
}

export function useFaceMesh() {
    const yaw = ref(0);
    const pitch = ref(0);
    const faceDetected = ref(false);
    const ready = ref(false);

    let landmarker = null;
    let animFrame = null;
    let videoEl = null;
    let running = false;

    async function init() {
        landmarker = await getSharedLandmarker();
        ready.value = true;
    }

    function processFrame() {
        if (!running || !videoEl || !landmarker) return;
        if (videoEl.readyState < 2) {
            animFrame = requestAnimationFrame(processFrame);
            return;
        }

        try {
            const result = landmarker.detectForVideo(videoEl, performance.now());
            if (result.faceLandmarks && result.faceLandmarks.length > 0) {
                faceDetected.value = true;
                const lm = result.faceLandmarks[0];

                const noseTip = lm[1];
                const leftEar = lm[234];
                const rightEar = lm[454];
                const forehead = lm[10];
                const chin = lm[152];

                const earMidX = (leftEar.x + rightEar.x) / 2;
                const earSpan = Math.abs(leftEar.x - rightEar.x) || 0.001;
                yaw.value = ((noseTip.x - earMidX) / earSpan) * 100;

                const faceMidY = (forehead.y + chin.y) / 2;
                const faceHeight = Math.abs(chin.y - forehead.y) || 0.001;
                pitch.value = ((noseTip.y - faceMidY) / faceHeight) * 100;
            } else {
                faceDetected.value = false;
                yaw.value = 0;
                pitch.value = 0;
            }
        } catch {
            faceDetected.value = false;
        }

        if (running) {
            animFrame = requestAnimationFrame(processFrame);
        }
    }

    function startTracking(video) {
        videoEl = video;
        running = true;
        processFrame();
    }

    function stopTracking() {
        running = false;
        if (animFrame) {
            cancelAnimationFrame(animFrame);
            animFrame = null;
        }
    }

    async function destroy() {
        stopTracking();
        ready.value = false;
    }

    onUnmounted(() => {
        stopTracking();
    });

    return { yaw, pitch, faceDetected, ready, init, startTracking, stopTracking, destroy };
}
