<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useVideoUploader } from '@/composables/useVideoUploader';
import FaceScanCapture from '@/Components/FaceScanCapture.vue';
import axios from 'axios';

const emit = defineEmits(['enrolled']);

const { queueUpload } = useVideoUploader();

const step = ref('scan');
const enrolling = ref(false);
const enrollError = ref('');
let scanResult = null;

onMounted(() => {
    document.body.style.overflow = 'hidden';
});

onUnmounted(() => {
    document.body.style.overflow = '';
});

async function onScanComplete(result) {
    scanResult = result;
    enrolling.value = true;
    enrollError.value = '';
    step.value = 'processing';

    try {
        await axios.post('/api/face/enroll', { image: scanResult.image });
        if (scanResult.video) {
            queueUpload(scanResult.video, 'enrollment');
        }
        emit('enrolled');
    } catch (e) {
        enrollError.value = e.response?.data?.error || 'Enrollment failed. Please try again.';
        step.value = 'error';
    } finally {
        enrolling.value = false;
    }
}

function retryScan() {
    enrollError.value = '';
    scanResult = null;
    step.value = 'scan';
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
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75H6A2.25 2.25 0 0 0 3.75 6v1.5M16.5 3.75H18A2.25 2.25 0 0 1 20.25 6v1.5m0 9V18A2.25 2.25 0 0 1 18 20.25h-1.5m-9 0H6A2.25 2.25 0 0 1 3.75 18v-1.5"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-surface-100">Face Registration Required</h3>
                            <p class="text-xs text-surface-500 mt-0.5">3D face scan to enable punch in/out</p>
                        </div>
                    </div>
                </div>

                <!-- Body -->
                <div class="px-6 py-5">
                    <!-- Scan step -->
                    <FaceScanCapture v-if="step === 'scan'" @complete="onScanComplete" @cancel="() => {}" />

                    <!-- Processing -->
                    <div v-else-if="step === 'processing'" class="text-center py-10">
                        <div class="w-16 h-16 rounded-full bg-brand/10 flex items-center justify-center mx-auto mb-4">
                            <div class="w-8 h-8 border-2 border-brand/30 border-t-brand rounded-full animate-spin"></div>
                        </div>
                        <p class="text-sm font-medium text-surface-200 mb-1">Registering your face...</p>
                        <p class="text-xs text-surface-500">This may take a moment</p>
                    </div>

                    <!-- Error -->
                    <div v-else-if="step === 'error'" class="text-center py-8">
                        <div class="w-14 h-14 rounded-full bg-red-500/10 flex items-center justify-center mx-auto mb-4">
                            <svg class="w-7 h-7 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                        </div>
                        <p class="text-sm text-red-400 mb-4">{{ enrollError }}</p>
                        <button @click="retryScan" class="px-6 py-2.5 text-sm font-medium bg-brand hover:bg-brand-light text-surface-950 rounded-xl transition-colors">
                            Try Again
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
