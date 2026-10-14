<script setup>
import { ref, watch, nextTick, computed, onMounted, onUnmounted } from 'vue';
import { useMessaging } from '@/composables/useMessaging';
import { usePage } from '@inertiajs/vue3';
import { vProtectedSrc } from '@/directives/protectedSrc';
import { vLazySrc } from '@/directives/lazySrc';
import GroupInfoPanel from './GroupInfoPanel.vue';
import axios from 'axios';

const emit = defineEmits(['back']);

const {
    activeConversation, messages, sendMessage, startConversation, loadingMessages,
    nextCursor, fetchMessages, fetchConversations, typingState, otherLastRead, emitTyping,
    unreadFromIndex, totalUnread, onIncoming,
    groupDetails, pinnedMessages, toggleReaction, togglePin, onSeen,
} = useMessaging();

const QUICK_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏', '🔥', '🎉'];

const page = usePage();
const currentUserId = computed(() => page.props.auth.user?.id);

const body = ref('');
const sending = ref(false);
const messagesContainer = ref(null);
const showUnreadBanner = ref(false);
const fadingUnread = ref(false);
let unreadDismissTimer = null;
const fileInputRef = ref(null);
const selectedFiles = ref([]);
const filePreviews = ref([]);
const isDragging = ref(false);
const lightboxUrl = ref(null);
const hasNewBelow = ref(false);
const textareaRef = ref(null);
const showInfo = ref(false);
const showPinnedList = ref(false);
const reactionPickerFor = ref(null);
const highlightedId = ref(null);
const actionError = ref('');

const isGroup = computed(() => !!activeConversation.value?.is_group);
const myMembership = computed(() => groupDetails.value?.members?.find(m => m.id === currentUserId.value));
const canSendHere = computed(() => {
    if (!isGroup.value) return true;
    if (myMembership.value) return myMembership.value.role === 'owner' || myMembership.value.can_send;
    return activeConversation.value?.can_send !== false;
});
const memberNameById = computed(() => {
    const map = {};
    (groupDetails.value?.members || []).forEach(m => { map[m.id] = m.name; });
    return map;
});

// --- @mentions (groups only) ---
const mentionQuery = ref(null);
const mentionStart = ref(-1);
const mentionIndex = ref(0);
const pendingMentions = ref([]);

const mentionCandidates = computed(() => {
    if (mentionQuery.value === null || !isGroup.value) return [];
    const q = mentionQuery.value.toLowerCase();
    const members = (groupDetails.value?.members || [])
        .filter(m => m.id !== currentUserId.value && m.name.toLowerCase().includes(q))
        .slice(0, 6);
    const everyone = 'everyone'.startsWith(q) ? [{ id: 'everyone', name: 'everyone', isEveryone: true }] : [];
    return [...everyone, ...members];
});

function updateMentionState() {
    const el = textareaRef.value;
    if (!el || !isGroup.value) { mentionQuery.value = null; return; }
    const upto = body.value.slice(0, el.selectionStart);
    const match = upto.match(/(^|\s)@([^\s@]*)$/);
    if (match) {
        mentionQuery.value = match[2];
        mentionStart.value = upto.length - match[2].length - 1;
        mentionIndex.value = 0;
    } else {
        mentionQuery.value = null;
    }
}

function pickMention(member) {
    const el = textareaRef.value;
    const before = body.value.slice(0, mentionStart.value);
    const after = body.value.slice(el ? el.selectionStart : body.value.length);
    const insert = '@' + member.name + ' ';
    body.value = before + insert + after;
    if (!member.isEveryone && !pendingMentions.value.some(m => m.id === member.id)) {
        pendingMentions.value.push({ id: member.id, name: member.name });
    }
    mentionQuery.value = null;
    nextTick(() => {
        const pos = before.length + insert.length;
        el?.focus();
        el?.setSelectionRange(pos, pos);
    });
}

// --- reactions / pins ---
async function react(msg, emoji) {
    reactionPickerFor.value = null;
    try { await toggleReaction(msg, emoji); } catch {}
}

function hasReacted(reaction) {
    return reaction.users?.some(u => u.id === currentUserId.value);
}

function reactionTitle(reaction) {
    return (reaction.users || []).map(u => u.id === currentUserId.value ? 'You' : u.name).join(', ');
}

async function pin(msg) {
    actionError.value = '';
    try {
        await togglePin(msg);
    } catch (e) {
        actionError.value = e.response?.data?.error || 'Could not update pin.';
        setTimeout(() => { actionError.value = ''; }, 3000);
    }
}

// --- "Seen by" (group owner only) ---
const isGroupOwner = computed(() => isGroup.value && !!groupDetails.value?.is_owner);
const seenInfo = ref(null); // { message, loading, seen, not_seen }

async function openSeenBy(msg) {
    seenInfo.value = { message: msg, loading: true, seen: [], not_seen: [] };
    try {
        const { data } = await axios.get(`/api/messages/${msg.id}/seen`);
        if (seenInfo.value?.message.id === msg.id) seenInfo.value = { message: msg, loading: false, ...data };
    } catch (e) {
        seenInfo.value = null;
        actionError.value = e.response?.data?.error || 'Could not load who has seen this.';
        setTimeout(() => { actionError.value = ''; }, 3000);
    }
}

// --- Who reacted with what ---
const reactionsView = ref(null); // { messageId, tab }

const reactionsViewMessage = computed(() => {
    if (!reactionsView.value) return null;
    return messages.value.find(m => m.id === reactionsView.value.messageId) || null;
});

const reactionsViewRows = computed(() => {
    const msg = reactionsViewMessage.value;
    if (!msg) return [];
    const tab = reactionsView.value.tab;
    return (msg.reactions || [])
        .filter(r => tab === 'all' || r.emoji === tab)
        .flatMap(r => (r.users || []).map(u => ({ ...u, emoji: r.emoji })))
        .sort((a, b) => (a.id === currentUserId.value ? -1 : b.id === currentUserId.value ? 1 : a.name.localeCompare(b.name)));
});

function totalReactions(msg) {
    return (msg.reactions || []).reduce((n, r) => n + r.count, 0);
}

function openReactions(msg, tab = 'all') {
    reactionsView.value = { messageId: msg.id, tab };
}

function jumpToMessage(id) {
    showPinnedList.value = false;
    const el = document.getElementById('msg-' + id);
    if (!el) return;
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    highlightedId.value = id;
    setTimeout(() => { if (highlightedId.value === id) highlightedId.value = null; }, 1800);
}

function preview(msg) {
    if (msg.body) return msg.body.length > 90 ? msg.body.slice(0, 90) + '…' : msg.body;
    return msg.attachments?.length ? '📎 Attachment' : '';
}

function mentionsMe(msg) {
    return isGroup.value && msg.user_id !== currentUserId.value && (msg.mentions || []).includes(currentUserId.value);
}

const originalTitle = typeof document !== 'undefined' ? document.title : '';
let titleFlashInterval = null;

function startTitleFlash(count) {
    stopTitleFlash();
    let show = true;
    titleFlashInterval = setInterval(() => {
        document.title = show ? `(${count}) New message` : originalTitle;
        show = !show;
    }, 1000);
}

function stopTitleFlash() {
    if (titleFlashInterval) {
        clearInterval(titleFlashInterval);
        titleFlashInterval = null;
        document.title = originalTitle;
    }
}

let removeIncomingHook = null;
let removeSeenHook = null;

function closeReactionPicker() { reactionPickerFor.value = null; }

onMounted(() => {
    document.addEventListener('visibilitychange', handleVisibility);
    document.addEventListener('click', closeReactionPicker);
    // Refresh an open "Message info" when someone reads it.
    removeSeenHook = onSeen((data) => {
        const msg = seenInfo.value?.message;
        if (msg && !seenInfo.value.loading && (data.message_ids || []).includes(msg.id)) openSeenBy(msg);
    });
    removeIncomingHook = onIncoming(() => {
        startTitleFlash(totalUnread.value || 1);
    });
});

onUnmounted(() => {
    stopTitleFlash();
    document.removeEventListener('visibilitychange', handleVisibility);
    document.removeEventListener('click', closeReactionPicker);
    if (removeIncomingHook) removeIncomingHook();
    if (removeSeenHook) removeSeenHook();
});

function handleVisibility() {
    if (!document.hidden) stopTitleFlash();
}

const ALLOWED_TYPES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    'video/mp4', 'video/webm', 'video/quicktime',
    'application/pdf',
    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain', 'text/csv', 'application/zip',
];
const MAX_FILE_SIZE = 50 * 1024 * 1024;
const MAX_FILES = 9;

const canSend = computed(() => (body.value.trim() || selectedFiles.value.length > 0) && !sending.value);

const unreadDividerIndex = computed(() => {
    if (unreadFromIndex.value <= 0) return -1;
    const idx = messages.value.length - unreadFromIndex.value;
    return idx >= 0 ? idx : 0;
});

watch(() => messages.value.length, (newLen, oldLen) => {
    if (newLen > oldLen) {
        if (unreadFromIndex.value > 0 && oldLen === 0) {
            showUnreadBanner.value = true;
            fadingUnread.value = false;
            nextTick(() => {
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        scrollToUnread();
                    });
                });
            });
        } else if (oldLen === 0 || isNearBottom()) {
            nextTick(() => scrollToBottom());
        } else if (oldLen > 0) {
            hasNewBelow.value = true;
        }
    }
});

watch(typingState, (val) => {
    if (val && isNearBottom()) nextTick(() => scrollToBottom());
});

watch(() => activeConversation.value?.id, () => {
    showInfo.value = false;
    seenInfo.value = null;
    reactionsView.value = null;
    showPinnedList.value = false;
    reactionPickerFor.value = null;
    pendingMentions.value = [];
    mentionQuery.value = null;
    body.value = '';
    selectedFiles.value = [];
    filePreviews.value = [];
    showUnreadBanner.value = false;
    fadingUnread.value = false;
    hasNewBelow.value = false;
    clearTimeout(unreadDismissTimer);
});

function isNearBottom() {
    if (!messagesContainer.value) return true;
    const { scrollTop, scrollHeight, clientHeight } = messagesContainer.value;
    return scrollHeight - scrollTop - clientHeight < 150;
}

function scrollToBottom() {
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
}

function scrollToUnread() {
    const container = messagesContainer.value;
    if (!container) return;

    const idx = unreadDividerIndex.value;
    const msg = messages.value[idx];
    if (!msg) { scrollToBottom(); return; }

    const el = document.getElementById('msg-' + msg.id);
    if (!el) { scrollToBottom(); return; }

    el.scrollIntoView({ block: 'center', behavior: 'instant' });

    unreadDismissTimer = setTimeout(() => {
        fadingUnread.value = true;
        setTimeout(() => {
            showUnreadBanner.value = false;
            fadingUnread.value = false;
            unreadFromIndex.value = -1;
        }, 500);
    }, 4000);
}

function dismissUnread() {
    if (!showUnreadBanner.value) return;
    clearTimeout(unreadDismissTimer);
    fadingUnread.value = true;
    setTimeout(() => {
        showUnreadBanner.value = false;
        fadingUnread.value = false;
        unreadFromIndex.value = -1;
    }, 500);
}

async function handleSend() {
    if (!canSend.value || !activeConversation.value) return;
    dismissUnread();
    sending.value = true;
    try {
        let convId = activeConversation.value.id;
        if (!convId) {
            convId = await startConversation(activeConversation.value.user.id);
            activeConversation.value = { ...activeConversation.value, id: convId, isDraft: false };
            await fetchConversations();
        }
        const text = body.value.trim();
        const mentionIds = pendingMentions.value.filter(m => text.includes('@' + m.name)).map(m => m.id);
        await sendMessage(convId, text, selectedFiles.value, mentionIds);
        pendingMentions.value = [];
        body.value = '';
        selectedFiles.value = [];
        filePreviews.value = [];
        nextTick(() => scrollToBottom());
    } catch (e) {
        actionError.value = e.response?.data?.error || 'Message could not be sent.';
        setTimeout(() => { actionError.value = ''; }, 4000);
    } finally {
        sending.value = false;
    }
}

function handleKeydown(e) {
    if (mentionCandidates.value.length) {
        if (e.key === 'ArrowDown') { e.preventDefault(); mentionIndex.value = (mentionIndex.value + 1) % mentionCandidates.value.length; return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); mentionIndex.value = (mentionIndex.value - 1 + mentionCandidates.value.length) % mentionCandidates.value.length; return; }
        if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); pickMention(mentionCandidates.value[mentionIndex.value]); return; }
        if (e.key === 'Escape') { e.preventDefault(); mentionQuery.value = null; return; }
    }
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSend();
    }
}

function jumpToBottom() {
    scrollToBottom();
    hasNewBelow.value = false;
    stopTitleFlash();
}

function handleInput() {
    dismissUnread();
    updateMentionState();
    if (activeConversation.value?.id) {
        emitTyping(activeConversation.value.id);
    }
}

function handleFilePick(e) {
    addFiles(Array.from(e.target.files));
    fileInputRef.value.value = '';
}

function addFiles(files) {
    for (const file of files) {
        if (selectedFiles.value.length >= MAX_FILES) break;
        if (!ALLOWED_TYPES.includes(file.type)) continue;
        if (file.size > MAX_FILE_SIZE) continue;
        if (selectedFiles.value.some(f => f.name === file.name && f.size === file.size)) continue;
        selectedFiles.value.push(file);
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (ev) => filePreviews.value.push({ name: file.name, url: ev.target.result, type: 'image' });
            reader.readAsDataURL(file);
        } else if (file.type.startsWith('video/')) {
            filePreviews.value.push({ name: file.name, url: URL.createObjectURL(file), type: 'video' });
        } else {
            filePreviews.value.push({ name: file.name, type: 'document', size: file.size });
        }
    }
}

function removeFile(index) {
    selectedFiles.value.splice(index, 1);
    filePreviews.value.splice(index, 1);
}

function handleDragOver(e) { e.preventDefault(); isDragging.value = true; }
function handleDragLeave() { isDragging.value = false; }
function handleDrop(e) {
    e.preventDefault();
    isDragging.value = false;
    addFiles(Array.from(e.dataTransfer.files));
}

function loadMore() {
    if (nextCursor.value && !loadingMessages.value && activeConversation.value) {
        const container = messagesContainer.value;
        const prevHeight = container.scrollHeight;
        fetchMessages(activeConversation.value.id, false).then(() => {
            nextTick(() => { container.scrollTop = container.scrollHeight - prevHeight; });
        });
    }
}

function handleScroll() {
    if (messagesContainer.value && messagesContainer.value.scrollTop < 80) loadMore();
    if (isNearBottom()) {
        hasNewBelow.value = false;
        if (totalUnread.value === 0) stopTitleFlash();
    }
}

function formatTime(dateStr) {
    return new Date(dateStr).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatFullTime(dateStr) {
    return new Date(dateStr).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
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

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

function fileIcon(mimeType) {
    if (mimeType?.includes('pdf')) return 'PDF';
    if (mimeType?.includes('word') || mimeType?.includes('document')) return 'DOC';
    if (mimeType?.includes('excel') || mimeType?.includes('sheet')) return 'XLS';
    if (mimeType?.includes('presentation') || mimeType?.includes('powerpoint')) return 'PPT';
    if (mimeType?.includes('zip')) return 'ZIP';
    if (mimeType?.includes('csv')) return 'CSV';
    return 'FILE';
}

function shouldShowDate(index) {
    if (index === 0) return true;
    return new Date(messages.value[index].created_at).toDateString() !== new Date(messages.value[index - 1].created_at).toDateString();
}

function isSameSender(index) {
    if (index === 0) return false;
    if (messages.value[index].type === 'system' || messages.value[index - 1].type === 'system') return false;
    return messages.value[index].user_id === messages.value[index - 1].user_id && !shouldShowDate(index);
}

function isLastInGroup(index) {
    if (index === messages.value.length - 1) return true;
    if (messages.value[index + 1]?.type === 'system') return true;
    return messages.value[index].user_id !== messages.value[index + 1]?.user_id || shouldShowDate(index + 1);
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

function linkify(text) {
    if (!text) return '';
    const escaped = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return escaped.replace(
        /https?:\/\/[^\s<]+/g,
        url => `<a href="${url}" target="_blank" rel="noopener noreferrer" class="underline break-all">${url}</a>`
    );
}

function escapeHtml(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function renderBody(msg) {
    let html = linkify(msg.body);
    const ids = msg.mentions || [];
    if (!ids.length) return html;
    const mine = msg.user_id === currentUserId.value;
    if (isGroup.value && /(^|\s)@everyone\b/i.test(msg.body)) {
        const cls = mine ? 'mention mention-on-brand' : 'mention mention-me';
        html = html.replace(/(^|\s)(@everyone)\b/gi, `$1<span class="${cls}">$2</span>`);
    }
    for (const id of ids) {
        const name = memberNameById.value[id];
        if (!name) continue;
        const token = '@' + escapeHtml(name);
        const cls = id === currentUserId.value
            ? 'mention mention-me'
            : (mine ? 'mention mention-on-brand' : 'mention');
        html = html.split(token).join(`<span class="${cls}">${token}</span>`);
    }
    return html;
}

function lastSeenText(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    const diffMin = Math.floor((Date.now() - d.getTime()) / 60000);
    if (diffMin < 1) return 'Last seen just now';
    if (diffMin < 60) return `Last seen ${diffMin}m ago`;
    const diffHr = Math.floor(diffMin / 60);
    if (diffHr < 24) return `Last seen ${diffHr}h ago`;
    return 'Last seen ' + d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function messageStatus(msg) {
    if (msg.user_id !== currentUserId.value) return null;
    if (!otherLastRead.value) return 'sent';
    return new Date(otherLastRead.value).getTime() >= new Date(msg.created_at).getTime() ? 'seen' : 'sent';
}

function seenTooltip(msg) {
    const status = messageStatus(msg);
    if (status === 'seen') return 'Seen ' + formatFullTime(otherLastRead.value);
    if (status === 'sent') return 'Sent ' + formatFullTime(msg.created_at);
    return '';
}

function imageAttachments(msg) {
    return (msg.attachments || []).filter(a => a.type === 'image');
}
function videoAttachments(msg) {
    return (msg.attachments || []).filter(a => a.type === 'video');
}
function docAttachments(msg) {
    return (msg.attachments || []).filter(a => a.type !== 'image' && a.type !== 'video');
}
function hasMedia(msg) {
    return (msg.attachments || []).length > 0;
}

</script>

<template>
    <!-- Empty state -->
    <div v-if="!activeConversation" class="flex-1 flex items-center justify-center bg-surface-950/30">
        <div class="text-center px-8">
            <div class="w-16 h-16 rounded-2xl bg-surface-800/40 flex items-center justify-center mx-auto mb-5">
                <svg class="w-8 h-8 text-surface-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-surface-300">Select a conversation</p>
            <p class="text-xs text-surface-500 mt-1.5">or search for a team member to start chatting</p>
        </div>
    </div>

    <!-- Active chat -->
    <template v-else>
        <!-- Header -->
        <div class="h-16 px-5 sm:pr-12 flex items-center gap-3.5 border-b border-surface-800/50 flex-shrink-0">
            <button @click="emit('back')" class="sm:hidden text-surface-400 hover:text-surface-100 transition-colors -ml-1">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                </svg>
            </button>
            <!-- Group header -->
            <template v-if="isGroup">
                <div class="w-10 h-10 rounded-xl bg-indigo-500/15 text-indigo-300 flex items-center justify-center flex-shrink-0">
                    <span class="text-xs font-bold">{{ initials(activeConversation.title) }}</span>
                </div>
                <button @click="showInfo = true" class="min-w-0 flex-1 text-left">
                    <div class="text-sm font-semibold text-surface-100 truncate">{{ activeConversation.title }}</div>
                    <div class="text-xs mt-0.5 truncate">
                        <span v-if="typingState" class="text-brand font-medium">{{ typingState.split(' ')[0] }} is typing<span class="typing-dots">...</span></span>
                        <span v-else class="text-surface-500">{{ groupDetails?.members?.length || activeConversation.member_count }} members</span>
                    </div>
                </button>
                <button @click="showInfo = true" title="Group info"
                    class="w-8 h-8 rounded-lg flex items-center justify-center text-surface-400 hover:text-surface-100 hover:bg-surface-800 transition-colors flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                </button>
            </template>
            <template v-else>
            <div class="relative">
                <div class="w-10 h-10 rounded-full bg-surface-700 flex items-center justify-center overflow-hidden">
                    <img v-if="activeConversation.user?.profile_picture_url" v-protected-src="activeConversation.user.profile_picture_url" class="w-full h-full object-cover" draggable="false" @contextmenu.prevent />
                    <span v-else class="text-xs font-bold text-surface-300">{{ initials(activeConversation.user?.name) }}</span>
                </div>
                <span v-if="!activeConversation.isDraft && activeConversation.user?.presence_status === 'online'"
                    class="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-emerald-400 ring-2 ring-surface-900"></span>
                <span v-else-if="!activeConversation.isDraft && activeConversation.user?.presence_status === 'away'"
                    class="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-amber-400 ring-2 ring-surface-900"></span>
                <span v-else-if="!activeConversation.isDraft"
                    class="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-surface-500 ring-2 ring-surface-900"></span>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-semibold text-surface-100 truncate">{{ activeConversation.user?.name || 'Unknown' }}</span>
                    <span v-if="activeConversation.user?.positions?.length" class="text-[10px] text-surface-400 truncate hidden sm:inline">{{ activeConversation.user.positions.map(p => p.title).join(', ') }}</span>
                </div>
                <div v-if="!activeConversation.isDraft" class="text-xs mt-0.5">
                    <template v-if="typingState">
                        <span class="text-brand font-medium">typing<span class="typing-dots">...</span></span>
                    </template>
                    <template v-else-if="activeConversation.user?.presence_status === 'online'">
                        <span class="text-emerald-400">Online</span>
                    </template>
                    <template v-else-if="activeConversation.user?.presence_status === 'away'">
                        <span class="text-amber-400">Away</span>
                    </template>
                    <template v-else>
                        <span class="text-surface-500">{{ lastSeenText(activeConversation.user?.last_active_at) || 'Offline' }}</span>
                    </template>
                </div>
                <div v-else class="text-xs mt-0.5 text-surface-500">New conversation</div>
            </div>
            </template>
        </div>

        <!-- Pinned messages bar -->
        <div v-if="pinnedMessages.length" class="relative flex-shrink-0 border-b border-surface-800/50 bg-surface-900/80">
            <button @click="showPinnedList = !showPinnedList" class="w-full px-5 py-2 flex items-center gap-2.5 text-left hover:bg-surface-800/30 transition-colors">
                <span class="text-sm">📌</span>
                <div class="min-w-0 flex-1">
                    <div class="text-[10px] font-semibold text-brand">Pinned{{ pinnedMessages.length > 1 ? ` · ${pinnedMessages.length}` : '' }}</div>
                    <div class="text-xs text-surface-300 truncate">{{ preview(pinnedMessages[0]) }}</div>
                </div>
                <svg class="w-4 h-4 text-surface-500 transition-transform" :class="showPinnedList ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
            </button>
            <div v-if="showPinnedList" class="absolute left-0 right-0 top-full z-20 max-h-72 overflow-y-auto scrollbar-thin bg-surface-900 border-b border-surface-800/60 shadow-2xl">
                <div v-for="pm in pinnedMessages" :key="pm.id" class="px-5 py-2.5 flex items-start gap-3 border-t border-surface-800/40 hover:bg-surface-800/30">
                    <button @click="jumpToMessage(pm.id)" class="min-w-0 flex-1 text-left">
                        <div class="text-[11px] font-medium text-surface-300">{{ pm.sender?.name }} <span class="text-surface-500 font-normal">· {{ formatFullTime(pm.created_at) }}</span></div>
                        <div class="text-xs text-surface-200 whitespace-pre-wrap break-words">{{ preview(pm) }}</div>
                        <div v-if="pm.pinned_by" class="text-[10px] text-surface-500 mt-0.5">Pinned by {{ pm.pinned_by.id === currentUserId ? 'you' : pm.pinned_by.name }}</div>
                    </button>
                    <button v-if="canSendHere" @click="pin(pm)" class="text-[11px] text-surface-400 hover:text-red-400 flex-shrink-0">Unpin</button>
                </div>
            </div>
        </div>

        <!-- Messages area -->
        <div
            ref="messagesContainer"
            @scroll="handleScroll"
            @dragover="handleDragOver"
            @dragleave="handleDragLeave"
            @drop="handleDrop"
            class="flex-1 overflow-y-auto px-5 py-4 scrollbar-thin bg-surface-950/30 relative"
        >
            <!-- Drag overlay -->
            <div v-if="isDragging" class="absolute inset-0 z-20 bg-brand/10 border-2 border-dashed border-brand rounded-xl flex items-center justify-center backdrop-blur-sm">
                <div class="text-center">
                    <svg class="w-10 h-10 text-brand mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                    </svg>
                    <p class="text-sm font-medium text-brand">Drop files here</p>
                </div>
            </div>

            <!-- Load more spinner -->
            <div v-if="loadingMessages && messages.length > 0" class="text-center py-3">
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-800/50">
                    <div class="w-3.5 h-3.5 border-2 border-surface-600 border-t-surface-300 rounded-full animate-spin"></div>
                    <span class="text-[11px] text-surface-500">Loading older messages</span>
                </div>
            </div>

            <template v-for="(msg, i) in messages" :key="msg.id">
                <!-- Date separator -->
                <div v-if="shouldShowDate(i)" class="flex items-center gap-4 py-4">
                    <div class="flex-1 h-px bg-surface-800/30"></div>
                    <span class="text-[11px] text-surface-500 font-medium bg-surface-900/80 px-3 py-0.5 rounded-full">{{ formatDate(msg.created_at) }}</span>
                    <div class="flex-1 h-px bg-surface-800/30"></div>
                </div>

                <!-- Unread divider -->
                <div v-if="i === unreadDividerIndex && showUnreadBanner" id="unread-divider"
                    class="flex items-center gap-3 py-3 transition-all duration-500"
                    :class="fadingUnread ? 'opacity-0 -translate-y-2' : 'opacity-100 translate-y-0'">
                    <div class="flex-1 h-px bg-brand/40"></div>
                    <span class="text-[11px] font-semibold text-brand bg-brand/10 px-3 py-1 rounded-full whitespace-nowrap">Unread Messages</span>
                    <div class="flex-1 h-px bg-brand/40"></div>
                </div>

                <!-- System line (group events) -->
                <div v-if="msg.type === 'system'" :id="'msg-' + msg.id" class="flex justify-center my-3">
                    <span class="text-[11px] text-surface-400 bg-surface-800/50 px-3 py-1 rounded-full text-center">{{ msg.body }}</span>
                </div>

                <!-- Message row -->
                <div v-else
                    :id="'msg-' + msg.id"
                    class="flex items-end gap-2.5 rounded-xl transition-colors duration-700"
                    :class="[msg.user_id === currentUserId ? 'justify-end' : 'justify-start', isSameSender(i) ? 'mt-1' : 'mt-4', highlightedId === msg.id ? 'bg-brand/10' : '']"
                >
                    <!-- Other user avatar -->
                    <div v-if="msg.user_id !== currentUserId" class="w-7 flex-shrink-0 mb-0.5">
                        <div v-if="isLastInGroup(i)" class="w-7 h-7 rounded-full bg-surface-700 flex items-center justify-center overflow-hidden">
                            <img v-if="msg.sender?.profile_picture_url" v-protected-src="msg.sender.profile_picture_url" class="w-full h-full object-cover" draggable="false" @contextmenu.prevent />
                            <span v-else class="text-[9px] font-bold text-surface-400">{{ initials(msg.sender?.name) }}</span>
                        </div>
                    </div>

                    <!-- Bubble -->
                    <div class="max-w-[75%]">
                        <p v-if="msg.user_id !== currentUserId && !isSameSender(i)"
                            class="text-[11px] text-surface-500 font-medium mb-1 ml-1">
                            {{ msg.sender?.name }}
                        </p>

                        <div class="relative group" :class="msg.user_id === currentUserId ? 'ml-auto' : ''">
                            <div v-if="msg.pinned_at" class="text-[10px] text-brand/80 mb-0.5 flex items-center gap-1" :class="msg.user_id === currentUserId ? 'justify-end mr-1' : 'ml-1'">
                                📌 Pinned
                            </div>

                            <!-- Hover actions: react + pin -->
                            <div class="absolute top-1 z-10 flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity"
                                :class="[msg.user_id === currentUserId ? 'right-full mr-1.5' : 'left-full ml-1.5', reactionPickerFor === msg.id ? '!opacity-100' : '']">
                                <button @click.stop="reactionPickerFor = reactionPickerFor === msg.id ? null : msg.id" title="React"
                                    class="w-7 h-7 rounded-full bg-surface-800 hover:bg-surface-700 flex items-center justify-center text-surface-300 shadow">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15.182 15.182a4.5 4.5 0 01-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75zm-.375 0h.008v.015h-.008V9.75zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75zm-.375 0h.008v.015h-.008V9.75z"/></svg>
                                </button>
                                <button v-if="isGroupOwner" @click.stop="openSeenBy(msg)" title="Seen by"
                                    class="w-7 h-7 rounded-full bg-surface-800 hover:bg-surface-700 flex items-center justify-center text-surface-300 shadow">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                </button>
                                <button v-if="canSendHere" @click.stop="pin(msg)" :title="msg.pinned_at ? 'Unpin' : 'Pin'"
                                    class="w-7 h-7 rounded-full bg-surface-800 hover:bg-surface-700 flex items-center justify-center text-[13px] shadow"
                                    :class="msg.pinned_at ? 'ring-1 ring-brand/60' : 'grayscale opacity-80'">📌</button>
                                <div v-if="reactionPickerFor === msg.id"
                                    class="absolute bottom-full mb-1.5 flex gap-0.5 p-1 rounded-full bg-surface-800 border border-surface-700/50 shadow-xl"
                                    :class="msg.user_id === currentUserId ? 'right-0' : 'left-0'">
                                    <button v-for="emoji in QUICK_REACTIONS" :key="emoji" @click.stop="react(msg, emoji)"
                                        class="w-7 h-7 rounded-full hover:bg-surface-700 text-base leading-none transition-transform hover:scale-125">{{ emoji }}</button>
                                </div>
                            </div>

                            <!-- Single connected card for the whole message -->
                            <div class="rounded-2xl overflow-hidden"
                                :class="[
                                    mentionsMe(msg) ? 'ring-2 ring-amber-400/60' : '',
                                    isLastInGroup(i)
                                        ? (msg.user_id === currentUserId ? 'rounded-br-sm' : 'rounded-bl-sm')
                                        : '',
                                ]"
                                :style="{ maxWidth: hasMedia(msg) ? '280px' : undefined }"
                            >
                                <!-- Text section -->
                                <div v-if="msg.body"
                                    class="px-4 py-2.5 text-sm leading-relaxed"
                                    :class="msg.user_id === currentUserId
                                        ? 'bg-brand text-surface-950'
                                        : 'bg-surface-800 text-surface-100'"
                                >
                                    <p class="whitespace-pre-wrap break-words" v-html="renderBody(msg)"></p>
                                    <!-- Inline time for text-only messages -->
                                    <div v-if="!hasMedia(msg)" class="flex items-center justify-end gap-1.5 mt-1 -mb-0.5">
                                        <span class="text-[10px] leading-none" :class="msg.user_id === currentUserId ? 'text-surface-950/50' : 'text-surface-500'">{{ formatTime(msg.created_at) }}</span>
                                        <div v-if="msg.user_id === currentUserId" class="status-icon" :title="seenTooltip(msg)">
                                            <svg v-if="messageStatus(msg) === 'seen'" class="w-4 h-4 text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 13l4 4L11 12.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M8.5 13l4 4L21 8"/></svg>
                                            <svg v-else class="w-4 h-4" :class="msg.user_id === currentUserId ? 'text-surface-950/50' : 'text-surface-500'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        </div>
                                    </div>
                                </div>

                                <!-- Images section (connected to text above) -->
                                <div v-if="imageAttachments(msg).length" class="relative bg-surface-800">
                                    <!-- Single image -->
                                    <div v-if="imageAttachments(msg).length === 1"
                                        class="cursor-pointer relative overflow-hidden aspect-[4/3]"
                                        @click="lightboxUrl = imageAttachments(msg)[0].url">
                                        <img v-lazy-src="imageAttachments(msg)[0].url" :alt="imageAttachments(msg)[0].original_name"
                                            class="w-full h-full object-cover hover:brightness-90 transition-opacity duration-300"
                                            decoding="async" />
                                    </div>
                                    <!-- Multiple images grid -->
                                    <div v-else class="grid gap-0.5"
                                        :class="imageAttachments(msg).length === 2 ? 'grid-cols-2' : 'grid-cols-3'">
                                        <div v-for="(att, idx) in imageAttachments(msg)" :key="att.id"
                                            class="relative bg-surface-700 cursor-pointer overflow-hidden"
                                            :class="imageAttachments(msg).length === 3 && idx === 0 ? 'col-span-3 aspect-[16/9]' : 'aspect-square'"
                                            @click="lightboxUrl = att.url">
                                            <img v-lazy-src="att.url" :alt="att.original_name"
                                                class="w-full h-full object-cover hover:brightness-90 transition-opacity duration-300"
                                                decoding="async" />
                                        </div>
                                    </div>
                                    <!-- Time overlaid on image -->
                                    <div class="absolute bottom-1.5 right-2 flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-black/50 backdrop-blur-sm">
                                        <span class="text-[10px] text-white/80">{{ formatTime(msg.created_at) }}</span>
                                        <div v-if="msg.user_id === currentUserId" class="status-icon">
                                            <svg v-if="messageStatus(msg) === 'seen'" class="w-3.5 h-3.5 text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 13l4 4L11 12.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M8.5 13l4 4L21 8"/></svg>
                                            <svg v-else class="w-3.5 h-3.5 text-white/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        </div>
                                    </div>
                                </div>

                                <!-- Videos section (connected) -->
                                <div v-for="att in videoAttachments(msg)" :key="att.id" class="bg-surface-800">
                                    <video :src="att.url" controls class="w-full" preload="none"></video>
                                </div>

                                <!-- Documents section (connected) -->
                                <div v-if="docAttachments(msg).length" class="bg-surface-800">
                                    <a v-for="att in docAttachments(msg)" :key="att.id"
                                        :href="att.url" target="_blank"
                                        class="flex items-center gap-3 p-3 transition-colors hover:bg-surface-700/50 border-t border-surface-700/30">
                                        <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 text-[10px] font-bold bg-surface-700 text-surface-300">
                                            {{ fileIcon(att.mime_type) }}
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-xs font-medium text-surface-200 truncate">{{ att.original_name }}</div>
                                            <div class="text-[10px] text-surface-500">{{ formatFileSize(att.size) }}</div>
                                        </div>
                                        <svg class="w-4 h-4 text-surface-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                                        </svg>
                                    </a>
                                    <!-- Time under documents if no images -->
                                    <div v-if="!imageAttachments(msg).length" class="flex items-center justify-end gap-1.5 px-3 pb-2 pt-0.5">
                                        <span class="text-[10px] text-surface-500">{{ formatTime(msg.created_at) }}</span>
                                        <div v-if="msg.user_id === currentUserId" class="status-icon" :title="seenTooltip(msg)">
                                            <svg v-if="messageStatus(msg) === 'seen'" class="w-3.5 h-3.5 text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 13l4 4L11 12.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M8.5 13l4 4L21 8"/></svg>
                                            <svg v-else class="w-3.5 h-3.5 text-surface-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Reactions -->
                            <div v-if="msg.reactions?.length" class="flex flex-wrap gap-1 mt-1" :class="msg.user_id === currentUserId ? 'justify-end' : ''">
                                <button v-for="r in msg.reactions" :key="r.emoji" @click="react(msg, r.emoji)" :title="reactionTitle(r)"
                                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-xs border transition-colors"
                                    :class="hasReacted(r) ? 'bg-brand/15 border-brand/40 text-brand' : 'bg-surface-800/80 border-surface-700/50 text-surface-300 hover:border-surface-600'">
                                    <span class="leading-none">{{ r.emoji }}</span>
                                    <span class="text-[10px] font-semibold">{{ r.count }}</span>
                                </button>
                                <button @click="openReactions(msg)" title="See who reacted"
                                    class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] text-surface-400 hover:text-surface-100 hover:bg-surface-800/80 transition-colors">
                                    {{ totalReactions(msg) === 1 ? '1 reaction' : `${totalReactions(msg)} reactions` }}
                                </button>
                            </div>

                            <!-- Owner: seen counter on every message -->
                            <button v-if="isGroupOwner && msg.audience_count > 0" @click="openSeenBy(msg)"
                                class="mt-1 w-fit flex items-center gap-1 text-[10px] transition-colors hover:underline"
                                :class="[
                                    msg.user_id === currentUserId ? 'ml-auto mr-1' : 'ml-1',
                                    msg.seen_count >= msg.audience_count ? 'text-sky-400' : 'text-surface-500 hover:text-surface-300',
                                ]">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                <span v-if="msg.seen_count >= msg.audience_count">Seen by all</span>
                                <span v-else-if="!msg.seen_count">Not seen yet · 0/{{ msg.audience_count }}</span>
                                <span v-else>Seen by {{ msg.seen_count }}/{{ msg.audience_count }}</span>
                            </button>

                            <!-- Hover tooltip -->
                            <div v-if="!isGroup && msg.user_id === currentUserId && messageStatus(msg)"
                                class="opacity-0 group-hover:opacity-100 pointer-events-none transition-opacity duration-200
                                       absolute -top-8 right-0 px-2.5 py-1 rounded-lg bg-surface-700 text-[10px] text-surface-300 whitespace-nowrap shadow-lg z-10">
                                {{ seenTooltip(msg) }}
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Typing indicator -->
            <div v-if="typingState" class="flex items-end gap-2.5 mt-4">
                <div class="w-7 h-7 rounded-full bg-surface-700 flex items-center justify-center flex-shrink-0 overflow-hidden">
                    <img v-if="!isGroup && activeConversation.user?.profile_picture_url" v-protected-src="activeConversation.user.profile_picture_url" class="w-full h-full object-cover" draggable="false" @contextmenu.prevent />
                    <span v-else class="text-[9px] font-bold text-surface-400">{{ initials(typingState) }}</span>
                </div>
                <div class="bg-surface-800 rounded-2xl rounded-bl-sm px-4 py-3">
                    <div class="flex gap-1.5 items-center h-4">
                        <span class="typing-dot"></span>
                        <span class="typing-dot" style="animation-delay: 0.2s"></span>
                        <span class="typing-dot" style="animation-delay: 0.4s"></span>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="messages.length === 0 && !loadingMessages" class="flex items-center justify-center h-full">
                <div class="text-center">
                    <p class="text-sm text-surface-400">No messages yet</p>
                    <p class="text-xs text-surface-600 mt-1">Send a message to start the conversation</p>
                </div>
            </div>

            <!-- New message indicator -->
            <transition
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="opacity-0 translate-y-4"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 translate-y-4"
            >
                <button v-if="hasNewBelow" @click="jumpToBottom"
                    class="absolute bottom-3 left-1/2 -translate-x-1/2 z-10 flex items-center gap-1.5 px-4 py-2 rounded-full bg-brand text-surface-950 text-xs font-semibold shadow-lg hover:bg-brand-light transition-colors active:scale-95">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5L12 21m0 0l-7.5-7.5M12 21V3"/>
                    </svg>
                    New message
                </button>
            </transition>
        </div>

        <!-- File previews strip -->
        <div v-if="filePreviews.length" class="px-4 pt-3 pb-1 border-t border-surface-800/50 flex-shrink-0">
            <div class="flex gap-2 overflow-x-auto scrollbar-thin pb-1">
                <div v-for="(preview, idx) in filePreviews" :key="idx"
                    class="relative flex-shrink-0 group/file">
                    <!-- Image preview -->
                    <div v-if="preview.type === 'image'" class="w-16 h-16 rounded-lg overflow-hidden bg-surface-800">
                        <img :src="preview.url" class="w-full h-full object-cover" />
                    </div>
                    <!-- Video preview -->
                    <div v-else-if="preview.type === 'video'" class="w-16 h-16 rounded-lg bg-surface-800 flex items-center justify-center">
                        <svg class="w-6 h-6 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z"/>
                        </svg>
                    </div>
                    <!-- Document preview -->
                    <div v-else class="h-16 px-3 rounded-lg bg-surface-800 flex items-center gap-2 max-w-[140px]">
                        <div class="w-8 h-8 rounded bg-surface-700 flex items-center justify-center text-[8px] font-bold text-surface-400 flex-shrink-0">
                            {{ fileIcon(preview.name) }}
                        </div>
                        <div class="min-w-0">
                            <div class="text-[10px] text-surface-300 truncate">{{ preview.name }}</div>
                            <div class="text-[9px] text-surface-500">{{ formatFileSize(preview.size) }}</div>
                        </div>
                    </div>
                    <!-- Remove button -->
                    <button @click="removeFile(idx)"
                        class="absolute top-0 right-0 w-5 h-5 rounded-full bg-red-500 text-white flex items-center justify-center opacity-0 group-hover/file:opacity-100 transition-opacity shadow-lg z-10">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Input area -->
        <div v-if="actionError" class="px-4 pt-2 text-[11px] text-red-400 flex-shrink-0">{{ actionError }}</div>
        <div v-if="!canSendHere" class="px-4 py-3.5 border-t border-surface-800/50 flex-shrink-0 text-center text-xs text-surface-500">
            🔒 Only members allowed by the group owner can send messages here.
        </div>
        <div v-else class="px-4 py-3 border-t border-surface-800/50 flex-shrink-0 relative">
            <!-- Mention suggestions -->
            <div v-if="mentionCandidates.length" class="absolute left-14 right-16 bottom-full mb-1 z-20 bg-surface-800 border border-surface-700/50 rounded-xl shadow-xl overflow-hidden">
                <button v-for="(m, idx) in mentionCandidates" :key="m.id" @mousedown.prevent="pickMention(m)"
                    class="w-full px-3 py-2 flex items-center gap-2 text-left text-xs transition-colors"
                    :class="idx === mentionIndex ? 'bg-surface-700/70 text-surface-100' : 'text-surface-300 hover:bg-surface-700/40'">
                    <template v-if="m.isEveryone">
                        <span class="w-6 h-6 rounded-full bg-brand/20 text-brand flex items-center justify-center text-[11px]">👥</span>
                        <span><span class="font-semibold">@everyone</span> <span class="text-surface-500">· notify all members</span></span>
                    </template>
                    <template v-else>
                        <span class="w-6 h-6 rounded-full bg-surface-700 flex items-center justify-center text-[9px] font-bold text-surface-300">{{ initials(m.name) }}</span>
                        {{ m.name }}
                    </template>
                </button>
            </div>
            <div class="flex items-end gap-2">
                <!-- Attach button -->
                <button @click="fileInputRef?.click()"
                    class="w-10 h-10 rounded-xl flex items-center justify-center text-surface-400 hover:text-surface-200 hover:bg-surface-800/60 transition-colors flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                    </svg>
                </button>
                <input ref="fileInputRef" type="file" multiple @change="handleFilePick" class="hidden"
                    accept="image/*,video/*,.svg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip" />

                <textarea
                    ref="textareaRef"
                    v-model="body"
                    @keydown="handleKeydown"
                    @input="handleInput"
                    @click="updateMentionState"
                    @blur="mentionQuery = null"
                    :placeholder="isGroup ? 'Message the group — type @ to mention' : 'Type a message...'"
                    rows="1"
                    class="flex-1 px-4 py-2.5 text-sm bg-surface-800/60 border border-surface-700/30 rounded-2xl text-surface-100 placeholder-surface-500 focus:outline-none focus:border-surface-600 focus:bg-surface-800/80 resize-none max-h-32 scrollbar-thin transition-colors"
                ></textarea>

                <button
                    @click="handleSend"
                    :disabled="!canSend"
                    class="w-10 h-10 rounded-xl bg-brand hover:bg-brand-light disabled:opacity-30 disabled:cursor-not-allowed flex items-center justify-center transition-all duration-200 active:scale-95 flex-shrink-0"
                >
                    <svg class="w-5 h-5 text-surface-950" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M3.478 2.404a.75.75 0 0 0-.926.941l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.404Z"/>
                    </svg>
                </button>
            </div>
        </div>
    </template>

    <GroupInfoPanel v-if="activeConversation && isGroup && showInfo" @close="showInfo = false" />

    <!-- Who reacted -->
    <div v-if="reactionsView && reactionsViewMessage" class="absolute inset-0 z-30 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="reactionsView = null"></div>
        <div class="relative w-full max-w-sm max-h-[80%] flex flex-col bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl">
            <div class="px-5 pt-4 pb-3 border-b border-surface-800/50">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-surface-100">Reactions</h3>
                    <button @click="reactionsView = null" class="text-surface-400 hover:text-surface-100">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="flex gap-1 mt-3 overflow-x-auto scrollbar-thin">
                    <button @click="reactionsView.tab = 'all'"
                        class="px-2.5 py-1 rounded-full text-xs flex-shrink-0 transition-colors"
                        :class="reactionsView.tab === 'all' ? 'bg-brand/15 text-brand font-semibold' : 'text-surface-400 hover:bg-surface-800'">
                        All {{ totalReactions(reactionsViewMessage) }}
                    </button>
                    <button v-for="r in reactionsViewMessage.reactions" :key="r.emoji" @click="reactionsView.tab = r.emoji"
                        class="px-2.5 py-1 rounded-full text-xs flex-shrink-0 transition-colors"
                        :class="reactionsView.tab === r.emoji ? 'bg-brand/15 text-brand font-semibold' : 'text-surface-400 hover:bg-surface-800'">
                        {{ r.emoji }} {{ r.count }}
                    </button>
                </div>
            </div>
            <div class="overflow-y-auto scrollbar-thin px-5 py-3">
                <p v-if="!reactionsViewRows.length" class="text-xs text-surface-500 py-4 text-center">No reactions</p>
                <div v-for="row in reactionsViewRows" :key="row.id + row.emoji" class="flex items-center gap-2.5 py-1.5">
                    <div class="w-7 h-7 rounded-full bg-surface-700 flex items-center justify-center flex-shrink-0">
                        <span class="text-[9px] font-bold text-surface-300">{{ initials(row.name) }}</span>
                    </div>
                    <span class="text-xs text-surface-100 flex-1 truncate">
                        {{ row.id === currentUserId ? 'You' : row.name }}
                    </span>
                    <button v-if="row.id === currentUserId" @click="react(reactionsViewMessage, row.emoji)"
                        class="text-[10px] text-surface-500 hover:text-red-400">Remove</button>
                    <span class="text-lg leading-none">{{ row.emoji }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Seen by (group owner) -->
    <div v-if="seenInfo" class="absolute inset-0 z-30 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="seenInfo = null"></div>
        <div class="relative w-full max-w-sm max-h-[80%] flex flex-col bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl">
            <div class="px-5 py-4 border-b border-surface-800/50 flex items-start gap-3">
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-semibold text-surface-100">Message info</h3>
                    <p class="text-[11px] text-surface-400 mt-1 truncate">{{ seenInfo.message.sender?.name }}: {{ preview(seenInfo.message) }}</p>
                    <p class="text-[10px] text-surface-500 mt-0.5">Sent {{ formatFullTime(seenInfo.message.created_at) }}</p>
                </div>
                <button @click="seenInfo = null" class="text-surface-400 hover:text-surface-100">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div v-if="seenInfo.loading" class="py-10 flex justify-center">
                <div class="w-5 h-5 border-2 border-surface-600 border-t-surface-300 rounded-full animate-spin"></div>
            </div>
            <div v-else class="overflow-y-auto scrollbar-thin p-4 space-y-4">
                <div>
                    <p class="text-[10px] uppercase tracking-wide text-emerald-400 mb-1.5">Seen by {{ seenInfo.seen.length }}</p>
                    <p v-if="!seenInfo.seen.length" class="text-xs text-surface-500">Nobody yet</p>
                    <div v-for="u in seenInfo.seen" :key="u.id" class="flex items-center gap-2.5 py-1.5">
                        <div class="w-7 h-7 rounded-full bg-surface-700 flex items-center justify-center overflow-hidden flex-shrink-0">
                            <img v-if="u.profile_picture_url" v-protected-src="u.profile_picture_url" class="w-full h-full object-cover" draggable="false" @contextmenu.prevent />
                            <span v-else class="text-[9px] font-bold text-surface-300">{{ initials(u.name) }}</span>
                        </div>
                        <span class="text-xs text-surface-100 flex-1 truncate">{{ u.name }}</span>
                        <span class="text-[10px] text-surface-400 flex-shrink-0">{{ u.seen_at ? formatFullTime(u.seen_at) : 'Seen' }}</span>
                    </div>
                </div>
                <div v-if="seenInfo.not_seen.length">
                    <p class="text-[10px] uppercase tracking-wide text-surface-500 mb-1.5">Not seen · {{ seenInfo.not_seen.length }}</p>
                    <div v-for="u in seenInfo.not_seen" :key="u.id" class="flex items-center gap-2.5 py-1.5 opacity-70">
                        <div class="w-7 h-7 rounded-full bg-surface-700 flex items-center justify-center overflow-hidden flex-shrink-0">
                            <img v-if="u.profile_picture_url" v-protected-src="u.profile_picture_url" class="w-full h-full object-cover" draggable="false" @contextmenu.prevent />
                            <span v-else class="text-[9px] font-bold text-surface-300">{{ initials(u.name) }}</span>
                        </div>
                        <span class="text-xs text-surface-300 flex-1 truncate">{{ u.name }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Image lightbox -->
    <Teleport to="body">
        <div v-if="lightboxUrl" class="fixed inset-0 z-[100] bg-black/90 flex items-center justify-center" @click="lightboxUrl = null">
            <button class="absolute top-4 right-4 w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-colors">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
            <img :src="lightboxUrl" class="max-w-[90vw] max-h-[90vh] rounded-lg shadow-2xl" @click.stop />
        </div>
    </Teleport>
</template>

<style scoped>
.typing-dots {
    display: inline-block;
    animation: typingDots 1.4s infinite steps(4);
    width: 1em;
    text-align: left;
    overflow: hidden;
    vertical-align: bottom;
}

@keyframes typingDots {
    0% { width: 0; }
    25% { width: 0.33em; }
    50% { width: 0.66em; }
    75% { width: 1em; }
}

.typing-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #64748b;
    animation: typingBounce 1.2s ease-in-out infinite;
}

@keyframes typingBounce {
    0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
    30% { transform: translateY(-6px); opacity: 1; }
}

:deep(.mention) {
    font-weight: 600;
    color: #818cf8;
}
:deep(.mention-on-brand) {
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
}
:deep(.mention-me) {
    color: #fbbf24;
    background: rgba(251, 191, 36, 0.12);
    border-radius: 4px;
    padding: 0 2px;
}

.status-icon {
    display: inline-flex;
    flex-shrink: 0;
    cursor: default;
}

</style>
