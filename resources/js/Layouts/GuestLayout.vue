<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="min-h-screen bg-surface-950 flex flex-col items-center justify-center px-6 py-12">
        <div class="mb-8">
            <Link href="/" class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-lg bg-brand flex items-center justify-center">
                    <svg class="w-4.5 h-4.5 text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
                <span class="text-lg font-bold text-surface-100">ConnectFlow</span>
            </Link>
        </div>

        <div class="w-full max-w-md card p-8">
            <slot />
        </div>
    </div>
</template>
