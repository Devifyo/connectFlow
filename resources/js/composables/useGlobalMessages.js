import { ref } from 'vue';
import axios from 'axios';

const pendingMessages = ref([]);
const currentPopup = ref(null);
const myAnnouncements = ref([]);
const myAnnouncementsLoading = ref(false);
let initialized = false;

export function useGlobalMessages() {
    async function fetchPending() {
        try {
            const { data } = await axios.get('/api/global-messages/pending');
            pendingMessages.value = data;
            if (!currentPopup.value && data.length > 0) {
                currentPopup.value = data[0];
            }
        } catch {}
    }

    async function dismiss(messageId) {
        try {
            await axios.post(`/api/global-messages/${messageId}/dismiss`);
            pendingMessages.value = pendingMessages.value.filter(m => m.id !== messageId);
            if (currentPopup.value?.id === messageId) {
                currentPopup.value = pendingMessages.value[0] || null;
            }
        } catch {}
    }

    async function fetchMyAnnouncements() {
        myAnnouncementsLoading.value = true;
        try {
            const { data } = await axios.get('/api/global-messages/my-history');
            myAnnouncements.value = data;
        } catch {} finally { myAnnouncementsLoading.value = false; }
    }

    async function toggleReaction(messageId, emoji) {
        try {
            await axios.post(`/api/global-messages/${messageId}/react`, { emoji });
        } catch {}
    }

    async function fetchReactions(messageId) {
        try {
            const { data } = await axios.get(`/api/global-messages/${messageId}/reactions`);
            return data;
        } catch { return { reactions: [], user_reactions: [] }; }
    }

    async function fetchComments(messageId) {
        try {
            const { data } = await axios.get(`/api/global-messages/${messageId}/comments`);
            return data;
        } catch { return []; }
    }

    async function addComment(messageId, body) {
        try {
            const { data } = await axios.post(`/api/global-messages/${messageId}/comments`, { body });
            return data;
        } catch { return null; }
    }

    function handleGlobalMessageEvent(data) {
        const msg = {
            id: data.id,
            title: data.title,
            body: data.body,
            priority: data.priority,
            sender_name: data.sender_name,
            sender_picture: data.sender_picture,
            created_at: data.created_at,
            read_at: null,
        };
        pendingMessages.value.unshift(msg);
        currentPopup.value = msg;
    }

    function init() {
        if (initialized) return;
        initialized = true;
        fetchPending();
    }

    return {
        pendingMessages,
        currentPopup,
        myAnnouncements,
        myAnnouncementsLoading,
        fetchPending,
        dismiss,
        fetchMyAnnouncements,
        toggleReaction,
        fetchReactions,
        fetchComments,
        addComment,
        handleGlobalMessageEvent,
        init,
    };
}
