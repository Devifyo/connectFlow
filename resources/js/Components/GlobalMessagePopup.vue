<script setup>
import { ref, watch, computed } from 'vue';
import { useGlobalMessages } from '@/composables/useGlobalMessages';
import { useMessaging } from '@/composables/useMessaging';
import { vProtectedSrc } from '@/directives/protectedSrc';

const { currentPopup, dismiss, toggleReaction, fetchReactions, fetchComments, addComment } = useGlobalMessages();
const { startConversation, openPanel, closePanel, setActiveConversation, fetchConversations, isPanelOpen, conversations } = useMessaging();

const countdown = ref(0);
const popupHidden = ref(false);
const reactions = ref({ reactions: [], user_reactions: [] });
const comments = ref([]);
const commentText = ref('');
const showComments = ref(false);
const availableEmojis = ['👍', '❤️', '🎉', '👀', '🚀', '💯'];
let timer = null;

function calculateReadTime(body) {
    if (!body) return 5;
    const text = body.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    const words = text.split(' ').filter(w => w.length > 0).length;
    // Average reading speed ~200 words/min = ~3.3 words/sec
    // Minimum 5s, maximum 60s
    const seconds = Math.ceil(words / 3.3);
    return Math.max(5, Math.min(seconds, 60));
}

watch(currentPopup, async (msg) => {
    if (msg) {
        countdown.value = calculateReadTime(msg.body);
        showComments.value = false;
        commentText.value = '';
        clearInterval(timer);
        timer = setInterval(() => {
            countdown.value--;
            if (countdown.value <= 0) clearInterval(timer);
        }, 1000);
        const [r, c] = await Promise.all([fetchReactions(msg.id), fetchComments(msg.id)]);
        reactions.value = r;
        comments.value = c;
    } else {
        clearInterval(timer);
        countdown.value = 0;
    }
}, { immediate: true });

watch(isPanelOpen, (open) => {
    if (!open && popupHidden.value) {
        popupHidden.value = false;
    }
});

const showPopup = computed(() => currentPopup.value && !popupHidden.value);

function formatTime(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const renderedBody = computed(() => {
    if (!currentPopup.value?.body) return '';
    let html = currentPopup.value.body;
    html = html.replace(
        /<span[^>]*data-type="mention"[^>]*data-id="(\d+)"[^>]*>/g,
        (match, id) => match.replace('>', ` class="mention-link" data-user-id="${id}">`)
    );
    return html;
});

function onBodyClick(e) {
    const el = e.target.closest('[data-user-id]');
    if (el) {
        const userId = parseInt(el.dataset.userId);
        if (userId) openChatWith(userId);
    }
}

async function openChatWith(userId) {
    popupHidden.value = true;
    try {
        const conversationId = await startConversation(userId);
        await fetchConversations();
        openPanel();
        const conv = conversations.value.find(c => c.id === conversationId);
        if (conv) setActiveConversation(conv);
    } catch {
        popupHidden.value = false;
    }
}

async function handleReaction(emoji) {
    if (!currentPopup.value) return;
    await toggleReaction(currentPopup.value.id, emoji);
    reactions.value = await fetchReactions(currentPopup.value.id);
}

async function submitComment() {
    const body = commentText.value.trim();
    if (!body || !currentPopup.value) return;
    const comment = await addComment(currentPopup.value.id, body);
    if (comment) {
        comments.value.push(comment);
        commentText.value = '';
    }
}

function isUserReacted(emoji) {
    return reactions.value.user_reactions?.includes(emoji);
}

function getReactionCount(emoji) {
    const r = reactions.value.reactions?.find(r => r.emoji === emoji);
    return r ? r.count : 0;
}

function formatCommentTime(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0 translate-y-4 scale-95"
            enter-to-class="opacity-100 translate-y-0 scale-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100 translate-y-0 scale-100"
            leave-to-class="opacity-0 translate-y-4 scale-95"
        >
            <div v-if="showPopup" class="fixed inset-0 z-[9999] flex items-center justify-center p-4 sm:p-6 md:p-10">
                <!-- Backdrop (not clickable) -->
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>

                <!-- Popup card -->
                <div class="relative w-full max-w-lg md:max-w-2xl lg:max-w-3xl max-h-[90vh] flex flex-col bg-surface-900 border rounded-2xl shadow-2xl overflow-hidden"
                     :class="currentPopup.priority === 'urgent' ? 'border-red-500/50' : 'border-surface-700/50'">
                    <!-- Priority indicator -->
                    <div v-if="currentPopup.priority === 'urgent'" class="h-1 bg-gradient-to-r from-red-500 to-orange-500"></div>
                    <div v-else class="h-1 bg-gradient-to-r from-brand to-blue-500"></div>

                    <!-- Header -->
                    <div class="px-6 pt-5 pb-3 flex items-start gap-3 flex-shrink-0">
                        <!-- Sender photo or fallback icon -->
                        <div class="flex-shrink-0 w-10 h-10 rounded-full overflow-hidden bg-brand/20 flex items-center justify-center">
                            <img v-if="currentPopup.sender_picture" v-protected-src="currentPopup.sender_picture" class="w-full h-full object-cover" />
                            <svg v-else class="w-5 h-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 010 3.46" />
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="text-base font-semibold text-surface-100 truncate">{{ currentPopup.title }}</h3>
                                <span v-if="currentPopup.priority === 'urgent'" class="flex-shrink-0 text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded bg-red-500/20 text-red-400">
                                    Urgent
                                </span>
                            </div>
                            <p class="text-xs text-surface-400 mt-0.5">
                                From {{ currentPopup.sender_name }} &middot; {{ formatTime(currentPopup.created_at) }}
                            </p>
                        </div>
                    </div>

                    <!-- Body (scrollable area) -->
                    <div class="px-6 pb-4 flex-1 min-h-0 overflow-y-auto">
                        <div class="prose-popup text-sm text-surface-300 leading-relaxed" @click="onBodyClick" v-html="renderedBody"></div>
                    </div>

                    <!-- Reactions -->
                    <div class="px-6 pb-3 flex-shrink-0">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button v-for="emoji in availableEmojis" :key="emoji"
                                @click="handleReaction(emoji)"
                                class="flex items-center gap-1 px-2 py-1 rounded-full text-xs transition-colors border"
                                :class="isUserReacted(emoji) ? 'bg-brand/20 border-brand/40 text-brand' : 'bg-surface-800 border-surface-700 text-surface-400 hover:border-surface-500'">
                                <span>{{ emoji }}</span>
                                <span v-if="getReactionCount(emoji) > 0" class="text-[10px]">{{ getReactionCount(emoji) }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Comments toggle & section -->
                    <div class="px-6 pb-3 flex-shrink-0">
                        <button @click="showComments = !showComments" class="text-xs text-surface-400 hover:text-surface-200 transition-colors flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            <span>{{ comments.length }} comment{{ comments.length !== 1 ? 's' : '' }}</span>
                            <svg class="w-3 h-3 transition-transform" :class="showComments ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <div v-if="showComments" class="mt-2 space-y-2 max-h-32 overflow-y-auto">
                            <div v-for="c in comments" :key="c.id" class="flex items-start gap-2">
                                <div class="w-5 h-5 rounded-full bg-surface-700 flex items-center justify-center flex-shrink-0 overflow-hidden">
                                    <img v-if="c.user_picture" v-protected-src="c.user_picture" class="w-full h-full object-cover" />
                                    <span v-else class="text-[9px] text-surface-400">{{ (c.user_name || '?')[0] }}</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-1.5">
                                        <span class="text-[11px] font-medium text-surface-200">{{ c.user_name }}</span>
                                        <span class="text-[9px] text-surface-500">{{ formatCommentTime(c.created_at) }}</span>
                                    </div>
                                    <p class="text-xs text-surface-300 break-words">{{ c.body }}</p>
                                </div>
                            </div>
                            <div v-if="comments.length === 0" class="text-xs text-surface-500 italic">No comments yet</div>
                        </div>

                        <!-- Comment input -->
                        <div v-if="showComments" class="mt-2 flex items-center gap-2">
                            <input v-model="commentText" @keyup.enter="submitComment"
                                placeholder="Add a comment..."
                                class="flex-1 bg-surface-800 border border-surface-700 rounded-lg px-3 py-1.5 text-xs text-surface-200 placeholder:text-surface-500 focus:outline-none focus:border-brand/50" />
                            <button @click="submitComment" :disabled="!commentText.trim()"
                                class="px-2.5 py-1.5 text-xs font-medium rounded-lg bg-brand text-surface-950 disabled:opacity-40 disabled:cursor-not-allowed hover:bg-brand/90 transition-colors">
                                Send
                            </button>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="px-6 pb-5 flex items-center justify-between border-t border-surface-700/50 pt-3 flex-shrink-0">
                        <p v-if="countdown > 0" class="text-xs text-surface-500">Please read the message above</p>
                        <div class="ml-auto">
                            <button @click="dismiss(currentPopup.id)"
                                :disabled="countdown > 0"
                                class="px-4 py-2 text-sm font-medium rounded-lg transition-colors"
                                :class="countdown > 0 ? 'bg-surface-700 text-surface-500 cursor-not-allowed' : 'bg-brand text-surface-950 hover:bg-brand/90'">
                                <span v-if="countdown > 0">I've read it ({{ countdown }}s)</span>
                                <span v-else>I've read it</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

