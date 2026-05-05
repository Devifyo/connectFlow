<script setup>
import { computed } from 'vue';

const emit = defineEmits(['update:checked']);

const props = defineProps({
    checked: { type: [Array, Boolean], required: true },
    value: { default: null },
});

const proxyChecked = computed({
    get() { return props.checked; },
    set(val) { emit('update:checked', val); },
});
</script>

<template>
    <input
        type="checkbox"
        :value="value"
        v-model="proxyChecked"
        class="w-4 h-4 rounded bg-surface-800 border-surface-600 text-brand focus:ring-brand/30 focus:ring-offset-0 transition-colors"
    />
</template>
