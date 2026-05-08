<script setup>
import { ref } from 'vue';
import { useMessaging } from '@/composables/useMessaging';

const {
    conversations, activeConversation,
    searchUsers, startConversation,
    setActiveConversation, fetchConversations,
} = useMessaging();

const query = ref('');
const searchResults = ref([]);
const searching = ref(false);

let debounceTimer = null;

function onSearch() {
    clearTimeout(debounceTimer);
    if (!query.value || query.value.length < 1) {
        searchResults.value = [];
        return;
    }
    debounceTimer = setTimeout(async () => {
        searching.value = true;
        searchResults.value = await searchUsers(query.value);
        searching.value = false;
    }, 300);
}

function selectUser(user) {
    query.value = '';
    searchResults.value = [];
    const existing = conversations.value.find(c => c.user?.id === user.id);
    if (existing) {
        setActiveConversation(existing);
    } else {
        setActiveConversation({ id: null, user, isDraft: true });
    }
}

function selectConversation(conv) {
    setActiveConversation(conv);
}

function timeAgo(dateStr) {
    if (!dateStr) return '';
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'now';
    if (mins < 60) return `${mins}m`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h`;
    const days = Math.floor(hrs / 24);
    return `${days}d`;
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}
</script>

<template>
    <div class="flex-1 flex flex-col min-h-0">
        <!-- Search -->
        <div class="p-3 relative">
            <div class="relative">
                <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                </svg>
                <input
                    v-model="query"
                    @input="onSearch"
                    type="text"
                    placeholder="Search team members..."
                    class="w-full pl-8 pr-3 py-2 text-xs bg-surface-800/60 border border-surface-700/40 rounded-xl text-surface-100 placeholder-surface-500 focus:outline-none focus:border-surface-600 focus:ring-1 focus:ring-surface-600/30"
                />
            </div>
            <!-- Search Results Dropdown -->
            <div v-if="searchResults.length" class="absolute left-3 right-3 top-full mt-1 bg-surface-800 border border-surface-700/50 rounded-xl shadow-xl z-20 max-h-48 overflow-y-auto">
                <button
                    v-for="user in searchResults"
                    :key="user.id"
                    @click="selectUser(user)"
                    class="w-full px-3 py-2.5 flex items-center gap-2.5 hover:bg-surface-700/50 transition-colors text-left first:rounded-t-xl last:rounded-b-xl"
                >
                    <div class="w-7 h-7 rounded-full bg-brand/15 flex items-center justify-center flex-shrink-0">
                        <span class="text-[10px] font-bold text-brand">{{ initials(user.name) }}</span>
                    </div>
                    <div class="min-w-0">
                        <div class="text-xs font-medium text-surface-100 truncate">{{ user.name }}</div>
                        <div v-if="user.designation" class="text-[10px] text-surface-500 truncate">{{ user.designation }}</div>
                    </div>
                </button>
            </div>
        </div>

        <!-- Conversations -->
        <div class="flex-1 overflow-y-auto scrollbar-thin">
            <div v-if="conversations.length === 0" class="px-4 py-10 text-center">
                <svg class="w-8 h-8 text-surface-600 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/>
                </svg>
                <p class="text-xs text-surface-500">No conversations yet</p>
                <p class="text-[10px] text-surface-600 mt-1">Search above to start chatting</p>
            </div>

            <button
                v-for="conv in conversations"
                :key="conv.id"
                @click="selectConversation(conv)"
                class="w-full px-3 py-2.5 flex items-center gap-2.5 transition-colors text-left border-l-2"
                :class="activeConversation?.id === conv.id
                    ? 'bg-surface-800/70 border-l-brand'
                    : 'hover:bg-surface-800/30 border-l-transparent'"
            >
                <div class="relative flex-shrink-0">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center"
                        :class="conv.unread ? 'bg-brand/15' : 'bg-surface-700'">
                        <span class="text-[10px] font-bold"
                            :class="conv.unread ? 'text-brand' : 'text-surface-400'">
                            {{ initials(conv.user?.name) }}
                        </span>
                    </div>
                    <span v-if="conv.user?.presence_status === 'online'"
                        class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-400 ring-2 ring-surface-900"></span>
                    <span v-else-if="conv.user?.presence_status === 'away'"
                        class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-amber-400 ring-2 ring-surface-900"></span>
                    <span v-else
                        class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-surface-500 ring-2 ring-surface-900"></span>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs truncate" :class="conv.unread ? 'font-bold text-surface-100' : 'font-medium text-surface-200'">
                            {{ conv.user?.name || 'Unknown' }}
                        </span>
                        <span class="text-[9px] flex-shrink-0" :class="conv.unread ? 'text-brand font-semibold' : 'text-surface-500'">
                            {{ timeAgo(conv.updated_at) }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between gap-2 mt-0.5">
                        <p v-if="conv.latest_message" class="text-[11px] truncate" :class="conv.unread ? 'text-surface-300' : 'text-surface-500'">
                            <span v-if="conv.latest_message.is_mine" class="text-surface-400">You: </span>{{ conv.latest_message.body }}
                        </p>
                        <span v-if="conv.unread" class="min-w-[18px] h-[18px] rounded-full bg-brand flex items-center justify-center px-1 flex-shrink-0">
                            <span class="text-[9px] font-bold text-surface-950 leading-none">{{ conv.unread > 9 ? '9+' : conv.unread }}</span>
                        </span>
                    </div>
                </div>
            </button>
        </div>
    </div>
</template>
