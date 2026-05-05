<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
    active: { type: Boolean },
    method: { type: String, default: 'get' },
    as: { type: String, default: 'a' },
});
</script>

<template>
    <Link
        :href="href"
        :method="method"
        :as="as"
        class="block w-full px-4 py-2.5 rounded-lg text-sm font-medium transition-all duration-200"
        :class="active
            ? 'text-surface-100 bg-surface-800 border border-surface-700/50'
            : 'text-surface-400 hover:text-surface-200 hover:bg-surface-800/50'
        "
    >
        <slot />
    </Link>
</template>
