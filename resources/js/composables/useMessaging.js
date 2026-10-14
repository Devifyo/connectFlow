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
const groupDetails = ref(null);
const pinnedMessages = ref([]);
const onIncomingCallbacks = [];
const onSeenCallbacks = [];

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

    async function sendMessage(conversationId, body, files = [], mentions = []) {
        const formData = new FormData();
        if (body) formData.append('body', body);
        files.forEach(file => formData.append('attachments[]', file));
        mentions.forEach(id => formData.append('mentions[]', id));

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

    async function fetchAllTeammates() {
        const { data } = await axios.get('/api/messages/search-users', { params: { all: 1 } });
        return data;
    }

    async function markRead(conversationId) {
        // While impersonating we don't persist reads, but the badge should still clear on screen.
        if (!usePage().props.impersonating) {
            await axios.post(`/api/messages/conversations/${conversationId}/read`);
        }
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
                type: msg.type || 'text',
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
                        type: data.type || 'text',
                        body: data.body,
                        mentions: data.mentions || [],
                        attachments: data.attachments || [],
                        reactions: [],
                        pinned_at: null,
                        ...(groupDetails.value?.is_owner && data.type !== 'system'
                            ? { seen_count: 0, audience_count: Math.max(0, (groupDetails.value.members?.length || 1) - 1) }
                            : {}),
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
                        type: data.type || 'text',
                        body: data.body || (data.attachments?.length ? '📎 Attachment' : ''),
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

    // Reactions, pins and group membership/settings changes from other users.
    function handleChatEvent(data) {
        try {
            const isActive = activeConversation.value?.id === data.conversation_id;
            switch (data.action) {
                case 'reaction': {
                    if (!isActive) break;
                    const msg = messages.value.find(m => m.id === data.message_id);
                    if (msg) msg.reactions = data.reactions;
                    const pinned = pinnedMessages.value.find(m => m.id === data.message_id);
                    if (pinned) pinned.reactions = data.reactions;
                    break;
                }
                case 'message.deleted': {
                    messages.value = messages.value.filter(m => m.id !== data.message_id);
                    pinnedMessages.value = pinnedMessages.value.filter(m => m.id !== data.message_id);
                    fetchConversations().catch(() => {});
                    break;
                }
                case 'seen': {
                    if (!isActive) break;
                    const ids = new Set(data.message_ids || []);
                    messages.value.forEach(m => {
                        if (ids.has(m.id) && m.audience_count != null) {
                            m.seen_count = Math.min((m.seen_count || 0) + 1, m.audience_count);
                        }
                    });
                    onSeenCallbacks.forEach(cb => { try { cb(data); } catch {} });
                    break;
                }
                case 'pin': {
                    if (!isActive) break;
                    applyPinUpdate(data.message);
                    break;
                }
                case 'group.updated': {
                    const conv = conversations.value.find(c => c.id === data.conversation_id);
                    if (conv && data.title) conv.title = data.title;
                    if (isActive && data.title) {
                        activeConversation.value = { ...activeConversation.value, title: data.title };
                        if (groupDetails.value) groupDetails.value.title = data.title;
                    }
                    break;
                }
                case 'group.members': {
                    fetchConversations().then(() => {
                        if (!isActive) return;
                        const fresh = conversations.value.find(c => c.id === data.conversation_id);
                        if (fresh) activeConversation.value = { ...activeConversation.value, ...fresh, unread: 0 };
                    }).catch(() => {});
                    if (isActive) fetchGroupDetails(data.conversation_id).catch(() => {});
                    break;
                }
                case 'group.removed': {
                    const conv = conversations.value.find(c => c.id === data.conversation_id);
                    if (conv) totalUnread.value = Math.max(0, totalUnread.value - (conv.unread || 0));
                    conversations.value = conversations.value.filter(c => c.id !== data.conversation_id);
                    if (isActive) setActiveConversation(null);
                    break;
                }
            }
        } catch {}
    }

    function applyPinUpdate(updated) {
        const msg = messages.value.find(m => m.id === updated.id);
        if (msg) {
            msg.pinned_at = updated.pinned_at;
            msg.pinned_by = updated.pinned_by;
        }
        pinnedMessages.value = pinnedMessages.value.filter(m => m.id !== updated.id);
        if (updated.pinned_at) pinnedMessages.value.unshift(updated);
    }

    async function toggleReaction(message, emoji) {
        const { data } = await axios.post(`/api/messages/${message.id}/react`, { emoji });
        const msg = messages.value.find(m => m.id === data.message_id);
        if (msg) msg.reactions = data.reactions;
    }

    async function togglePin(message) {
        const { data } = await axios.post(`/api/messages/${message.id}/pin`);
        applyPinUpdate(data);
    }

    async function fetchPinned(conversationId) {
        const { data } = await axios.get(`/api/messages/conversations/${conversationId}/pinned`);
        if (activeConversation.value?.id === conversationId) pinnedMessages.value = data;
    }

    async function fetchGroupDetails(conversationId) {
        const { data } = await axios.get(`/api/messages/groups/${conversationId}`);
        if (activeConversation.value?.id === conversationId) groupDetails.value = data;
        return data;
    }

    async function createGroup(title, memberIds) {
        const { data } = await axios.post('/api/messages/groups', { title, member_ids: memberIds });
        await openConversationById(data.conversation_id);
        return data.conversation_id;
    }

    // Group actions return the system lines they posted; broadcasts skip the actor, so add them here.
    function appendSystemMessages(conversationId, list) {
        if (!list?.length || activeConversation.value?.id !== conversationId) return;
        list.forEach(m => {
            if (!messages.value.some(x => x.id === m.id)) messages.value.push(m);
        });
        updateConversationPreview(conversationId, list[list.length - 1]);
    }

    async function renameGroup(conversationId, title) {
        const { data } = await axios.put(`/api/messages/groups/${conversationId}`, { title });
        appendSystemMessages(conversationId, data.system_messages);
        const conv = conversations.value.find(c => c.id === conversationId);
        if (conv) conv.title = data.title;
        if (activeConversation.value?.id === conversationId) {
            activeConversation.value = { ...activeConversation.value, title: data.title };
        }
        await fetchGroupDetails(conversationId);
    }

    async function addGroupMembers(conversationId, userIds) {
        const { data } = await axios.post(`/api/messages/groups/${conversationId}/members`, { user_ids: userIds });
        appendSystemMessages(conversationId, data.system_messages);
        await Promise.all([fetchGroupDetails(conversationId), fetchConversations()]);
    }

    async function removeGroupMember(conversationId, userId) {
        const { data } = await axios.delete(`/api/messages/groups/${conversationId}/members/${userId}`);
        appendSystemMessages(conversationId, data.system_messages);
        await Promise.all([fetchGroupDetails(conversationId), fetchConversations()]);
    }

    async function setMemberCanSend(conversationId, userId, canSend) {
        await axios.put(`/api/messages/groups/${conversationId}/members/${userId}`, { can_send: canSend });
        await fetchGroupDetails(conversationId);
    }

    async function setAllCanSend(conversationId, canSend) {
        const { data } = await axios.put(`/api/messages/groups/${conversationId}/members/all`, { can_send: canSend });
        appendSystemMessages(conversationId, data.system_messages);
        await fetchGroupDetails(conversationId);
    }

    async function leaveGroup(conversationId) {
        await axios.post(`/api/messages/groups/${conversationId}/leave`);
        conversations.value = conversations.value.filter(c => c.id !== conversationId);
        if (activeConversation.value?.id === conversationId) setActiveConversation(null);
    }

    async function createInviteLink(conversationId) {
        const { data } = await axios.post(`/api/messages/groups/${conversationId}/invite-link`);
        if (groupDetails.value?.id === conversationId) groupDetails.value.invite_url = data.invite_url;
        return data.invite_url;
    }

    async function disableInviteLink(conversationId) {
        await axios.delete(`/api/messages/groups/${conversationId}/invite-link`);
        if (groupDetails.value?.id === conversationId) groupDetails.value.invite_url = null;
    }

    // Used by the dashboards when the page is opened with ?join=<token>.
    async function joinGroupByToken(token) {
        const { data } = await axios.post(`/api/messages/groups/join/${token}`);
        await openConversationById(data.conversation_id);
        return data;
    }

    async function deleteGroup(conversationId) {
        await axios.delete(`/api/messages/groups/${conversationId}`);
        conversations.value = conversations.value.filter(c => c.id !== conversationId);
        if (activeConversation.value?.id === conversationId) setActiveConversation(null);
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

    function onSeen(cb) {
        onSeenCallbacks.push(cb);
        return () => { const i = onSeenCallbacks.indexOf(cb); if (i > -1) onSeenCallbacks.splice(i, 1); };
    }

    function onIncoming(cb) {
        onIncomingCallbacks.push(cb);
        return () => { const i = onIncomingCallbacks.indexOf(cb); if (i > -1) onIncomingCallbacks.splice(i, 1); };
    }

    function openPanel() { isPanelOpen.value = true; }
    function closePanel() { isPanelOpen.value = false; activeConversation.value = null; typingState.value = null; }
    function togglePanel() { isPanelOpen.value = !isPanelOpen.value; }

    async function openConversationById(conversationId) {
        await fetchConversations();
        const conv = conversations.value.find(c => c.id === conversationId);
        if (conv) {
            isPanelOpen.value = true;
            setActiveConversation(conv);
        } else {
            isPanelOpen.value = true;
        }
    }

    async function openConversationByHash(hash) {
        try {
            const { data } = await axios.get(`/api/messages/resolve/${hash}`);
            if (data.conversation_id) {
                await openConversationById(data.conversation_id);
            }
        } catch {}
    }

    function setActiveConversation(conv) {
        activeConversation.value = conv;
        nextCursor.value = null;
        typingState.value = null;
        messages.value = [];
        unreadFromIndex.value = conv?.unread > 0 ? conv.unread : -1;
        otherLastRead.value = conv?.other_last_read || null;
        groupDetails.value = null;
        pinnedMessages.value = [];
        if (conv?.id) {
            fetchMessages(conv.id);
            fetchPinned(conv.id).catch(() => {});
            if (conv.is_group) fetchGroupDetails(conv.id).catch(() => {});
            markRead(conv.id).catch(() => {});
        }
    }

    return {
        conversations, activeConversation, messages, totalUnread, isPanelOpen,
        loadingMessages, nextCursor, typingState, otherLastRead, unreadFromIndex,
        groupDetails, pinnedMessages,
        fetchConversations, fetchMessages, sendMessage, startConversation,
        searchUsers, fetchAllTeammates, markRead, fetchUnreadCount, handleIncomingMessage,
        handleTypingEvent, handleReadEvent, handlePresenceEvent, handleChatEvent, emitTyping,
        toggleReaction, togglePin, fetchPinned, fetchGroupDetails, createGroup, renameGroup,
        addGroupMembers, removeGroupMember, setMemberCanSend, setAllCanSend, leaveGroup, deleteGroup,
        createInviteLink, disableInviteLink, joinGroupByToken,
        startHeartbeat, stopHeartbeat,
        openPanel, closePanel, togglePanel, setActiveConversation, openConversationById, openConversationByHash, onIncoming, onSeen,
    };
}
