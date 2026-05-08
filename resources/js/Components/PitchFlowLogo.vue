<script setup>
import { computed } from 'vue';

const props = defineProps({
    size: { type: String, default: 'md' },
});

const uid = computed(() => `pf-${Math.random().toString(36).slice(2, 8)}`);

const sizeClass = computed(() => ({
    xs: 'w-3 h-3',
    sm: 'w-4 h-4',
    md: 'w-[18px] h-[18px]',
    lg: 'w-6 h-6',
    xl: 'w-16 h-16',
}[props.size] || props.size));
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 48 48"
        fill="none"
        :class="sizeClass"
    >
        <defs>
            <linearGradient :id="`pf-${uid}`" x1="0" y1="0" x2="48" y2="48" gradientUnits="userSpaceOnUse">
                <stop stop-color="#b0e12e"/>
                <stop offset="1" stop-color="#7b933b"/>
            </linearGradient>
        </defs>
        <rect width="48" height="48" rx="12" :fill="`url(#pf-${uid})`"/>
        <g transform="translate(9, 9) scale(1.25)">
            <path d="M3.478 2.404a.75.75 0 0 0-.926.941l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.404z" fill="#ffffff"/>
        </g>
    </svg>
</template>
