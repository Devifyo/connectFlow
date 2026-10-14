<script setup>
import { ref, computed } from 'vue';
import { useMessaging } from '@/composables/useMessaging';
import { vProtectedSrc } from '@/directives/protectedSrc';

const selected = defineModel({ type: Array, default: () => [] });
const props = defineProps({
    excludeIds: { type: Array, default: () => [] },
});

const { searchUsers, fetchAllTeammates } = useMessaging();

const query = ref('');
const results = ref([]);
const loadingAll = ref(false);
let debounceTimer = null;

// Typing "everyone" (or "@everyone") offers a one-click select-all.
const wantsEveryone = computed(() => {
    const q = query.value.trim().toLowerCase().replace(/^@/, '');
    return q.length >= 3 && 'everyone'.startsWith(q);
});

async function selectEveryone() {
    if (loadingAll.value) return;
    loadingAll.value = true;
    try {
        const all = (await fetchAllTeammates()).filter(u => !props.excludeIds.includes(u.id));
        const chosen = new Set(selected.value.map(u => u.id));
        selected.value = [...selected.value, ...all.filter(u => !chosen.has(u.id))];
        query.value = '';
        results.value = [];
    } finally {
        loadingAll.value = false;
    }
}

function onKeydown(e) {
    if (e.key === 'Enter' && wantsEveryone.value) {
        e.preventDefault();
        selectEveryone();
    }
}

function onSearch() {
    clearTimeout(debounceTimer);
    if (!query.value || wantsEveryone.value) { results.value = []; return; }
    debounceTimer = setTimeout(async () => {
        const users = await searchUsers(query.value);
        results.value = users.filter(u => !props.excludeIds.includes(u.id));
    }, 250);
}

function isSelected(user) {
    return selected.value.some(u => u.id === user.id);
}

function toggle(user) {
    selected.value = isSelected(user)
        ? selected.value.filter(u => u.id !== user.id)
        : [...selected.value, user];
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}
</script>

<template>
    <div>
        <div v-if="selected.length > 1" class="flex items-center justify-between mb-1.5">
            <span class="text-[10px] text-surface-500">{{ selected.length }} selected</span>
            <button type="button" @click="selected = []" class="text-[10px] text-surface-400 hover:text-red-400">Clear all</button>
        </div>
        <div v-if="selected.length" class="flex flex-wrap gap-1.5 mb-2 max-h-28 overflow-y-auto scrollbar-thin">
            <span v-for="u in selected" :key="u.id"
                class="inline-flex items-center gap-1 pl-2.5 pr-1 py-1 rounded-full bg-brand/15 text-brand text-[11px] font-medium">
                {{ u.name }}
                <button type="button" @click="toggle(u)" class="w-4 h-4 rounded-full hover:bg-brand/20 flex items-center justify-center">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </span>
        </div>
        <input v-model="query" @input="onSearch" @keydown="onKeydown" type="text" placeholder="Search team members, or type “everyone”..."
            class="w-full px-3 py-2 text-xs bg-surface-800/60 border border-surface-700/40 rounded-xl text-surface-100 placeholder-surface-500 focus:outline-none focus:border-surface-600" />
        <button v-if="wantsEveryone" type="button" @click="selectEveryone" :disabled="loadingAll"
            class="mt-1.5 w-full px-3 py-2.5 flex items-center gap-2.5 rounded-xl border border-brand/30 bg-brand/10 hover:bg-brand/20 transition-colors text-left">
            <span class="w-7 h-7 rounded-full bg-brand/20 text-brand flex items-center justify-center text-sm flex-shrink-0">👥</span>
            <span class="min-w-0 flex-1">
                <span class="block text-xs font-semibold text-brand">{{ loadingAll ? 'Selecting…' : 'Select everyone' }}</span>
                <span class="block text-[10px] text-surface-400">Add all active team members</span>
            </span>
            <span class="text-[10px] text-surface-500 flex-shrink-0">Enter ↵</span>
        </button>
        <div v-if="results.length" class="mt-1.5 max-h-48 overflow-y-auto scrollbar-thin rounded-xl border border-surface-700/40 bg-surface-800/40">
            <button v-for="user in results" :key="user.id" type="button" @click="toggle(user)"
                class="w-full px-3 py-2 flex items-center gap-2.5 hover:bg-surface-700/50 transition-colors text-left">
                <div class="w-7 h-7 rounded-full bg-surface-700 flex items-center justify-center flex-shrink-0 overflow-hidden">
                    <img v-if="user.profile_picture_url" v-protected-src="user.profile_picture_url" class="w-full h-full object-cover" draggable="false" @contextmenu.prevent />
                    <span v-else class="text-[10px] font-bold text-surface-300">{{ initials(user.name) }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-xs font-medium text-surface-100 truncate">{{ user.name }}</div>
                    <div class="text-[10px] text-surface-500 truncate">{{ user.positions?.map(p => p.title).join(', ') || user.designation || '' }}</div>
                </div>
                <span class="w-4 h-4 rounded border flex items-center justify-center flex-shrink-0"
                    :class="isSelected(user) ? 'bg-brand border-brand' : 'border-surface-600'">
                    <svg v-if="isSelected(user)" class="w-3 h-3 text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                </span>
            </button>
        </div>
    </div>
</template>
