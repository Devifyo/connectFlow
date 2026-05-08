import { ref } from 'vue';
import axios from 'axios';

const conversations = ref([]);
const activeConversation = ref(null);
const messages = ref([]);
const totalUnread = ref(0);
const isPanelOpen = ref(false);
const nextCursor = ref(null);
const loadingMessages = ref(false);

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
        } finally {
            loadingMessages.value = false;
        }
    }

    async function sendMessage(conversationId, body) {
        const { data } = await axios.post(`/api/messages/conversations/${conversationId}/send`, { body });
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
        await axios.post(`/api/messages/conversations/${conversationId}/read`);
        const conv = conversations.value.find(c => c.id === conversationId);
        if (conv) {
            totalUnread.value -= conv.unread;
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
                body: msg.body,
                sender_name: msg.sender?.name || 'You',
                created_at: msg.created_at,
                is_mine: true,
            };
            conv.updated_at = msg.created_at;
        }
    }

    function handleIncomingMessage(data) {
        if (activeConversation.value?.id === data.conversation_id) {
            const exists = messages.value.some(m => m.id === data.id);
            if (!exists) {
                messages.value.push({
                    id: data.id,
                    conversation_id: data.conversation_id,
                    user_id: data.user_id,
                    body: data.body,
                    sender: { id: data.user_id, name: data.sender_name },
                    created_at: data.created_at,
                });
            }
            markRead(data.conversation_id);
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
                fetchConversations();
            }
        }

        const currentUserId = document.querySelector('meta[name="user-id"]')?.content;
        if (String(data.user_id) !== String(currentUserId)) {
            playNotificationSound();
        }
    }

    function playNotificationSound() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
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
        } catch {}
    }

    function openPanel() { isPanelOpen.value = true; }
    function closePanel() { isPanelOpen.value = false; }
    function togglePanel() { isPanelOpen.value = !isPanelOpen.value; }

    function setActiveConversation(conv) {
        activeConversation.value = conv;
        nextCursor.value = null;
        if (conv) {
            fetchMessages(conv.id);
            markRead(conv.id);
        }
    }

    return {
        conversations, activeConversation, messages, totalUnread, isPanelOpen,
        loadingMessages, nextCursor,
        fetchConversations, fetchMessages, sendMessage, startConversation,
        searchUsers, markRead, fetchUnreadCount, handleIncomingMessage,
        openPanel, closePanel, togglePanel, setActiveConversation,
    };
}
