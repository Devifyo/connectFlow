<script setup>
import { Link } from '@inertiajs/vue3';
import PitchFlowLogo from '@/Components/PitchFlowLogo.vue';
</script>

<template>
    <div class="min-h-screen bg-surface-950 flex flex-col items-center justify-center px-6 py-12">
        <div class="mb-8">
            <Link href="/" class="flex items-center gap-2">
                <PitchFlowLogo size="w-9 h-9" />
                <span class="text-lg font-bold text-surface-100">PitchFlow</span>
            </Link>
        </div>

        <div class="w-full max-w-md card p-8">
            <slot />
        </div>
    </div>
</template>
