<script setup>
import { ref, watch, nextTick, computed } from 'vue';
import { useMessaging } from '@/composables/useMessaging';
import { usePage } from '@inertiajs/vue3';

const emit = defineEmits(['back']);

const {
    activeConversation, messages, sendMessage, startConversation, loadingMessages,
    nextCursor, fetchMessages, fetchConversations, typingState, otherLastRead, emitTyping,
} = useMessaging();

const page = usePage();
const currentUserId = computed(() => page.props.auth.user?.id);

const body = ref('');
const sending = ref(false);
const messagesContainer = ref(null);
const fileInputRef = ref(null);
const selectedFiles = ref([]);
const filePreviews = ref([]);
const isDragging = ref(false);
const lightboxUrl = ref(null);

const ALLOWED_TYPES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    'video/mp4', 'video/webm', 'video/quicktime',
    'application/pdf',
    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain', 'text/csv', 'application/zip',
];
const MAX_FILE_SIZE = 25 * 1024 * 1024;
const MAX_FILES = 10;

const canSend = computed(() => (body.value.trim() || selectedFiles.value.length > 0) && !sending.value);

watch(() => messages.value.length, () => {
    nextTick(() => scrollToBottom());
});

watch(activeConversation, () => {
    body.value = '';
    selectedFiles.value = [];
    filePreviews.value = [];
    nextTick(() => scrollToBottom());
});

function scrollToBottom() {
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
}

async function handleSend() {
    if (!canSend.value || !activeConversation.value) return;
    sending.value = true;
    try {
        let convId = activeConversation.value.id;
        if (!convId) {
            convId = await startConversation(activeConversation.value.user.id);
            activeConversation.value = { ...activeConversation.value, id: convId, isDraft: false };
            await fetchConversations();
        }
        await sendMessage(convId, body.value.trim(), selectedFiles.value);
        body.value = '';
        selectedFiles.value = [];
        filePreviews.value = [];
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

function handleInput() {
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
    if (messagesContainer.value?.scrollTop === 0) loadMore();
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
    return messages.value[index].user_id === messages.value[index - 1].user_id && !shouldShowDate(index);
}

function isLastInGroup(index) {
    if (index === messages.value.length - 1) return true;
    return messages.value[index].user_id !== messages.value[index + 1]?.user_id || shouldShowDate(index + 1);
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
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

function imageRows(images) {
    const count = images.length;
    if (count === 1) return [[images[0]]];
    if (count === 2) return [[images[0], images[1]]];
    if (count === 3) return [[images[0]], [images[1], images[2]]];
    if (count === 4) return [[images[0], images[1]], [images[2], images[3]]];
    const rows = [];
    for (let i = 0; i < count; i += 3) {
        rows.push(images.slice(i, i + 3));
    }
    return rows;
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
        <div class="h-16 px-5 flex items-center gap-3.5 border-b border-surface-800/50 flex-shrink-0">
            <button @click="emit('back')" class="sm:hidden text-surface-400 hover:text-surface-100 transition-colors -ml-1">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                </svg>
            </button>
            <div class="relative">
                <div class="w-10 h-10 rounded-full bg-surface-700 flex items-center justify-center">
                    <span class="text-xs font-bold text-surface-300">{{ initials(activeConversation.user?.name) }}</span>
                </div>
                <span v-if="!activeConversation.isDraft && activeConversation.user?.presence_status === 'online'"
                    class="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-emerald-400 ring-2 ring-surface-900"></span>
                <span v-else-if="!activeConversation.isDraft && activeConversation.user?.presence_status === 'away'"
                    class="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-amber-400 ring-2 ring-surface-900"></span>
                <span v-else-if="!activeConversation.isDraft"
                    class="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-surface-500 ring-2 ring-surface-900"></span>
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-surface-100 truncate">{{ activeConversation.user?.name || 'Unknown' }}</div>
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

                <!-- Message row -->
                <div
                    class="flex items-end gap-2.5"
                    :class="[msg.user_id === currentUserId ? 'justify-end' : 'justify-start', isSameSender(i) ? 'mt-1' : 'mt-4']"
                >
                    <!-- Other user avatar -->
                    <div v-if="msg.user_id !== currentUserId" class="w-7 flex-shrink-0 mb-0.5">
                        <div v-if="isLastInGroup(i)" class="w-7 h-7 rounded-full bg-surface-700 flex items-center justify-center">
                            <span class="text-[9px] font-bold text-surface-400">{{ initials(msg.sender?.name) }}</span>
                        </div>
                    </div>

                    <!-- Bubble -->
                    <div class="max-w-[75%]">
                        <p v-if="msg.user_id !== currentUserId && !isSameSender(i)"
                            class="text-[11px] text-surface-500 font-medium mb-1 ml-1">
                            {{ msg.sender?.name }}
                        </p>

                        <div class="relative group" :class="msg.user_id === currentUserId ? 'ml-auto' : ''">

                            <!-- Image-only message (no bubble, clean collage) -->
                            <template v-if="imageAttachments(msg).length && !msg.body && !videoAttachments(msg).length && !docAttachments(msg).length">
                                <div class="rounded-2xl overflow-hidden" :class="isLastInGroup(i) ? (msg.user_id === currentUserId ? 'rounded-br-sm' : 'rounded-bl-sm') : ''">
                                    <div class="flex flex-col gap-px" :style="{ maxWidth: imageAttachments(msg).length === 1 ? '280px' : '260px' }">
                                        <div v-for="(row, ri) in imageRows(imageAttachments(msg))" :key="ri"
                                            class="flex gap-px"
                                            :style="{ height: imageAttachments(msg).length === 1 ? 'auto' : '120px' }"
                                        >
                                            <div v-for="att in row" :key="att.id"
                                                class="relative overflow-hidden cursor-pointer flex-1 min-w-0 bg-surface-800"
                                                :style="imageAttachments(msg).length === 1 ? { maxHeight: '260px' } : { height: '100%' }"
                                                @click="lightboxUrl = att.url"
                                            >
                                                <div v-if="!att._loaded" class="absolute inset-0 flex items-center justify-center z-[1]">
                                                    <div class="w-5 h-5 border-2 border-surface-500 border-t-transparent rounded-full animate-spin"></div>
                                                </div>
                                                <img :src="att.url" :alt="att.original_name" @load="att._loaded = true"
                                                    class="w-full h-full object-cover hover:brightness-90 transition" loading="lazy" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Time overlaid at bottom-right -->
                                <div class="flex items-center justify-end gap-1.5 mt-1">
                                    <span class="text-[10px] text-surface-500">{{ formatTime(msg.created_at) }}</span>
                                    <div v-if="msg.user_id === currentUserId" class="status-icon" :title="seenTooltip(msg)">
                                        <svg v-if="messageStatus(msg) === 'seen'" class="w-3.5 h-3.5 text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 13l4 4L11 12.5"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.5 13l4 4L21 8"/>
                                        </svg>
                                        <svg v-else class="w-3.5 h-3.5 text-surface-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                        </svg>
                                    </div>
                                </div>
                            </template>

                            <!-- Text message / mixed content (normal bubble) -->
                            <template v-else>
                                <div
                                    class="text-sm leading-relaxed overflow-hidden"
                                    :class="[
                                        msg.user_id === currentUserId
                                            ? 'bg-brand text-surface-950 rounded-2xl'
                                            : 'bg-surface-800 text-surface-100 rounded-2xl',
                                        msg.user_id === currentUserId
                                            ? (isLastInGroup(i) ? 'rounded-br-sm' : '')
                                            : (isLastInGroup(i) ? 'rounded-bl-sm' : ''),
                                    ]"
                                >
                                    <p v-if="msg.body" class="whitespace-pre-wrap break-words px-4 pt-2.5" :class="msg.attachments?.length ? '' : 'pb-0'">{{ msg.body }}</p>

                                    <!-- Inline images in mixed message -->
                                    <div v-if="imageAttachments(msg).length" :class="msg.body ? 'mt-1' : ''" class="flex flex-col gap-px">
                                        <div v-for="(row, ri) in imageRows(imageAttachments(msg))" :key="ri"
                                            class="flex gap-px" style="height: 120px">
                                            <div v-for="att in row" :key="att.id"
                                                class="relative overflow-hidden cursor-pointer flex-1 min-w-0"
                                                @click="lightboxUrl = att.url">
                                                <div v-if="!att._loaded" class="absolute inset-0 flex items-center justify-center bg-surface-800/50 z-[1]">
                                                    <div class="w-5 h-5 border-2 border-surface-500 border-t-transparent rounded-full animate-spin"></div>
                                                </div>
                                                <img :src="att.url" :alt="att.original_name" @load="att._loaded = true"
                                                    class="w-full h-full object-cover hover:brightness-90 transition" loading="lazy" />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Videos -->
                                    <div v-for="att in videoAttachments(msg)" :key="att.id" class="mt-1">
                                        <video :src="att.url" controls class="w-full" preload="metadata"></video>
                                    </div>

                                    <!-- Documents -->
                                    <div v-if="docAttachments(msg).length" class="space-y-1 py-1">
                                        <a v-for="att in docAttachments(msg)" :key="att.id"
                                            :href="att.url" target="_blank"
                                            class="flex items-center gap-3 p-3 mx-2 rounded-xl transition-colors"
                                            :class="msg.user_id === currentUserId ? 'bg-surface-950/15 hover:bg-surface-950/25' : 'bg-surface-700/50 hover:bg-surface-700'">
                                            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 text-[10px] font-bold"
                                                :class="msg.user_id === currentUserId ? 'bg-surface-950/20 text-surface-950' : 'bg-surface-600 text-surface-200'">
                                                {{ fileIcon(att.mime_type) }}
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-xs font-medium truncate">{{ att.original_name }}</div>
                                                <div class="text-[10px] opacity-60">{{ formatFileSize(att.size) }}</div>
                                            </div>
                                            <svg class="w-4 h-4 opacity-50 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                                            </svg>
                                        </a>
                                    </div>

                                    <!-- Time + status -->
                                    <div class="flex items-center justify-end gap-1.5 mt-1 px-4 pb-2">
                                        <span class="text-[10px] leading-none" :class="msg.user_id === currentUserId ? 'text-surface-950/50' : 'text-surface-500'">
                                            {{ formatTime(msg.created_at) }}
                                        </span>
                                        <div v-if="msg.user_id === currentUserId" class="status-icon" :title="seenTooltip(msg)">
                                            <svg v-if="messageStatus(msg) === 'seen'" class="w-4 h-4 text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 13l4 4L11 12.5"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.5 13l4 4L21 8"/>
                                            </svg>
                                            <svg v-else class="w-4 h-4 text-surface-950/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <!-- Hover tooltip -->
                            <div v-if="msg.user_id === currentUserId && messageStatus(msg)"
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
                <div class="w-7 h-7 rounded-full bg-surface-700 flex items-center justify-center flex-shrink-0">
                    <span class="text-[9px] font-bold text-surface-400">{{ initials(typingState) }}</span>
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
        <div class="px-4 py-3 border-t border-surface-800/50 flex-shrink-0">
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
                    v-model="body"
                    @keydown="handleKeydown"
                    @input="handleInput"
                    placeholder="Type a message..."
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

.status-icon {
    display: inline-flex;
    flex-shrink: 0;
    cursor: default;
}

</style>
