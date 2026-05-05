<script setup>
import { Head, router } from '@inertiajs/vue3';
import { onMounted, ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps(['auth']);

const ready = ref(false);
const loading = ref(true);
const tenants = ref([]);
const stats = ref({ total_tenants: 0, total_users: 0, total_bidders: 0, total_bids: 0, bids_today: 0 });
const confirmAction = ref(null);

async function fetchTenants() {
    try {
        const { data } = await axios.get('/api/super/tenants');
        tenants.value = data.tenants;
        stats.value = data.stats;
    } catch (e) {}
}

async function updateTenantStatus(tenantId, status) {
    try {
        await axios.put(`/api/super/tenants/${tenantId}/status`, { status });
        confirmAction.value = null;
        await fetchTenants();
    } catch (e) {}
}

function toLocalDate(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

const statusBadge = {
    active: 'badge-success',
    suspended: 'badge-danger',
    past_due: 'badge-warning',
};

function statusLabel(s) {
    if (s === 'past_due') return 'Past Due';
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
}

const planColors = {
    Starter: 'bg-surface-700/50 text-surface-300',
    Pro: 'bg-blue-500/10 text-blue-400',
    Enterprise: 'bg-violet-500/10 text-violet-400',
};

function planBadgeClass(plan) {
    return planColors[plan] || 'bg-surface-700/50 text-surface-300';
}

onMounted(async () => {
    ready.value = true;
    await fetchTenants();
    loading.value = false;
});
</script>

<template>
    <Head title="Command Center" />

    <div class="min-h-screen bg-surface-950 text-surface-100">
        <!-- Top bar -->
        <nav class="sticky top-0 z-50 bg-surface-950/80 backdrop-blur-xl border-b border-surface-800/50">
            <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-7 h-7 rounded-md bg-brand flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <span class="text-sm font-bold">ConnectFlow</span>
                    <span class="badge-neutral text-xs ml-2">Super Admin</span>
                </div>

                <div class="flex items-center gap-4">
                    <span class="text-sm text-surface-400">{{ auth.user.name }}</span>
                    <button @click="router.post('/logout')" class="btn-ghost text-xs">Sign out</button>
                </div>
            </div>
        </nav>

        <!-- Loading -->
        <div v-if="loading" class="flex items-center justify-center h-[60vh]">
            <div class="flex flex-col items-center gap-3">
                <div class="w-8 h-8 border-2 border-brand/30 border-t-brand rounded-full animate-spin"></div>
                <span class="text-sm text-surface-400">Loading...</span>
            </div>
        </div>

        <main v-else class="max-w-7xl mx-auto px-6 py-8" :class="{ 'animate-fade-in': ready }">
            <!-- Page header -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-surface-100">Command Center</h1>
                <p class="text-sm text-surface-400 mt-1">Platform-wide tenant management and oversight</p>
            </div>

            <!-- Stats row -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-8">
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Tenants</span>
                    <span class="text-2xl font-semibold text-surface-100 mt-1">{{ stats.total_tenants }}</span>
                </div>
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Total Users</span>
                    <span class="text-2xl font-semibold text-surface-100 mt-1">{{ stats.total_users }}</span>
                </div>
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Bidders</span>
                    <span class="text-2xl font-semibold text-surface-100 mt-1">{{ stats.total_bidders }}</span>
                </div>
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Total Bids</span>
                    <span class="text-2xl font-semibold text-surface-100 mt-1">{{ stats.total_bids }}</span>
                </div>
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Bids Today</span>
                    <span class="text-2xl font-semibold text-brand mt-1">{{ stats.bids_today }}</span>
                </div>
            </div>

            <!-- Tenants table -->
            <div class="card overflow-hidden">
                <div class="px-6 py-4 border-b border-surface-700/30 flex items-center justify-between">
                    <h2 class="font-semibold text-surface-100">Registered Agencies</h2>
                    <span class="text-xs text-surface-500">{{ tenants.length }} total</span>
                </div>

                <div v-if="tenants.length === 0" class="px-6 py-12 text-center">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-surface-800/50 flex items-center justify-center">
                        <svg class="w-8 h-8 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 7.5h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" />
                        </svg>
                    </div>
                    <p class="text-surface-300 font-medium">No tenants registered yet</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-surface-700/30">
                                <th class="px-6 py-3 text-left table-header">ID</th>
                                <th class="px-6 py-3 text-left table-header">Company</th>
                                <th class="px-6 py-3 text-left table-header">Plan</th>
                                <th class="px-6 py-3 text-left table-header">Status</th>
                                <th class="px-6 py-3 text-center table-header">Users</th>
                                <th class="px-6 py-3 text-center table-header">Bidders</th>
                                <th class="px-6 py-3 text-center table-header">Bids</th>
                                <th class="px-6 py-3 text-left table-header">Created</th>
                                <th class="px-6 py-3 text-right table-header">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm">
                            <tr v-for="tenant in tenants" :key="tenant.tenant_id"
                                class="border-b border-surface-800/30 hover:bg-surface-800/20 transition-colors">
                                <td class="px-6 py-4 font-mono text-xs text-surface-500">#{{ String(tenant.tenant_id).padStart(3, '0') }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-lg bg-surface-800 flex items-center justify-center text-xs font-bold text-surface-300">
                                            {{ tenant.company_name.charAt(0).toUpperCase() }}
                                        </div>
                                        <p class="font-medium text-surface-200">{{ tenant.company_name }}</p>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-xs font-medium px-2 py-1 rounded-md" :class="planBadgeClass(tenant.subscription_plan)">
                                        {{ tenant.subscription_plan || 'Free' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span :class="statusBadge[tenant.subscription_status] || 'badge-neutral'">
                                        {{ statusLabel(tenant.subscription_status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center font-mono text-surface-300">{{ tenant.user_count }}</td>
                                <td class="px-6 py-4 text-center font-mono text-surface-300">{{ tenant.bidder_count }}</td>
                                <td class="px-6 py-4 text-center font-mono text-surface-300">{{ tenant.bid_count }}</td>
                                <td class="px-6 py-4 text-xs text-surface-500">{{ toLocalDate(tenant.created_at) }}</td>
                                <td class="px-6 py-4 text-right relative">
                                    <div class="flex items-center justify-end gap-1">
                                        <button v-if="tenant.subscription_status === 'suspended'"
                                            @click="updateTenantStatus(tenant.tenant_id, 'active')"
                                            class="btn-ghost text-xs text-emerald-400 hover:text-emerald-300">
                                            Activate
                                        </button>
                                        <button v-else
                                            @click="confirmAction = confirmAction === tenant.tenant_id ? null : tenant.tenant_id"
                                            class="btn-ghost text-xs text-red-400 hover:text-red-300">
                                            Suspend
                                        </button>
                                    </div>

                                    <!-- Confirm popup -->
                                    <div v-if="confirmAction === tenant.tenant_id"
                                        class="absolute right-6 top-full mt-1 w-56 bg-surface-800 border border-surface-700/50 rounded-xl shadow-xl z-20 p-4">
                                        <p class="text-xs text-surface-300 mb-3">Suspend <strong class="text-surface-100">{{ tenant.company_name }}</strong>?</p>
                                        <div class="flex gap-2">
                                            <button @click="updateTenantStatus(tenant.tenant_id, 'suspended')"
                                                class="flex-1 text-xs py-1.5 rounded-lg bg-red-500/15 text-red-400 hover:bg-red-500/25 transition-colors font-medium">
                                                Confirm
                                            </button>
                                            <button @click="confirmAction = null"
                                                class="flex-1 text-xs py-1.5 rounded-lg bg-surface-700/50 text-surface-300 hover:bg-surface-700 transition-colors">
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</template>
