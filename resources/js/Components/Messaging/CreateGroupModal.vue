<script setup>
import { ref, computed } from 'vue';
import { useMessaging } from '@/composables/useMessaging';
import MemberPicker from './MemberPicker.vue';

const emit = defineEmits(['close']);
const { createGroup } = useMessaging();

const title = ref('');
const members = ref([]);
const saving = ref(false);
const error = ref('');

const canCreate = computed(() => title.value.trim() && members.value.length > 0 && !saving.value);

async function submit() {
    if (!canCreate.value) return;
    saving.value = true;
    error.value = '';
    try {
        await createGroup(title.value.trim(), members.value.map(m => m.id));
        emit('close');
    } catch (e) {
        error.value = e.response?.data?.error || e.response?.data?.message || 'Could not create the group.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="absolute inset-0 z-30 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="emit('close')"></div>
        <form @submit.prevent="submit" class="relative w-full max-w-sm bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl">
            <div class="px-5 py-4 border-b border-surface-800/50 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-surface-100">New group chat</h3>
                <button type="button" @click="emit('close')" class="text-surface-400 hover:text-surface-100">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-5 space-y-4">
                <div>
                    <label class="block text-[11px] font-medium text-surface-400 mb-1.5">Room title</label>
                    <input v-model="title" maxlength="100" type="text" placeholder="e.g. Leadership sync" autofocus
                        class="w-full px-3 py-2 text-sm bg-surface-800/60 border border-surface-700/40 rounded-xl text-surface-100 placeholder-surface-500 focus:outline-none focus:border-surface-600" />
                </div>
                <div>
                    <label class="block text-[11px] font-medium text-surface-400 mb-1.5">Members</label>
                    <MemberPicker v-model="members" />
                </div>
                <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
            </div>
            <div class="px-5 py-3 border-t border-surface-800/50 flex justify-end gap-2">
                <button type="button" @click="emit('close')" class="px-3 py-1.5 text-xs rounded-lg text-surface-300 hover:bg-surface-800">Cancel</button>
                <button type="submit" :disabled="!canCreate"
                    class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-brand text-surface-950 hover:bg-brand-light disabled:opacity-40 disabled:cursor-not-allowed">
                    {{ saving ? 'Creating…' : 'Create group' }}
                </button>
            </div>
        </form>
    </div>
</template>
