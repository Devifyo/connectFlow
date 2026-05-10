import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

const conversations = ref([]);
const activeConversation = ref(null);
const messages = ref([]);
const totalUnread = ref(0);
const isPanelOpen = ref(false);
const nextCursor = ref(null);
const loadingMessages = ref(false);
const otherLastRead = ref(null);
const typingState = ref(null);
const unreadFromIndex = ref(-1);
const onIncomingCallbacks = [];

let typingTimeout = null;
let sendTypingTimer = null;
let heartbeatInterval = null;
let presenceCheckInterval = null;
let heartbeatStarted = false;
let activityTimeout = null;
let currentStatus = 'online';
let statusChangeThrottle = null;

const ONLINE_EXPIRY = 45000;
const AWAY_EXPIRY = 90000;

export function useMessaging() {
    async function fetchConversations() {
        const { data } = await axios.get('/api/messages/conversations');
        conversations.value = data;
    }

    async function fetchMessages(conversationId, reset = true) {
        if (loadingMessages.value) return;
        loadingMessages.value = true;

        try {
            const params = {};
            if (!reset && nextCursor.value) params.cursor = nextCursor.value;

            const { data } = await axios.get(`/api/messages/conversations/${conversationId}/messages`, { params });

            const incoming = data.data.reverse();
            if (reset) {
                messages.value = incoming;
            } else {
                messages.value = [...incoming, ...messages.value];
            }
            nextCursor.value = data.next_cursor;
            if (data.other_last_read) {
                otherLastRead.value = data.other_last_read;
            }
        } finally {
            loadingMessages.value = false;
        }
    }

    async function sendMessage(conversationId, body, files = []) {
        const formData = new FormData();
        if (body) formData.append('body', body);
        files.forEach(file => formData.append('attachments[]', file));

        const { data } = await axios.post(
            `/api/messages/conversations/${conversationId}/send`,
            formData,
            { headers: { 'Content-Type': 'multipart/form-data' } }
        );
        messages.value.push(data);
        updateConversationPreview(conversationId, data);
        return data;
    }

    async function startConversation(userId) {
        const { data } = await axios.post('/api/messages/conversations/start', { user_id: userId });
        return data.conversation_id;
    }

    async function searchUsers(query) {
        if (!query || query.length < 1) return [];
        const { data } = await axios.get('/api/messages/search-users', { params: { q: query } });
        return data;
    }

    async function markRead(conversationId) {
        if (usePage().props.impersonating) return;
        await axios.post(`/api/messages/conversations/${conversationId}/read`);
        const conv = conversations.value.find(c => c.id === conversationId);
        if (conv) {
            totalUnread.value = Math.max(0, totalUnread.value - conv.unread);
            conv.unread = 0;
        }
    }

    async function fetchUnreadCount() {
        const { data } = await axios.get('/api/messages/unread-count');
        totalUnread.value = data.count;
    }

    function updateConversationPreview(conversationId, msg) {
        const conv = conversations.value.find(c => c.id === conversationId);
        if (conv) {
            conv.latest_message = {
                body: msg.body || (msg.attachments?.length ? '📎 Attachment' : ''),
                sender_name: msg.sender?.name || 'You',
                created_at: msg.created_at,
                is_mine: true,
            };
            conv.updated_at = msg.created_at;
        }
    }

    function emitTyping(conversationId) {
        if (sendTypingTimer) return;
        sendTypingTimer = setTimeout(() => { sendTypingTimer = null; }, 3000);
        axios.post(`/api/messages/conversations/${conversationId}/typing`).catch(() => {});
    }

    function handleTypingEvent(data) {
        try {
            if (activeConversation.value?.id === data.conversation_id) {
                typingState.value = data.user_name;
                clearTimeout(typingTimeout);
                typingTimeout = setTimeout(() => { typingState.value = null; }, 3500);
            }
        } catch {}
    }

    function handleReadEvent(data) {
        try {
            if (activeConversation.value?.id === data.conversation_id) {
                otherLastRead.value = data.read_at;
            }
            const conv = conversations.value.find(c => c.id === data.conversation_id);
            if (conv) conv.other_last_read = data.read_at;
        } catch {}
    }

    function handlePresenceEvent(data) {
        try {
            conversations.value.forEach(conv => {
                if (conv.user?.id === data.user_id) {
                    conv.user.presence_status = data.status;
                    conv.user.last_active_at = data.last_active_at;
                }
            });
            if (activeConversation.value?.user?.id === data.user_id) {
                activeConversation.value = {
                    ...activeConversation.value,
                    user: {
                        ...activeConversation.value.user,
                        presence_status: data.status,
                        last_active_at: data.last_active_at,
                    },
                };
            }
        } catch {}
    }

    function handleIncomingMessage(data) {
        try {
            typingState.value = null;

            if (activeConversation.value?.id === data.conversation_id) {
                const exists = messages.value.some(m => m.id === data.id);
                if (!exists) {
                    messages.value.push({
                        id: data.id,
                        conversation_id: data.conversation_id,
                        user_id: data.user_id,
                        body: data.body,
                        attachments: data.attachments || [],
                        sender: { id: data.user_id, name: data.sender_name },
                        created_at: data.created_at,
                    });
                }
                markRead(data.conversation_id).catch(() => {});
            } else {
                totalUnread.value++;
                const conv = conversations.value.find(c => c.id === data.conversation_id);
                if (conv) {
                    conv.unread = (conv.unread || 0) + 1;
                    conv.latest_message = {
                        body: data.body,
                        sender_name: data.sender_name,
                        created_at: data.created_at,
                        is_mine: false,
                    };
                    conv.updated_at = data.created_at;
                } else {
                    fetchConversations().catch(() => {});
                }
            }

            playNotificationSound();
            onIncomingCallbacks.forEach(cb => { try { cb(data); } catch {} });
        } catch {}
    }

    let audioCtx = null;
    let audioUnlocked = false;

    function getAudioContext() {
        if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        return audioCtx;
    }

    function unlockAudio() {
        if (audioUnlocked) return;
        const ctx = getAudioContext();
        if (ctx.state === 'suspended') ctx.resume().catch(() => {});
        audioUnlocked = true;
    }

    function initAudio() {
        ['click', 'keydown', 'touchstart'].forEach(e => {
            document.addEventListener(e, unlockAudio, { once: true, passive: true });
        });
    }

    function playNotificationSound() {
        try {
            const ctx = getAudioContext();
            if (ctx.state === 'suspended') {
                ctx.resume().then(() => playBeep(ctx)).catch(() => {});
                return;
            }
            playBeep(ctx);
        } catch {}
    }

    function playBeep(ctx) {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.setValueAtTime(880, ctx.currentTime);
        osc.frequency.setValueAtTime(1100, ctx.currentTime + 0.1);
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.3);
    }

    function onActivity() {
        clearTimeout(activityTimeout);
        if (currentStatus !== 'online') {
            currentStatus = 'online';
            sendStatusNow('online');
        }
        activityTimeout = setTimeout(() => {
            currentStatus = 'away';
            sendStatusNow('away');
        }, 120000);
    }

    function sendStatusNow(status) {
        if (statusChangeThrottle) return;
        statusChangeThrottle = setTimeout(() => { statusChangeThrottle = null; }, 2000);
        axios.post('/api/heartbeat', { status }).catch(() => {});
    }

    function expireStalePresence() {
        const now = Date.now();
        function expire(user) {
            if (!user || !user.presence_status || user.presence_status === 'offline') return;
            if (!user.last_active_at) { user.presence_status = 'offline'; return; }
            const age = now - new Date(user.last_active_at).getTime();
            const limit = user.presence_status === 'online' ? ONLINE_EXPIRY : AWAY_EXPIRY;
            if (age > limit) {
                user.presence_status = 'offline';
            }
        }
        conversations.value.forEach(conv => expire(conv.user));
        if (activeConversation.value?.user) {
            const prev = activeConversation.value.user.presence_status;
            expire(activeConversation.value.user);
            if (activeConversation.value.user.presence_status !== prev) {
                activeConversation.value = { ...activeConversation.value };
            }
        }
    }

    function startHeartbeat() {
        if (heartbeatStarted) return;
        if (usePage().props.impersonating) return;
        heartbeatStarted = true;
        currentStatus = 'online';
        initAudio();

        axios.post('/api/heartbeat', { status: 'online' }).catch(() => {});

        heartbeatInterval = setInterval(() => {
            const status = document.hidden ? 'away' : currentStatus;
            axios.post('/api/heartbeat', { status }).catch(() => {});
        }, 30000);

        presenceCheckInterval = setInterval(expireStalePresence, 15000);

        ['mousemove', 'keydown', 'scroll', 'click', 'touchstart'].forEach(evt => {
            document.addEventListener(evt, onActivity, { passive: true });
        });
        onActivity();

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                currentStatus = 'away';
                clearTimeout(activityTimeout);
                axios.post('/api/heartbeat', { status: 'away' }).catch(() => {});
            } else {
                onActivity();
            }
        });

        function goOfflineBeacon() {
            navigator.sendBeacon('/api/go-offline');
        }
        window.addEventListener('beforeunload', goOfflineBeacon);
        window.addEventListener('pagehide', goOfflineBeacon);
    }

    function stopHeartbeat() {
        clearInterval(heartbeatInterval);
        clearInterval(presenceCheckInterval);
        heartbeatInterval = null;
        presenceCheckInterval = null;
        heartbeatStarted = false;
    }

    function onIncoming(cb) {
        onIncomingCallbacks.push(cb);
        return () => { const i = onIncomingCallbacks.indexOf(cb); if (i > -1) onIncomingCallbacks.splice(i, 1); };
    }

    function openPanel() { isPanelOpen.value = true; }
    function closePanel() { isPanelOpen.value = false; activeConversation.value = null; typingState.value = null; }
    function togglePanel() { isPanelOpen.value = !isPanelOpen.value; }

    function setActiveConversation(conv) {
        activeConversation.value = conv;
        nextCursor.value = null;
        typingState.value = null;
        messages.value = [];
        unreadFromIndex.value = conv?.unread > 0 ? conv.unread : -1;
        otherLastRead.value = conv?.other_last_read || null;
        if (conv?.id) {
            fetchMessages(conv.id);
            markRead(conv.id).catch(() => {});
        }
    }

    return {
        conversations, activeConversation, messages, totalUnread, isPanelOpen,
        loadingMessages, nextCursor, typingState, otherLastRead, unreadFromIndex,
        fetchConversations, fetchMessages, sendMessage, startConversation,
        searchUsers, markRead, fetchUnreadCount, handleIncomingMessage,
        handleTypingEvent, handleReadEvent, handlePresenceEvent, emitTyping,
        startHeartbeat, stopHeartbeat,
        openPanel, closePanel, togglePanel, setActiveConversation, onIncoming,
    };
}
