<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
    active: { type: Boolean },
});
</script>

<template>
    <Link
        :href="href"
        class="px-3 py-1.5 rounded-lg text-sm font-medium transition-all duration-200"
        :class="active
            ? 'text-surface-100 bg-surface-800'
            : 'text-surface-400 hover:text-surface-200 hover:bg-surface-800/50'
        "
    >
        <slot />
    </Link>
</template>
