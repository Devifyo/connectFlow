<script setup>
import { ref, onBeforeUnmount } from 'vue';

const props = defineProps({
    src: { type: String, required: true },
    autoplay: { type: Boolean, default: false },
});

// status: 'loading' | 'ready' | 'audio-only' | 'error'
const status = ref('loading');
const videoEl = ref(null);
let stallTimer = null;
let fixingDuration = false;

// Last-resort net for a file that never fires ANY event (not even 'error').
// Deliberately long: with preload="metadata" the browser may not fire canplay at all
// until playback starts, so readiness is decided by loadedmetadata, not this timer.
function armStallTimer() {
    clearStallTimer();
    stallTimer = setTimeout(() => {
        if (status.value === 'loading') status.value = 'error';
    }, 30000);
}

function clearStallTimer() {
    if (stallTimer) {
        clearTimeout(stallTimer);
        stallTimer = null;
    }
}

function onLoadStart() {
    status.value = 'loading';
    armStallTimer();
}

// Videos recorded with MediaRecorder report duration === Infinity and have no seek
// index, which makes the browser sit in a loading state instead of playing. Seeking
// to the end forces the browser to compute the real duration; then it plays and seeks.
function resolveInfiniteDuration(el) {
    if (fixingDuration) return;
    if (el.duration !== Infinity && !Number.isNaN(el.duration)) return;
    fixingDuration = true;
    const onTimeUpdate = () => {
        el.removeEventListener('timeupdate', onTimeUpdate);
        el.currentTime = 0;
        fixingDuration = false;
    };
    el.addEventListener('timeupdate', onTimeUpdate);
    try { el.currentTime = 1e101; } catch { fixingDuration = false; }
}

function markReady() {
    if (status.value === 'loading') status.value = 'ready';
    clearStallTimer();
}

function onLoadedMetadata() {
    const el = videoEl.value;
    if (!el) return;
    // No video track (e.g. camera never produced frames) => audio-only recording.
    if (el.videoWidth === 0 && el.videoHeight === 0) {
        status.value = 'audio-only';
        clearStallTimer();
        return;
    }
    // Real video dimensions => the file is valid. Show it now; waiting for
    // canplay/loadeddata would hang under preload="metadata" and falsely flag it corrupt.
    markReady();
    resolveInfiniteDuration(el);
}

// Any of these means there's a playable frame — stop showing the spinner.
function onPlayable() {
    if (status.value !== 'audio-only') markReady();
}

function onError() {
    // Once the video has shown as playable, a later hiccup (e.g. the duration-fix seek)
    // must not relabel a good recording as corrupted.
    if (status.value === 'ready' || status.value === 'audio-only') return;
    status.value = 'error';
    clearStallTimer();
}

onBeforeUnmount(clearStallTimer);
</script>

<template>
    <div class="relative w-full">
        <video
            v-show="status !== 'error' && status !== 'audio-only'"
            ref="videoEl"
            :src="src"
            controls
            :autoplay="autoplay"
            preload="metadata"
            class="w-full rounded-lg bg-black"
            @loadstart="onLoadStart"
            @loadedmetadata="onLoadedMetadata"
            @loadeddata="onPlayable"
            @canplay="onPlayable"
            @playing="onPlayable"
            @error="onError"
        ></video>

        <!-- Loading spinner -->
        <div v-if="status === 'loading'" class="absolute inset-0 flex items-center justify-center rounded-lg bg-black/40">
            <svg class="w-6 h-6 animate-spin text-surface-300" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
        </div>

        <!-- Audio-only recording: no video track was captured, but audio can still be played -->
        <div v-if="status === 'audio-only'" class="flex flex-col items-center gap-2 rounded-lg bg-surface-800/60 border border-surface-700/50 py-4 px-4">
            <div class="flex items-center gap-2 text-[11px] text-amber-400">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9l10.5-3m0 6.553v3.75a2.25 2.25 0 01-1.632 2.163l-1.32.377a1.803 1.803 0 11-.99-3.467l2.31-.66a2.25 2.25 0 001.632-2.163zm0 0V2.25L9 5.25v10.303m0 0v3.75a2.25 2.25 0 01-1.632 2.163l-1.32.377a1.803 1.803 0 01-.99-3.467l2.31-.66A2.25 2.25 0 009 15.553z"/></svg>
                <span>No video was recorded — audio only. Press play to listen.</span>
            </div>
            <audio :src="src" controls :autoplay="autoplay" class="w-full"></audio>
        </div>

        <!-- Broken / unplayable recording -->
        <div v-if="status === 'error'" class="flex flex-col items-center justify-center gap-2 rounded-lg bg-surface-800/60 border border-surface-700/50 py-6 px-4 text-center">
            <svg class="w-6 h-6 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
            <p class="text-xs text-surface-300">This recording is corrupted and can't be played.</p>
            <a :href="src" download class="text-[11px] text-primary-400 hover:text-primary-300 underline">Download raw file</a>
        </div>
    </div>
</template>
