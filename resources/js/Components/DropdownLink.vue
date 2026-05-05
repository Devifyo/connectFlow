<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
    method: { type: String, default: 'get' },
    as: { type: String, default: 'a' },
});
</script>

<template>
    <Link
        :href="href"
        :method="method"
        :as="as"
        class="block w-full px-4 py-2.5 text-start text-sm text-surface-300 hover:text-surface-100 hover:bg-surface-700/50 transition-colors first:rounded-t-lg last:rounded-b-lg"
    >
        <slot />
    </Link>
</template>
