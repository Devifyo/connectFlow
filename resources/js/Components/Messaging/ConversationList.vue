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

async function selectUser(user) {
    const conversationId = await startConversation(user.id);
    query.value = '';
    searchResults.value = [];
    await fetchConversations();
    const conv = conversations.value.find(c => c.id === conversationId);
    if (conv) {
        setActiveConversation(conv);
    } else {
        setActiveConversation({ id: conversationId, user });
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
            <input
                v-model="query"
                @input="onSearch"
                type="text"
                placeholder="Search team members..."
                class="w-full px-3 py-2 text-xs bg-surface-800 border border-surface-700/50 rounded-lg text-surface-100 placeholder-surface-500 focus:outline-none focus:border-brand/50 focus:ring-1 focus:ring-brand/20"
            />
            <!-- Search Results Dropdown -->
            <div v-if="searchResults.length" class="absolute left-3 right-3 top-full mt-1 bg-surface-800 border border-surface-700/50 rounded-lg shadow-xl z-20 max-h-48 overflow-y-auto">
                <button
                    v-for="user in searchResults"
                    :key="user.id"
                    @click="selectUser(user)"
                    class="w-full px-3 py-2.5 flex items-center gap-2.5 hover:bg-surface-700/50 transition-colors text-left"
                >
                    <div class="w-7 h-7 rounded-full bg-brand/20 flex items-center justify-center flex-shrink-0">
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
        <div class="flex-1 overflow-y-auto">
            <div v-if="conversations.length === 0" class="px-4 py-8 text-center">
                <p class="text-xs text-surface-500">No conversations yet.</p>
                <p class="text-[10px] text-surface-600 mt-1">Search for a team member to start chatting.</p>
            </div>

            <button
                v-for="conv in conversations"
                :key="conv.id"
                @click="selectConversation(conv)"
                class="w-full px-3 py-3 flex items-center gap-2.5 hover:bg-surface-800/50 transition-colors text-left"
                :class="{ 'bg-surface-800/60': activeConversation?.id === conv.id }"
            >
                <div class="relative flex-shrink-0">
                    <div class="w-8 h-8 rounded-full bg-surface-700 flex items-center justify-center">
                        <span class="text-[10px] font-bold text-surface-300">{{ initials(conv.user?.name) }}</span>
                    </div>
                    <div v-if="conv.unread" class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-brand flex items-center justify-center">
                        <span class="text-[8px] font-bold text-surface-950">{{ conv.unread > 9 ? '9+' : conv.unread }}</span>
                    </div>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-surface-100 truncate">{{ conv.user?.name || 'Unknown' }}</span>
                        <span class="text-[9px] text-surface-500 flex-shrink-0 ml-2">{{ timeAgo(conv.updated_at) }}</span>
                    </div>
                    <p v-if="conv.latest_message" class="text-[10px] text-surface-500 truncate mt-0.5">
                        <span v-if="conv.latest_message.is_mine" class="text-surface-400">You: </span>
                        {{ conv.latest_message.body }}
                    </p>
                </div>
            </button>
        </div>
    </div>
</template>
