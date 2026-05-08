<script setup>
import { ref, watch, nextTick, computed } from 'vue';
import { useMessaging } from '@/composables/useMessaging';
import { usePage } from '@inertiajs/vue3';

const emit = defineEmits(['back']);

const { activeConversation, messages, sendMessage, loadingMessages, nextCursor, fetchMessages } = useMessaging();

const page = usePage();
const currentUserId = computed(() => page.props.auth.user?.id);

const body = ref('');
const sending = ref(false);
const messagesContainer = ref(null);

watch(() => messages.value.length, () => {
    nextTick(() => scrollToBottom());
});

watch(activeConversation, () => {
    body.value = '';
    nextTick(() => scrollToBottom());
});

function scrollToBottom() {
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
}

async function handleSend() {
    if (!body.value.trim() || sending.value || !activeConversation.value) return;
    sending.value = true;
    try {
        await sendMessage(activeConversation.value.id, body.value.trim());
        body.value = '';
    } finally {
        sending.value = false;
    }
}

function handleKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSend();
    }
}

function loadMore() {
    if (nextCursor.value && !loadingMessages.value && activeConversation.value) {
        const container = messagesContainer.value;
        const prevHeight = container.scrollHeight;
        fetchMessages(activeConversation.value.id, false).then(() => {
            nextTick(() => {
                container.scrollTop = container.scrollHeight - prevHeight;
            });
        });
    }
}

function handleScroll() {
    if (messagesContainer.value?.scrollTop === 0) {
        loadMore();
    }
}

function formatTime(dateStr) {
    return new Date(dateStr).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatDate(dateStr) {
    const d = new Date(dateStr);
    const today = new Date();
    if (d.toDateString() === today.toDateString()) return 'Today';
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

function shouldShowDate(index) {
    if (index === 0) return true;
    const curr = new Date(messages.value[index].created_at).toDateString();
    const prev = new Date(messages.value[index - 1].created_at).toDateString();
    return curr !== prev;
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}
</script>

<template>
    <!-- Empty state -->
    <div v-if="!activeConversation" class="flex-1 flex items-center justify-center">
        <div class="text-center px-6">
            <div class="w-12 h-12 rounded-2xl bg-surface-800 flex items-center justify-center mx-auto mb-3">
                <svg class="w-6 h-6 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 2.25c5.385 0 9.75 4.365 9.75 9.75s-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12 6.615 2.25 12 2.25z"/>
                </svg>
            </div>
            <p class="text-sm text-surface-400">Select a conversation</p>
            <p class="text-xs text-surface-600 mt-1">or search for a team member</p>
        </div>
    </div>

    <!-- Active chat -->
    <template v-else>
        <!-- Header -->
        <div class="h-14 px-4 flex items-center gap-3 border-b border-surface-800/50 flex-shrink-0">
            <button @click="emit('back')" class="sm:hidden text-surface-400 hover:text-surface-100 transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                </svg>
            </button>
            <div class="w-8 h-8 rounded-full bg-surface-700 flex items-center justify-center flex-shrink-0">
                <span class="text-[10px] font-bold text-surface-300">{{ initials(activeConversation.user?.name) }}</span>
            </div>
            <div class="min-w-0">
                <div class="text-sm font-semibold text-surface-100 truncate">{{ activeConversation.user?.name || 'Unknown' }}</div>
            </div>
        </div>

        <!-- Messages -->
        <div
            ref="messagesContainer"
            @scroll="handleScroll"
            class="flex-1 overflow-y-auto px-4 py-4 space-y-1"
        >
            <div v-if="loadingMessages && messages.length > 0" class="text-center py-2">
                <span class="text-[10px] text-surface-500">Loading older messages...</span>
            </div>

            <template v-for="(msg, i) in messages" :key="msg.id">
                <!-- Date separator -->
                <div v-if="shouldShowDate(i)" class="flex items-center gap-3 py-3">
                    <div class="flex-1 h-px bg-surface-800/50"></div>
                    <span class="text-[10px] text-surface-500 font-medium">{{ formatDate(msg.created_at) }}</span>
                    <div class="flex-1 h-px bg-surface-800/50"></div>
                </div>

                <!-- Message bubble -->
                <div
                    class="flex"
                    :class="msg.user_id === currentUserId ? 'justify-end' : 'justify-start'"
                >
                    <div
                        class="max-w-[75%] px-3 py-2 rounded-2xl text-sm leading-relaxed"
                        :class="msg.user_id === currentUserId
                            ? 'bg-brand text-surface-950 rounded-br-md'
                            : 'bg-surface-800 text-surface-100 rounded-bl-md'"
                    >
                        <p class="whitespace-pre-wrap break-words">{{ msg.body }}</p>
                        <p
                            class="text-[9px] mt-1"
                            :class="msg.user_id === currentUserId ? 'text-surface-950/50' : 'text-surface-500'"
                        >
                            {{ formatTime(msg.created_at) }}
                        </p>
                    </div>
                </div>
            </template>

            <div v-if="messages.length === 0 && !loadingMessages" class="text-center py-12">
                <p class="text-xs text-surface-500">No messages yet. Say hello!</p>
            </div>
        </div>

        <!-- Input -->
        <div class="px-4 py-3 border-t border-surface-800/50 flex-shrink-0">
            <div class="flex items-end gap-2">
                <textarea
                    v-model="body"
                    @keydown="handleKeydown"
                    placeholder="Type a message..."
                    rows="1"
                    class="flex-1 px-3 py-2 text-sm bg-surface-800 border border-surface-700/50 rounded-xl text-surface-100 placeholder-surface-500 focus:outline-none focus:border-brand/50 focus:ring-1 focus:ring-brand/20 resize-none max-h-32"
                ></textarea>
                <button
                    @click="handleSend"
                    :disabled="!body.trim() || sending"
                    class="w-9 h-9 rounded-xl bg-brand hover:bg-brand-light disabled:opacity-30 disabled:cursor-not-allowed flex items-center justify-center transition-colors flex-shrink-0"
                >
                    <svg class="w-4 h-4 text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/>
                    </svg>
                </button>
            </div>
        </div>
    </template>
</template>
