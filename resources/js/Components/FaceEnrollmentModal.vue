<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useWebcam } from '@/composables/useWebcam';
import { useVideoUploader } from '@/composables/useVideoUploader';
import axios from 'axios';

const emit = defineEmits(['enrolled']);

const { videoRef, isStreaming, error: cameraError, startCamera, stopCamera, captureFrame, startRecording, stopRecording } = useWebcam();
const { uploadStatus, uploadProgress, queueUpload } = useVideoUploader();

const step = ref('instructions');
const capturedImage = ref(null);
const enrolling = ref(false);
const enrollError = ref('');

onMounted(() => {
    document.body.style.overflow = 'hidden';
});

onUnmounted(() => {
    stopCamera();
    document.body.style.overflow = '';
});

async function beginCapture() {
    step.value = 'camera';
    await startCamera();
    startRecording();
}

function takePhoto() {
    const image = captureFrame();
    if (image) {
        capturedImage.value = image;
        step.value = 'preview';
        stopCamera();
    }
}

function retake() {
    capturedImage.value = null;
    enrollError.value = '';
    step.value = 'camera';
    startCamera();
    startRecording();
}

async function submitEnrollment() {
    enrolling.value = true;
    enrollError.value = '';
    const videoBlob = await stopRecording();
    try {
        await axios.post('/api/face/enroll', { image: capturedImage.value });
        stopCamera();
        queueUpload(videoBlob, 'enrollment');
        emit('enrolled');
    } catch (e) {
        enrollError.value = e.response?.data?.error || 'Enrollment failed. Please try again.';
    } finally {
        enrolling.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 backdrop-blur-md">
            <div class="bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-surface-800/50">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center">
                            <svg class="w-5 h-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-surface-100">Face Enrollment Required</h3>
                            <p class="text-xs text-surface-500 mt-0.5">Register your face to use punch in/out</p>
                        </div>
                    </div>
                </div>

                <!-- Body -->
                <div class="px-6 py-5">
                    <!-- Step 1: Instructions -->
                    <div v-if="step === 'instructions'" class="text-center">
                        <div class="w-20 h-20 rounded-2xl bg-surface-800/50 border border-surface-700/30 flex items-center justify-center mx-auto mb-4">
                            <svg class="w-10 h-10 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
                            </svg>
                        </div>
                        <p class="text-sm text-surface-300 mb-2">Your organization requires face verification for attendance.</p>
                        <p class="text-xs text-surface-500 mb-6">This is a one-time setup. Your face data is stored securely and only used for punch in/out verification.</p>

                        <div class="text-left mx-auto max-w-xs mb-6">
                            <p class="text-xs font-medium text-surface-400 mb-2.5">For best results:</p>
                            <ul class="space-y-2 text-xs text-surface-500">
                                <li class="flex items-start gap-2">
                                    <svg class="w-3.5 h-3.5 text-brand flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                    Ensure good, even lighting on your face
                                </li>
                                <li class="flex items-start gap-2">
                                    <svg class="w-3.5 h-3.5 text-brand flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                    Look directly at the camera
                                </li>
                                <li class="flex items-start gap-2">
                                    <svg class="w-3.5 h-3.5 text-brand flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                    Remove sunglasses or face coverings
                                </li>
                            </ul>
                        </div>

                        <button @click="beginCapture" class="btn-primary text-sm w-full py-3">
                            <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                            </svg>
                            Open Camera & Enroll
                        </button>
                    </div>

                    <!-- Step 2: Camera -->
                    <div v-else-if="step === 'camera'">
                        <div v-if="cameraError" class="text-center py-8">
                            <div class="w-12 h-12 rounded-full bg-red-500/10 flex items-center justify-center mx-auto mb-3">
                                <svg class="w-6 h-6 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                                </svg>
                            </div>
                            <p class="text-sm text-red-400 mb-3">{{ cameraError }}</p>
                            <button @click="beginCapture" class="btn-secondary text-xs px-4 py-2">Try Again</button>
                        </div>

                        <div v-else>
                            <div class="relative rounded-xl overflow-hidden bg-black mb-4">
                                <video ref="videoRef" class="w-full" autoplay playsinline muted></video>
                                <div class="absolute inset-0 pointer-events-none">
                                    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-48 h-48 sm:w-56 sm:h-56 rounded-full border-2 border-brand/40"></div>
                                </div>
                                <div v-if="!isStreaming" class="absolute inset-0 flex items-center justify-center">
                                    <div class="w-8 h-8 border-2 border-brand/30 border-t-brand rounded-full animate-spin"></div>
                                </div>
                            </div>
                            <p class="text-xs text-surface-500 text-center mb-4">Position your face within the circle</p>
                            <button @click="takePhoto" :disabled="!isStreaming" class="btn-primary text-sm w-full py-3 disabled:opacity-30">
                                Capture Photo
                            </button>
                        </div>
                    </div>

                    <!-- Step 3: Preview & Submit -->
                    <div v-else-if="step === 'preview'">
                        <div class="rounded-xl overflow-hidden bg-black mb-4">
                            <img :src="capturedImage" class="w-full" />
                        </div>

                        <div v-if="enrollError" class="p-3 rounded-lg bg-red-500/10 border border-red-500/20 mb-4">
                            <p class="text-xs text-red-400">{{ enrollError }}</p>
                        </div>

                        <div class="flex gap-3">
                            <button @click="retake" :disabled="enrolling" class="btn-secondary text-sm flex-1 py-2.5">
                                Retake
                            </button>
                            <button @click="submitEnrollment" :disabled="enrolling" class="btn-primary text-sm flex-1 py-2.5">
                                <span v-if="enrolling" class="flex items-center justify-center gap-2">
                                    <div class="w-3.5 h-3.5 border-2 border-current/30 border-t-current rounded-full animate-spin"></div>
                                    Enrolling...
                                </span>
                                <span v-else>Confirm & Enroll</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
