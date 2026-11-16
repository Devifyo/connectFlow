<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import PitchFlowLogo from '@/Components/PitchFlowLogo.vue';

const props = defineProps({
    reason: { type: String, default: '' },
    since: { type: String, default: null },
    company: { type: String, default: '' },
});

const checking = ref(false);
let poll = null;

const sinceLabel = props.since
    ? new Date(props.since).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
    : '';

async function checkAgain() {
    checking.value = true;
    try {
        const { data } = await axios.get('/api/maintenance/status');
        if (!data.maintenance) {
            window.location.href = '/dashboard';
            return;
        }
    } catch (e) {} finally {
        checking.value = false;
    }
}

function logout() {
    router.post('/logout');
}

onMounted(() => { poll = setInterval(checkAgain, 30000); });
onUnmounted(() => { if (poll) clearInterval(poll); });
</script>

<template>
    <Head title="Temporarily unavailable" />
    <div class="min-h-screen bg-surface-950 flex items-center justify-center px-6">
        <div class="w-full max-w-md text-center">
            <PitchFlowLogo class="h-8 mx-auto mb-8" />
            <div class="card p-8">
                <div class="w-14 h-14 rounded-full bg-amber-500/10 border border-amber-500/20 flex items-center justify-center mx-auto mb-5">
                    <svg class="w-7 h-7 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z"/>
                    </svg>
                </div>
                <h1 class="text-lg font-semibold text-surface-100 mb-1">{{ company || 'This workspace' }} is temporarily unavailable</h1>
                <p class="text-sm text-surface-400 mb-5">Please check back shortly.</p>

                <div class="text-left rounded-xl bg-surface-800/50 border border-surface-700/40 px-4 py-3 mb-5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-surface-500 mb-1">Reason</p>
                    <p class="text-sm text-surface-200 whitespace-pre-line">{{ reason || 'No reason given.' }}</p>
                    <p v-if="sinceLabel" class="text-[11px] text-surface-500 mt-2">Since {{ sinceLabel }} IST</p>
                </div>

                <div class="flex items-center justify-center gap-3">
                    <button @click="checkAgain" :disabled="checking" class="btn-primary text-sm py-2.5 px-5" :class="{ 'opacity-50': checking }">
                        {{ checking ? 'Checking...' : 'Check again' }}
                    </button>
                    <button @click="logout" class="btn-ghost text-sm py-2.5 px-4">Log out</button>
                </div>
                <p class="text-[11px] text-surface-600 mt-4">This page checks automatically every 30 seconds.</p>
            </div>
        </div>
    </div>
</template>
