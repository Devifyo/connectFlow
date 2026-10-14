<script setup>
import { ref, computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useMessaging } from '@/composables/useMessaging';
import { vProtectedSrc } from '@/directives/protectedSrc';
import MemberPicker from './MemberPicker.vue';

const emit = defineEmits(['close']);

const {
    activeConversation, groupDetails, renameGroup, addGroupMembers, removeGroupMember,
    setMemberCanSend, setAllCanSend, leaveGroup, deleteGroup,
    createInviteLink, disableInviteLink,
} = useMessaging();

const page = usePage();
const currentUserId = computed(() => page.props.auth.user?.id);
const isOwner = computed(() => !!groupDetails.value?.is_owner);
const conversationId = computed(() => activeConversation.value?.id);

const editingTitle = ref(false);
const titleDraft = ref('');
const showAdd = ref(false);
const toAdd = ref([]);
const busy = ref(false);
const error = ref('');

watch(() => groupDetails.value?.title, (t) => { if (!editingTitle.value) titleDraft.value = t || ''; }, { immediate: true });

const memberIds = computed(() => (groupDetails.value?.members || []).map(m => m.id));
const everyoneCanSend = computed(() => (groupDetails.value?.members || []).every(m => m.role === 'owner' || m.can_send));

async function run(fn) {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        await fn();
    } catch (e) {
        error.value = e.response?.data?.error || e.response?.data?.message || 'Something went wrong.';
    } finally {
        busy.value = false;
    }
}

function saveTitle() {
    const t = titleDraft.value.trim();
    if (!t) return;
    run(async () => {
        await renameGroup(conversationId.value, t);
        editingTitle.value = false;
    });
}

function addMembers() {
    if (!toAdd.value.length) return;
    run(async () => {
        await addGroupMembers(conversationId.value, toAdd.value.map(u => u.id));
        toAdd.value = [];
        showAdd.value = false;
    });
}

function kick(member) {
    if (!confirm(`Remove ${member.name} from this group?`)) return;
    run(() => removeGroupMember(conversationId.value, member.id));
}

function toggleSend(member) {
    run(() => setMemberCanSend(conversationId.value, member.id, !member.can_send));
}

function toggleAll() {
    run(() => setAllCanSend(conversationId.value, !everyoneCanSend.value));
}

const copied = ref(false);

function generateLink() {
    const reset = !!groupDetails.value?.invite_url;
    if (reset && !confirm('Reset the invite link? The old link will stop working.')) return;
    run(() => createInviteLink(conversationId.value));
}

function turnOffLink() {
    if (!confirm('Turn off the invite link? Nobody new can join with it.')) return;
    run(() => disableInviteLink(conversationId.value));
}

async function copyLink() {
    try {
        await navigator.clipboard.writeText(groupDetails.value.invite_url);
    } catch {
        window.prompt('Copy this invite link:', groupDetails.value.invite_url);
        return;
    }
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
}

function leave() {
    const msg = isOwner.value
        ? 'Leave this group? Ownership will pass to the longest-standing member.'
        : 'Leave this group?';
    if (!confirm(msg)) return;
    run(async () => { await leaveGroup(conversationId.value); emit('close'); });
}

function destroy() {
    if (!confirm('Delete this group for everyone? This cannot be undone.')) return;
    run(async () => { await deleteGroup(conversationId.value); emit('close'); });
}

function positionLabel(member) {
    return (member.positions || []).map(p => p.title).join(', ');
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}
</script>

<template>
    <div class="absolute inset-y-0 right-0 z-20 w-full sm:w-[320px] bg-surface-900 border-l border-surface-800/50 flex flex-col shadow-2xl">
        <div class="h-16 px-4 flex items-center justify-between border-b border-surface-800/50 flex-shrink-0">
            <h3 class="text-sm font-semibold text-surface-100">Group info</h3>
            <button @click="emit('close')" class="w-7 h-7 rounded-lg hover:bg-surface-800 flex items-center justify-center text-surface-400 hover:text-surface-100">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div v-if="!groupDetails" class="flex-1 flex items-center justify-center">
            <div class="w-5 h-5 border-2 border-surface-600 border-t-surface-300 rounded-full animate-spin"></div>
        </div>

        <div v-else class="flex-1 overflow-y-auto scrollbar-thin p-4 space-y-5">
            <!-- Title -->
            <div>
                <p class="text-[10px] uppercase tracking-wide text-surface-500 mb-1.5">Room title</p>
                <div v-if="editingTitle" class="flex gap-2">
                    <input v-model="titleDraft" maxlength="100" @keydown.enter="saveTitle"
                        class="flex-1 px-3 py-1.5 text-sm bg-surface-800/60 border border-surface-700/40 rounded-lg text-surface-100 focus:outline-none focus:border-surface-600" />
                    <button @click="saveTitle" :disabled="busy" class="px-2.5 text-xs font-semibold rounded-lg bg-brand text-surface-950">Save</button>
                </div>
                <div v-else class="flex items-center gap-2">
                    <span class="text-sm font-semibold text-surface-100 flex-1 break-words">{{ groupDetails.title }}</span>
                    <button v-if="isOwner" @click="editingTitle = true" class="text-[11px] text-brand hover:underline">Rename</button>
                </div>
            </div>

            <!-- Owner controls -->
            <div v-if="isOwner" class="rounded-xl border border-surface-800/60 p-3 space-y-2">
                <p class="text-[10px] uppercase tracking-wide text-surface-500">Who can send messages</p>
                <button @click="toggleAll" :disabled="busy"
                    class="w-full flex items-center justify-between text-xs text-surface-200">
                    <span>{{ everyoneCanSend ? 'Everyone' : 'Only selected members' }}</span>
                    <span class="text-[11px] text-brand">{{ everyoneCanSend ? 'Restrict all' : 'Allow everyone' }}</span>
                </button>
                <p class="text-[10px] text-surface-500">Use the toggle next to each member to allow or block them individually.</p>
            </div>

            <!-- Invite link (owner only) -->
            <div v-if="isOwner" class="rounded-xl border border-surface-800/60 p-3 space-y-2">
                <p class="text-[10px] uppercase tracking-wide text-surface-500">Invite link</p>
                <template v-if="groupDetails.invite_url">
                    <div class="flex gap-1.5">
                        <input :value="groupDetails.invite_url" readonly @focus="$event.target.select()"
                            class="flex-1 min-w-0 px-2 py-1.5 text-[11px] bg-surface-800/60 border border-surface-700/40 rounded-lg text-surface-300 focus:outline-none" />
                        <button @click="copyLink" class="px-2.5 text-[11px] font-semibold rounded-lg bg-brand text-surface-950 flex-shrink-0">
                            {{ copied ? 'Copied!' : 'Copy' }}
                        </button>
                    </div>
                    <p class="text-[10px] text-surface-500">Any teammate who opens this link joins the group.</p>
                    <div class="flex gap-3">
                        <button @click="generateLink" :disabled="busy" class="text-[11px] text-surface-400 hover:text-surface-200">Reset link</button>
                        <button @click="turnOffLink" :disabled="busy" class="text-[11px] text-red-400 hover:text-red-300">Turn off</button>
                    </div>
                </template>
                <button v-else @click="generateLink" :disabled="busy"
                    class="w-full py-1.5 text-xs font-semibold rounded-lg bg-brand/10 text-brand hover:bg-brand/20">
                    Create invite link
                </button>
            </div>

            <!-- Members -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <p class="text-[10px] uppercase tracking-wide text-surface-500">{{ groupDetails.members.length }} members</p>
                    <button v-if="isOwner" @click="showAdd = !showAdd" class="text-[11px] text-brand hover:underline">{{ showAdd ? 'Cancel' : '+ Add members' }}</button>
                </div>

                <div v-if="showAdd" class="mb-3 space-y-2">
                    <MemberPicker v-model="toAdd" :exclude-ids="memberIds" />
                    <button @click="addMembers" :disabled="!toAdd.length || busy"
                        class="w-full py-1.5 text-xs font-semibold rounded-lg bg-brand text-surface-950 disabled:opacity-40">
                        Add {{ toAdd.length || '' }} member{{ toAdd.length === 1 ? '' : 's' }}
                    </button>
                </div>

                <div class="space-y-1">
                    <div v-for="m in groupDetails.members" :key="m.id" class="flex items-center gap-2.5 px-2 py-1.5 rounded-lg hover:bg-surface-800/40">
                        <div class="w-8 h-8 rounded-full bg-surface-700 flex items-center justify-center flex-shrink-0 overflow-hidden">
                            <img v-if="m.profile_picture_url" v-protected-src="m.profile_picture_url" class="w-full h-full object-cover" draggable="false" @contextmenu.prevent />
                            <span v-else class="text-[10px] font-bold text-surface-300">{{ initials(m.name) }}</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-xs font-medium text-surface-100 truncate">
                                {{ m.name }}<span v-if="m.id === currentUserId" class="text-surface-500"> (you)</span>
                            </div>
                            <div class="text-[10px] truncate text-surface-500">
                                <span v-if="m.role === 'owner'" class="text-brand font-medium">Owner</span>
                                <span v-else-if="!m.can_send" class="text-amber-400">Read only</span>
                                <template v-if="positionLabel(m)">
                                    <span v-if="m.role === 'owner' || !m.can_send"> · </span>{{ positionLabel(m) }}
                                </template>
                                <template v-else-if="m.role !== 'owner' && m.can_send">Member</template>
                            </div>
                        </div>
                        <template v-if="isOwner && m.role !== 'owner'">
                            <button @click="toggleSend(m)" :disabled="busy" :title="m.can_send ? 'Can send — click to make read-only' : 'Read-only — click to allow sending'"
                                class="relative w-8 h-4 rounded-full transition-colors flex-shrink-0"
                                :class="m.can_send ? 'bg-emerald-500/70' : 'bg-surface-700'">
                                <span class="absolute top-0.5 w-3 h-3 rounded-full bg-white transition-all" :class="m.can_send ? 'left-[18px]' : 'left-0.5'"></span>
                            </button>
                            <button @click="kick(m)" :disabled="busy" title="Remove from group"
                                class="w-6 h-6 rounded-md flex items-center justify-center text-surface-500 hover:text-red-400 hover:bg-red-500/10 flex-shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z"/></svg>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
        </div>

        <div v-if="groupDetails" class="p-4 border-t border-surface-800/50 space-y-2 flex-shrink-0">
            <button @click="leave" :disabled="busy" class="w-full py-2 text-xs font-medium rounded-lg text-red-400 hover:bg-red-500/10">Leave group</button>
            <button v-if="isOwner" @click="destroy" :disabled="busy" class="w-full py-2 text-xs font-medium rounded-lg bg-red-500/10 text-red-400 hover:bg-red-500/20">Delete group</button>
        </div>
    </div>
</template>
