<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue';
import { useWebcam } from '@/composables/useWebcam';
import { useFaceMesh } from '@/composables/useFaceMesh';

const props = defineProps({
    autostart: { type: Boolean, default: false },
});

const emit = defineEmits(['complete', 'cancel']);

const { videoRef, isStreaming, error: cameraError, startCamera, stopCamera, captureFrame, startRecording, stopRecording } = useWebcam();
const { yaw, pitch, faceDetected, ready: meshReady, init: initMesh, startTracking, stopTracking, destroy: destroyMesh } = useFaceMesh();

const scanPhases = [
    { key: 'center', label: 'Look straight ahead',         short: 'Front',  yawMin: -8,  yawMax: 8,   pitchMin: -8,  pitchMax: 8 },
    { key: 'left',   label: 'Slowly turn left',            short: 'Left',   yawMin: 15,  yawMax: 90,  pitchMin: -15, pitchMax: 15 },
    { key: 'right',  label: 'Slowly turn right',           short: 'Right',  yawMin: -90, yawMax: -15, pitchMin: -15, pitchMax: 15 },
    { key: 'up',     label: 'Tilt up slightly',            short: 'Up',     yawMin: -15, yawMax: 15,  pitchMin: -50, pitchMax: -10 },
    { key: 'down',   label: 'Tilt down slightly',          short: 'Down',   yawMin: -15, yawMax: 15,  pitchMin: 10,  pitchMax: 50 },
];

const phase = ref(-1);
const phaseProgress = ref(0);
const capturedFrames = ref([]);
const scanning = ref(false);
const moveWarning = ref('');
const loadingMesh = ref(false);
const poseCorrect = ref(false);
let tickTimer = null;

const CENTER_DURATION = 2000;
const MOVE_FILL_DURATION = 1800;
const TICK = 100;
const DECAY_RATE = 2;

const totalProgress = computed(() => {
    if (phase.value < 0) return 0;
    if (phase.value >= scanPhases.length) return 100;
    return Math.round(((phase.value + phaseProgress.value / 100) / scanPhases.length) * 100);
});

const currentPhase = computed(() => phase.value >= 0 && phase.value < scanPhases.length ? scanPhases[phase.value] : null);

const CIRC = 2 * Math.PI * 120;
const ringDash = computed(() => {
    const filled = (totalProgress.value / 100) * CIRC;
    return `${filled} ${CIRC - filled}`;
});

const phaseRingDash = computed(() => {
    const filled = (phaseProgress.value / 100) * CIRC;
    return `${filled} ${CIRC - filled}`;
});

const dotAngle = computed(() => ((totalProgress.value / 100) * 360 - 90) * (Math.PI / 180));

function isPoseInRange(p) {
    return yaw.value >= p.yawMin && yaw.value <= p.yawMax &&
           pitch.value >= p.pitchMin && pitch.value <= p.pitchMax;
}

async function begin() {
    loadingMesh.value = true;
    scanning.value = true;
    phase.value = 0;
    phaseProgress.value = 0;
    capturedFrames.value = [];
    moveWarning.value = '';
    poseCorrect.value = false;
    await nextTick();
    await startCamera();
    try { await initMesh(); } catch {}
    loadingMesh.value = false;
    startTracking(videoRef.value);
    startRecording();
    runPhase();
}

function runPhase() {
    clearTimers();
    phaseProgress.value = 0;
    moveWarning.value = '';

    const p = scanPhases[phase.value];
    const isCenter = p.key === 'center';
    const progressPerTick = isCenter
        ? (100 / (CENTER_DURATION / TICK))
        : (100 / (MOVE_FILL_DURATION / TICK));

    tickTimer = setInterval(() => {
        if (!faceDetected.value) {
            moveWarning.value = 'Position your face in the frame';
            poseCorrect.value = false;
            phaseProgress.value = Math.max(0, phaseProgress.value - DECAY_RATE);
            return;
        }

        const inRange = isPoseInRange(p);
        poseCorrect.value = inRange;

        if (inRange) {
            moveWarning.value = '';
            phaseProgress.value = Math.min(100, phaseProgress.value + progressPerTick);
        } else {
            moveWarning.value = p.label;
            phaseProgress.value = Math.max(0, phaseProgress.value - DECAY_RATE);
        }

        if (phaseProgress.value >= 100) advancePhase();
    }, TICK);
}

function advancePhase() {
    clearTimers();
    const frame = captureFrame();
    if (frame) capturedFrames.value.push({ key: scanPhases[phase.value].key, image: frame });

    phase.value++;
    if (phase.value < scanPhases.length) {
        runPhase();
    } else {
        phaseProgress.value = 100;
        moveWarning.value = '';
        poseCorrect.value = false;
        finishScan();
    }
}

async function finishScan() {
    scanning.value = false;
    stopTracking();
    const videoBlob = await stopRecording();
    const primaryImage = capturedFrames.value.find(f => f.key === 'center')?.image || capturedFrames.value[0]?.image;
    emit('complete', { image: primaryImage, frames: capturedFrames.value, video: videoBlob });
}

function cancel() {
    clearTimers();
    scanning.value = false;
    stopTracking();
    stopRecording();
    stopCamera();
    emit('cancel');
}

function clearTimers() {
    if (tickTimer) { clearInterval(tickTimer); tickTimer = null; }
}

async function retry() {
    clearTimers();
    stopTracking();
    stopCamera();
    phase.value = -1;
    phaseProgress.value = 0;
    capturedFrames.value = [];
    scanning.value = false;
    moveWarning.value = '';
    poseCorrect.value = false;
    await nextTick();
    begin();
}

const showFullscreen = computed(() => scanning.value || phase.value >= scanPhases.length || (props.autostart && phase.value === -1));

onMounted(() => {
    if (props.autostart) begin();
});

onUnmounted(() => {
    clearTimers();
    stopTracking();
    stopCamera();
});
</script>

<template>
    <Teleport to="body">
        <div v-if="showFullscreen" class="face-scan-fullscreen">
            <!-- Pre-start (fullscreen) -->
            <div v-if="phase === -1 && !cameraError" class="scan-fullscreen-inner flex flex-col items-center justify-center bg-surface-950 px-6">
                <div class="w-full max-w-sm">
                    <div class="relative w-28 h-28 mx-auto mb-5">
                        <div class="absolute inset-0 rounded-full bg-gradient-to-br from-blue-500/10 to-purple-500/10 animate-pulse-subtle"></div>
                        <div class="absolute inset-2 rounded-full bg-surface-900/80 flex items-center justify-center">
                            <svg class="w-12 h-12 text-blue-400/80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="0.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75H6A2.25 2.25 0 0 0 3.75 6v1.5M16.5 3.75H18A2.25 2.25 0 0 1 20.25 6v1.5m0 9V18A2.25 2.25 0 0 1 18 20.25h-1.5m-9 0H6A2.25 2.25 0 0 1 3.75 18v-1.5"/>
                            </svg>
                        </div>
                        <svg class="absolute inset-0 w-full h-full animate-spin-slow" viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="56" fill="none" stroke="url(#preGrad)" stroke-width="1" stroke-dasharray="8 12" opacity="0.5"/>
                            <defs><linearGradient id="preGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3b82f6"/><stop offset="100%" stop-color="#8b5cf6"/></linearGradient></defs>
                        </svg>
                    </div>

                    <h3 class="text-base font-semibold text-surface-100 mb-1.5 text-center">3D Face Scan</h3>
                    <p class="text-xs text-surface-500 mb-6 max-w-[280px] mx-auto leading-relaxed text-center">Scan your face from multiple angles for secure identity verification.</p>

                    <div class="grid grid-cols-2 gap-2 max-w-xs mx-auto mb-6">
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-800/40 border border-surface-700/20">
                            <svg class="w-3.5 h-3.5 text-blue-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636"/></svg>
                            <span class="text-[10px] text-surface-400">Good lighting</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-800/40 border border-surface-700/20">
                            <svg class="w-3.5 h-3.5 text-blue-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/></svg>
                            <span class="text-[10px] text-surface-400">No glasses</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-800/40 border border-surface-700/20">
                            <svg class="w-3.5 h-3.5 text-blue-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/></svg>
                            <span class="text-[10px] text-surface-400">Arm's length</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-800/40 border border-surface-700/20">
                            <svg class="w-3.5 h-3.5 text-blue-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12c0-1.232-.046-2.453-.138-3.662a4.006 4.006 0 0 0-3.7-3.7 48.678 48.678 0 0 0-7.324 0 4.006 4.006 0 0 0-3.7 3.7c-.017.22-.032.441-.046.662M19.5 12l3-3m-3 3-3-3m-12 3c0 1.232.046 2.453.138 3.662a4.006 4.006 0 0 0 3.7 3.7 48.656 48.656 0 0 0 7.324 0 4.006 4.006 0 0 0 3.7-3.7c.017-.22.032-.441.046-.662M4.5 12l3 3m-3-3-3 3"/></svg>
                            <span class="text-[10px] text-surface-400">Follow prompts</span>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button @click="cancel" class="flex-1 py-2.5 text-sm font-medium bg-surface-800/60 hover:bg-surface-700/60 text-surface-400 rounded-xl transition-all border border-surface-700/30">Cancel</button>
                        <button @click="begin" class="flex-1 py-2.5 text-sm font-semibold bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-400 hover:to-blue-500 text-white rounded-xl transition-all shadow-lg shadow-blue-500/20 flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75H6A2.25 2.25 0 0 0 3.75 6v1.5M16.5 3.75H18A2.25 2.25 0 0 1 20.25 6v1.5m0 9V18A2.25 2.25 0 0 1 18 20.25h-1.5m-9 0H6A2.25 2.25 0 0 1 3.75 18v-1.5"/></svg>
                            Begin Scan
                        </button>
                    </div>
                </div>
            </div>

            <!-- Camera error (fullscreen) -->
            <div v-else-if="cameraError" class="scan-fullscreen-inner flex flex-col items-center justify-center bg-surface-950 px-6">
                <div class="w-16 h-16 rounded-full bg-red-500/10 border border-red-500/20 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                </div>
                <p class="text-sm text-red-400 mb-1">Camera Error</p>
                <p class="text-xs text-surface-500 mb-4 text-center max-w-xs">{{ cameraError }}</p>
                <div class="flex gap-3">
                    <button @click="cancel" class="px-5 py-2 text-xs font-medium bg-surface-800 hover:bg-surface-700 text-surface-300 rounded-xl transition-colors border border-surface-700/50">Back</button>
                    <button @click="retry" class="px-5 py-2 text-xs font-medium bg-blue-500/20 hover:bg-blue-500/30 text-blue-300 rounded-xl transition-colors border border-blue-500/30">Try Again</button>
                </div>
            </div>

            <!-- Scanning active (fullscreen) -->
            <div v-else-if="phase >= 0 && phase < scanPhases.length" class="scan-fullscreen-inner">
                <div class="scan-viewport">
                    <video ref="videoRef" class="scan-video" autoplay playsinline muted></video>

                    <!-- Loading mesh overlay -->
                    <div v-if="loadingMesh" class="absolute inset-0 flex flex-col items-center justify-center bg-black/80 z-20">
                        <div class="relative w-16 h-16 mb-4">
                            <svg class="w-full h-full animate-spin-slow" viewBox="0 0 60 60">
                                <circle cx="30" cy="30" r="26" fill="none" stroke="url(#loadGrad)" stroke-width="2" stroke-dasharray="10 8" stroke-linecap="round"/>
                                <defs><linearGradient id="loadGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3b82f6"/><stop offset="100%" stop-color="#8b5cf6"/></linearGradient></defs>
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <div class="w-4 h-4 rounded-full bg-blue-500/30 animate-pulse"></div>
                            </div>
                        </div>
                        <p class="text-xs text-surface-300 font-medium">Initializing Face Detection</p>
                        <p class="text-[10px] text-surface-500 mt-1">Loading AI model...</p>
                    </div>

                    <!-- Scanning HUD overlay -->
                    <div v-else class="absolute inset-0 pointer-events-none">
                        <!-- Vignette -->
                        <div class="absolute inset-0 bg-[radial-gradient(circle_at_center,transparent_35%,rgba(0,0,0,0.6)_100%)]"></div>

                        <!-- Scan lines sweeping effect -->
                        <div class="absolute inset-0 overflow-hidden">
                            <div class="scan-line-1 absolute left-0 right-0 h-[1px] bg-gradient-to-r from-transparent via-blue-400/30 to-transparent"></div>
                            <div class="scan-line-2 absolute left-0 right-0 h-[1px] bg-gradient-to-r from-transparent via-purple-400/20 to-transparent"></div>
                        </div>

                        <!-- Main face ring system -->
                        <svg class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 scan-ring-svg" viewBox="0 0 260 260">
                            <circle cx="130" cy="130" r="125" fill="none" stroke="rgba(255,255,255,0.03)" stroke-width="1"/>
                            <circle cx="130" cy="130" r="125" fill="none" stroke="url(#outerTick)" stroke-width="1" stroke-dasharray="2 18" class="animate-spin-very-slow"/>
                            <circle cx="130" cy="130" r="120" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="2.5"/>
                            <circle cx="130" cy="130" r="120" fill="none"
                                stroke="url(#progressGrad)" stroke-width="2.5" stroke-linecap="round"
                                :stroke-dasharray="ringDash"
                                transform="rotate(-90 130 130)"
                                class="transition-[stroke-dasharray] duration-150"/>
                            <circle cx="130" cy="130" r="113" fill="none"
                                :stroke="poseCorrect ? 'rgba(52, 211, 153, 0.4)' : (faceDetected ? 'rgba(96, 165, 250, 0.15)' : 'rgba(239, 68, 68, 0.2)')"
                                stroke-width="1.5" stroke-linecap="round"
                                :stroke-dasharray="phaseRingDash"
                                transform="rotate(-90 130 130)"
                                class="transition-all duration-150"/>
                            <g v-if="totalProgress > 0">
                                <circle :cx="130 + 120 * Math.cos(dotAngle)" :cy="130 + 120 * Math.sin(dotAngle)"
                                    r="8" :fill="poseCorrect ? 'rgba(52, 211, 153, 0.15)' : 'rgba(59, 130, 246, 0.15)'"/>
                                <circle :cx="130 + 120 * Math.cos(dotAngle)" :cy="130 + 120 * Math.sin(dotAngle)"
                                    r="4" :fill="poseCorrect ? '#34d399' : '#60a5fa'">
                                    <animate attributeName="r" values="3;5;3" dur="1.5s" repeatCount="indefinite"/>
                                </circle>
                                <circle :cx="130 + 120 * Math.cos(dotAngle)" :cy="130 + 120 * Math.sin(dotAngle)"
                                    r="2" fill="white"/>
                            </g>
                            <circle cx="130" cy="130" r="105" fill="none"
                                :stroke="poseCorrect ? 'rgba(52, 211, 153, 0.12)' : 'rgba(255,255,255,0.04)'"
                                stroke-width="0.5" class="transition-colors duration-500"/>
                            <defs>
                                <linearGradient id="progressGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stop-color="#3b82f6"/>
                                    <stop offset="50%" stop-color="#6366f1"/>
                                    <stop offset="100%" stop-color="#8b5cf6"/>
                                </linearGradient>
                                <linearGradient id="outerTick" x1="0%" y1="0%" x2="100%" y2="0%">
                                    <stop offset="0%" stop-color="rgba(59,130,246,0.3)"/>
                                    <stop offset="100%" stop-color="rgba(139,92,246,0.3)"/>
                                </linearGradient>
                            </defs>
                        </svg>

                        <!-- Corner bracket markers -->
                        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 scan-brackets">
                            <div class="absolute top-0 left-0 w-10 h-10 border-t-[2px] border-l-[2px] rounded-tl-xl transition-colors duration-300" :class="poseCorrect ? 'border-emerald-400/70' : faceDetected ? 'border-blue-400/40' : 'border-red-400/40'"></div>
                            <div class="absolute top-0 right-0 w-10 h-10 border-t-[2px] border-r-[2px] rounded-tr-xl transition-colors duration-300" :class="poseCorrect ? 'border-emerald-400/70' : faceDetected ? 'border-blue-400/40' : 'border-red-400/40'"></div>
                            <div class="absolute bottom-0 left-0 w-10 h-10 border-b-[2px] border-l-[2px] rounded-bl-xl transition-colors duration-300" :class="poseCorrect ? 'border-emerald-400/70' : faceDetected ? 'border-blue-400/40' : 'border-red-400/40'"></div>
                            <div class="absolute bottom-0 right-0 w-10 h-10 border-b-[2px] border-r-[2px] rounded-br-xl transition-colors duration-300" :class="poseCorrect ? 'border-emerald-400/70' : faceDetected ? 'border-blue-400/40' : 'border-red-400/40'"></div>
                        </div>

                        <!-- Success pulse when pose correct -->
                        <div v-if="poseCorrect" class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 scan-pulse rounded-full border border-emerald-400/20 animate-ping-slow"></div>

                        <!-- Camera loading -->
                        <div v-if="!isStreaming" class="absolute inset-0 flex items-center justify-center bg-black/70 z-10">
                            <div class="w-8 h-8 border-2 border-blue-400/30 border-t-blue-400 rounded-full animate-spin"></div>
                        </div>
                    </div>

                    <!-- HUD: Top status bar -->
                    <div v-if="!loadingMesh" class="absolute top-0 left-0 right-0 p-3 sm:p-4 flex items-center justify-between z-10 safe-top">
                        <div class="flex items-center gap-1.5 bg-black/40 backdrop-blur-md px-2.5 py-1 rounded-full border border-white/5">
                            <div class="w-1.5 h-1.5 rounded-full transition-colors duration-300" :class="faceDetected ? (poseCorrect ? 'bg-emerald-400 shadow-emerald-400/50 shadow-sm' : 'bg-blue-400 shadow-blue-400/50 shadow-sm') : 'bg-red-400 animate-pulse'"></div>
                            <span class="text-[9px] font-medium tracking-wide uppercase transition-colors duration-300"
                                :class="faceDetected ? (poseCorrect ? 'text-emerald-400' : 'text-blue-300') : 'text-red-400'">
                                {{ faceDetected ? (poseCorrect ? 'Aligned' : 'Tracking') : 'No Face' }}
                            </span>
                        </div>
                        <div class="bg-black/40 backdrop-blur-md px-2.5 py-1 rounded-full border border-white/5">
                            <span class="text-[10px] font-mono font-semibold text-white/80">{{ totalProgress }}%</span>
                        </div>
                    </div>

                    <!-- HUD: Bottom instruction panel -->
                    <div v-if="!loadingMesh" class="absolute bottom-0 left-0 right-0 p-3 sm:p-4 z-10 safe-bottom">
                        <!-- Phase step pills -->
                        <div class="flex items-center justify-center gap-1 mb-2">
                            <div v-for="(p, i) in scanPhases" :key="p.key"
                                class="flex items-center gap-1 px-2 py-0.5 rounded-full text-[8px] sm:text-[9px] font-semibold tracking-wide uppercase transition-all duration-300 border"
                                :class="i < phase
                                    ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                                    : i === phase
                                        ? 'bg-blue-500/10 text-blue-300 border-blue-500/30 shadow-sm shadow-blue-500/10'
                                        : 'bg-black/20 text-white/30 border-white/5'">
                                <svg v-if="i < phase" class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                <div v-else-if="i === phase" class="w-1.5 h-1.5 rounded-full bg-blue-400"></div>
                                <span>{{ p.short }}</span>
                            </div>
                        </div>

                        <div class="bg-black/50 backdrop-blur-md rounded-xl px-4 py-2.5 border border-white/5 flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 transition-colors duration-300"
                                :class="poseCorrect ? 'bg-emerald-500/20' : 'bg-white/5'">
                                <svg v-if="currentPhase?.key === 'center'" class="w-5 h-5 transition-colors" :class="poseCorrect ? 'text-emerald-400' : 'text-white/70'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 2v2m0 16v2M2 12h2m16 0h2"/></svg>
                                <svg v-else-if="currentPhase?.key === 'left'" class="w-5 h-5 animate-nudge-left transition-colors" :class="poseCorrect ? 'text-emerald-400' : 'text-white/70'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
                                <svg v-else-if="currentPhase?.key === 'right'" class="w-5 h-5 animate-nudge-right transition-colors" :class="poseCorrect ? 'text-emerald-400' : 'text-white/70'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                                <svg v-else-if="currentPhase?.key === 'up'" class="w-5 h-5 animate-nudge-up transition-colors" :class="poseCorrect ? 'text-emerald-400' : 'text-white/70'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18"/></svg>
                                <svg v-else-if="currentPhase?.key === 'down'" class="w-5 h-5 animate-nudge-down transition-colors" :class="poseCorrect ? 'text-emerald-400' : 'text-white/70'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5 12 21m0 0-7.5-7.5M12 21V3"/></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-white/90 leading-tight">{{ currentPhase?.label }}</p>
                                <p class="text-[9px] mt-0.5 transition-colors duration-300"
                                    :class="poseCorrect ? 'text-emerald-400' : 'text-white/40'">
                                    {{ poseCorrect ? 'Hold steady...' : (faceDetected ? 'Move to position' : 'Face not visible') }}
                                </p>
                            </div>
                            <div class="w-9 h-9 flex-shrink-0">
                                <svg viewBox="0 0 36 36" class="w-full h-full -rotate-90">
                                    <circle cx="18" cy="18" r="14" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="2.5"/>
                                    <circle cx="18" cy="18" r="14" fill="none"
                                        :stroke="poseCorrect ? '#34d399' : '#60a5fa'"
                                        stroke-width="2.5" stroke-linecap="round"
                                        :stroke-dasharray="`${(phaseProgress / 100) * 88} 88`"
                                        class="transition-[stroke-dasharray] duration-100"/>
                                </svg>
                            </div>
                        </div>

                        <!-- Warning banner -->
                        <transition name="warn">
                            <div v-if="moveWarning && !poseCorrect" class="flex items-center gap-2 px-3 py-2 rounded-xl bg-amber-500/8 border border-amber-500/15 mt-2">
                                <div class="w-5 h-5 rounded-full bg-amber-500/15 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-3 h-3 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008v.008H12v-.008Z"/></svg>
                                </div>
                                <p class="text-[11px] text-amber-300/90 font-medium">{{ moveWarning }}</p>
                            </div>
                        </transition>

                        <!-- Cancel button -->
                        <button @click="cancel" class="w-full mt-3 py-2 text-xs font-medium text-white/50 hover:text-white/80 transition-colors">
                            Cancel Scan
                        </button>
                    </div>
                </div>
            </div>

            <!-- Scan complete (fullscreen) -->
            <div v-else-if="phase >= scanPhases.length" class="scan-fullscreen-inner flex flex-col items-center justify-center">
                <div class="relative w-24 h-24 mx-auto mb-6">
                    <div class="absolute inset-0 rounded-full bg-emerald-500/10 animate-ping-slow"></div>
                    <div class="relative w-full h-full rounded-full bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center">
                        <svg class="w-12 h-12 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    </div>
                </div>
                <p class="text-lg font-semibold text-emerald-400 mb-1">Scan Complete</p>
                <p class="text-sm text-surface-400">Registering your face identity...</p>
                <div class="flex justify-center mt-5">
                    <div class="flex gap-1.5">
                        <div class="w-2 h-2 rounded-full bg-emerald-400 animate-bounce-dot" style="animation-delay: 0s"></div>
                        <div class="w-2 h-2 rounded-full bg-emerald-400 animate-bounce-dot" style="animation-delay: 0.15s"></div>
                        <div class="w-2 h-2 rounded-full bg-emerald-400 animate-bounce-dot" style="animation-delay: 0.3s"></div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <div class="face-scan-capture">
        <!-- Camera error (inline, non-autostart only) -->
        <div v-if="!autostart && cameraError" class="text-center py-8">
            <div class="w-16 h-16 rounded-full bg-red-500/10 border border-red-500/20 flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
            </div>
            <p class="text-sm text-red-400 mb-1">Camera Error</p>
            <p class="text-xs text-surface-500 mb-4">{{ cameraError }}</p>
            <button @click="retry" class="px-5 py-2 text-xs font-medium bg-surface-800 hover:bg-surface-700 text-surface-300 rounded-xl transition-colors border border-surface-700/50">Try Again</button>
        </div>

        <!-- Pre-start (inline, non-autostart only) -->
        <div v-else-if="!autostart && phase === -1" class="text-center">
            <div class="relative w-28 h-28 mx-auto mb-5">
                <div class="absolute inset-0 rounded-full bg-gradient-to-br from-blue-500/10 to-purple-500/10 animate-pulse-subtle"></div>
                <div class="absolute inset-2 rounded-full bg-surface-900/80 flex items-center justify-center">
                    <svg class="w-12 h-12 text-blue-400/80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="0.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75H6A2.25 2.25 0 0 0 3.75 6v1.5M16.5 3.75H18A2.25 2.25 0 0 1 20.25 6v1.5m0 9V18A2.25 2.25 0 0 1 18 20.25h-1.5m-9 0H6A2.25 2.25 0 0 1 3.75 18v-1.5"/>
                    </svg>
                </div>
                <svg class="absolute inset-0 w-full h-full animate-spin-slow" viewBox="0 0 120 120">
                    <circle cx="60" cy="60" r="56" fill="none" stroke="url(#preGradInline)" stroke-width="1" stroke-dasharray="8 12" opacity="0.5"/>
                    <defs><linearGradient id="preGradInline" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3b82f6"/><stop offset="100%" stop-color="#8b5cf6"/></linearGradient></defs>
                </svg>
            </div>

            <h3 class="text-base font-semibold text-surface-100 mb-1.5">3D Face Scan</h3>
            <p class="text-xs text-surface-500 mb-6 max-w-[280px] mx-auto leading-relaxed">Scan your face from multiple angles for secure identity verification.</p>

            <div class="grid grid-cols-2 gap-2 max-w-xs mx-auto mb-6">
                <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-800/40 border border-surface-700/20">
                    <svg class="w-3.5 h-3.5 text-blue-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636"/></svg>
                    <span class="text-[10px] text-surface-400">Good lighting</span>
                </div>
                <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-800/40 border border-surface-700/20">
                    <svg class="w-3.5 h-3.5 text-blue-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/></svg>
                    <span class="text-[10px] text-surface-400">No glasses</span>
                </div>
                <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-800/40 border border-surface-700/20">
                    <svg class="w-3.5 h-3.5 text-blue-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/></svg>
                    <span class="text-[10px] text-surface-400">Arm's length</span>
                </div>
                <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-800/40 border border-surface-700/20">
                    <svg class="w-3.5 h-3.5 text-blue-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12c0-1.232-.046-2.453-.138-3.662a4.006 4.006 0 0 0-3.7-3.7 48.678 48.678 0 0 0-7.324 0 4.006 4.006 0 0 0-3.7 3.7c-.017.22-.032.441-.046.662M19.5 12l3-3m-3 3-3-3m-12 3c0 1.232.046 2.453.138 3.662a4.006 4.006 0 0 0 3.7 3.7 48.656 48.656 0 0 0 7.324 0 4.006 4.006 0 0 0 3.7-3.7c.017-.22.032-.441.046-.662M4.5 12l3 3m-3-3-3 3"/></svg>
                    <span class="text-[10px] text-surface-400">Follow prompts</span>
                </div>
            </div>

            <div class="flex gap-3">
                <button @click="cancel" class="flex-1 py-2.5 text-sm font-medium bg-surface-800/60 hover:bg-surface-700/60 text-surface-400 rounded-xl transition-all border border-surface-700/30">Cancel</button>
                <button @click="begin" class="flex-1 py-2.5 text-sm font-semibold bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-400 hover:to-blue-500 text-white rounded-xl transition-all shadow-lg shadow-blue-500/20 flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75H6A2.25 2.25 0 0 0 3.75 6v1.5M16.5 3.75H18A2.25 2.25 0 0 1 20.25 6v1.5m0 9V18A2.25 2.25 0 0 1 18 20.25h-1.5m-9 0H6A2.25 2.25 0 0 1 3.75 18v-1.5"/></svg>
                    Begin Scan
                </button>
            </div>
        </div>

        <!-- Inline placeholder while fullscreen scan is active -->
        <div v-else-if="showFullscreen" class="text-center py-6">
            <div class="w-10 h-10 rounded-full bg-blue-500/10 flex items-center justify-center mx-auto mb-3">
                <div class="w-5 h-5 border-2 border-blue-400/30 border-t-blue-400 rounded-full animate-spin"></div>
            </div>
            <p class="text-xs text-surface-400">Face scan in progress...</p>
        </div>
    </div>
</template>

<style scoped>
.face-scan-fullscreen {
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: #000;
}
.scan-fullscreen-inner {
    width: 100%;
    height: 100%;
    position: relative;
}
.scan-viewport {
    position: relative;
    width: 100%;
    height: 100%;
    overflow: hidden;
    background: #000;
}
.scan-video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.scan-ring-svg {
    width: 70vmin;
    height: 70vmin;
    max-width: 340px;
    max-height: 340px;
}
.scan-brackets {
    width: 55vmin;
    height: 55vmin;
    max-width: 260px;
    max-height: 260px;
}
.scan-pulse {
    width: 58vmin;
    height: 58vmin;
    max-width: 280px;
    max-height: 280px;
}
.safe-top {
    padding-top: max(0.75rem, env(safe-area-inset-top));
}
.safe-bottom {
    padding-bottom: max(0.75rem, env(safe-area-inset-bottom));
}
@keyframes ping-slow {
    0% { transform: translate(-50%, -50%) scale(1); opacity: 0.5; }
    100% { transform: translate(-50%, -50%) scale(1.12); opacity: 0; }
}
.animate-ping-slow {
    animation: ping-slow 2s cubic-bezier(0, 0, 0.2, 1) infinite;
}
@keyframes spin-slow {
    to { transform: rotate(360deg); }
}
.animate-spin-slow {
    animation: spin-slow 8s linear infinite;
}
@keyframes spin-very-slow {
    to { transform: rotate(360deg); }
}
.animate-spin-very-slow {
    animation: spin-very-slow 30s linear infinite;
}
@keyframes pulse-subtle {
    0%, 100% { opacity: 0.5; transform: scale(1); }
    50% { opacity: 0.8; transform: scale(1.03); }
}
.animate-pulse-subtle {
    animation: pulse-subtle 3s ease-in-out infinite;
}
@keyframes scan-sweep-1 {
    0% { top: -2%; opacity: 0; }
    10% { opacity: 1; }
    90% { opacity: 1; }
    100% { top: 102%; opacity: 0; }
}
@keyframes scan-sweep-2 {
    0% { top: 102%; opacity: 0; }
    10% { opacity: 0.7; }
    90% { opacity: 0.7; }
    100% { top: -2%; opacity: 0; }
}
.scan-line-1 {
    animation: scan-sweep-1 3s ease-in-out infinite;
}
.scan-line-2 {
    animation: scan-sweep-2 4s ease-in-out infinite;
    animation-delay: 1.5s;
}
@keyframes nudge-left {
    0%, 100% { transform: translateX(0); }
    50% { transform: translateX(-5px); }
}
@keyframes nudge-right {
    0%, 100% { transform: translateX(0); }
    50% { transform: translateX(5px); }
}
@keyframes nudge-up {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
}
@keyframes nudge-down {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(5px); }
}
.animate-nudge-left { animation: nudge-left 1s ease-in-out infinite; }
.animate-nudge-right { animation: nudge-right 1s ease-in-out infinite; }
.animate-nudge-up { animation: nudge-up 1s ease-in-out infinite; }
.animate-nudge-down { animation: nudge-down 1s ease-in-out infinite; }
@keyframes bounce-dot {
    0%, 80%, 100% { transform: translateY(0); }
    40% { transform: translateY(-6px); }
}
.animate-bounce-dot {
    animation: bounce-dot 1.2s ease-in-out infinite;
}
.warn-enter-active { transition: all 0.3s ease; }
.warn-leave-active { transition: all 0.2s ease; }
.warn-enter-from, .warn-leave-to { opacity: 0; transform: translateY(-4px); }
</style>
