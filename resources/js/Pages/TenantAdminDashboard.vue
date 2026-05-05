<script setup>
import { Head, router } from '@inertiajs/vue3';
import { onMounted, ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps(['auth']);

const ready = ref(false);
const activeTab = ref('pipeline');
const loading = ref(true);

const bids = ref([]);
const bidders = ref([]);
const summary = ref({ total: 0, bids_today: 0, conversion_rate: 0, status_counts: {}, connects_used: 0 });

const statusMenuOpen = ref(null);
const statusOptions = ['Submitted', 'Interviewing', 'Hired', 'Rejected'];

const statusColors = {
    Submitted: { dot: 'bg-blue-400', border: 'border-l-blue-400/50', badge: 'bg-blue-500/10 text-blue-400' },
    Interviewing: { dot: 'bg-amber-400', border: 'border-l-amber-400/50', badge: 'bg-amber-500/10 text-amber-400' },
    Hired: { dot: 'bg-emerald-400', border: 'border-l-emerald-400/50', badge: 'bg-emerald-500/10 text-emerald-400' },
    Rejected: { dot: 'bg-red-400', border: 'border-l-red-400/50', badge: 'bg-red-500/10 text-red-400' },
};

const userColors = ['indigo', 'emerald', 'amber', 'rose', 'cyan', 'violet', 'orange', 'teal'];
function getUserColor(name) {
    const idx = (name || '').charCodeAt(0) % userColors.length;
    const c = userColors[idx];
    return `bg-${c}-500/15 text-${c}-400`;
}

async function fetchPipelineData() {
    try {
        const { data } = await axios.get('/api/admin/reports/efficiency');
        bids.value = data.bids;
        summary.value = data.summary;
    } catch (e) {}
}

async function fetchTeamData() {
    try {
        const { data } = await axios.get('/api/admin/bidders');
        bidders.value = data.bidders;
    } catch (e) {}
}

async function updateBidStatus(bidId, status) {
    try {
        await axios.put(`/api/admin/bids/${bidId}/status`, { status });
        statusMenuOpen.value = null;
        await fetchPipelineData();
    } catch (e) {}
}

function toggleStatusMenu(bidId) {
    statusMenuOpen.value = statusMenuOpen.value === bidId ? null : bidId;
}

const pipelineColumns = computed(() => {
    const cols = {};
    for (const s of statusOptions) {
        cols[s] = bids.value.filter(b => b.status === s);
    }
    return cols;
});

function toLocalTime(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

function toLocalDate(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function truncateUrl(url, max = 40) {
    if (!url) return '';
    try {
        const u = new URL(url);
        const path = u.pathname.length > max ? u.pathname.substring(0, max) + '...' : u.pathname;
        return u.hostname + path;
    } catch {
        return url.length > max ? url.substring(0, max) + '...' : url;
    }
}

onMounted(async () => {
    ready.value = true;
    await Promise.all([fetchPipelineData(), fetchTeamData()]);
    loading.value = false;
});
</script>

<template>
    <Head title="Admin Dashboard" />

    <div class="min-h-screen bg-surface-950 text-surface-100 flex" @click="statusMenuOpen = null">
        <!-- Sidebar -->
        <aside class="hidden lg:flex w-60 flex-col border-r border-surface-800/50 bg-surface-900/50 flex-shrink-0">
            <div class="p-5 border-b border-surface-800/50">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-md bg-brand flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <span class="text-sm font-bold">ConnectFlow</span>
                    <span class="ml-auto text-[10px] font-medium bg-brand/10 text-brand px-1.5 py-0.5 rounded">Admin</span>
                </div>
            </div>

            <nav class="flex-1 px-3 py-4 space-y-1">
                <button @click="activeTab = 'pipeline'"
                    :class="activeTab === 'pipeline' ? 'bg-surface-800/60 text-surface-100' : 'text-surface-400 hover:text-surface-200 hover:bg-surface-800/30'"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15m-10.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125z" />
                    </svg>
                    Pipeline
                </button>
                <button @click="activeTab = 'team'"
                    :class="activeTab === 'team' ? 'bg-surface-800/60 text-surface-100' : 'text-surface-400 hover:text-surface-200 hover:bg-surface-800/30'"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                    Team
                </button>
                <button @click="activeTab = 'reports'"
                    :class="activeTab === 'reports' ? 'bg-surface-800/60 text-surface-100' : 'text-surface-400 hover:text-surface-200 hover:bg-surface-800/30'"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                    </svg>
                    Reports
                </button>
            </nav>

            <div class="p-4 border-t border-surface-800/50">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-surface-700 flex items-center justify-center text-xs font-semibold text-surface-300">
                        {{ auth.user.name.charAt(0).toUpperCase() }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-surface-200 truncate">{{ auth.user.name }}</p>
                        <p class="text-xs text-surface-500">Tenant Admin</p>
                    </div>
                </div>
                <button @click="router.post('/logout')" class="mt-3 w-full btn-ghost text-xs justify-center">
                    Sign out
                </button>
            </div>
        </aside>

        <!-- Main content -->
        <div class="flex-1 flex flex-col min-h-screen overflow-hidden">
            <!-- Mobile header -->
            <header class="lg:hidden h-14 border-b border-surface-800/50 px-4 flex items-center justify-between flex-shrink-0">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-md bg-brand flex items-center justify-center">
                        <svg class="w-3 h-3 text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <span class="text-sm font-bold">ConnectFlow</span>
                </div>
                <div class="flex gap-1">
                    <button v-for="tab in ['pipeline', 'team', 'reports']" :key="tab"
                        @click="activeTab = tab"
                        :class="activeTab === tab ? 'bg-surface-700 text-surface-100' : 'text-surface-400'"
                        class="px-3 py-1.5 rounded-md text-xs font-medium capitalize transition-colors">
                        {{ tab }}
                    </button>
                </div>
            </header>

            <!-- Top bar -->
            <header class="h-14 border-b border-surface-800/50 px-6 flex items-center justify-between flex-shrink-0">
                <h1 class="text-lg font-semibold text-surface-100 capitalize">{{ activeTab }}</h1>
                <div class="flex items-center gap-3">
                    <div v-if="summary.conversion_rate > 0" class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-surface-800/50 border border-surface-700/30">
                        <span class="text-xs text-surface-400">Conversion</span>
                        <span class="text-sm font-semibold text-brand">{{ summary.conversion_rate }}%</span>
                    </div>
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-surface-800/50 border border-surface-700/30">
                        <span class="text-xs text-surface-400">Total Bids</span>
                        <span class="text-sm font-semibold text-surface-200">{{ summary.total }}</span>
                    </div>
                </div>
            </header>

            <!-- Loading -->
            <div v-if="loading" class="flex-1 flex items-center justify-center">
                <div class="flex flex-col items-center gap-3">
                    <div class="w-8 h-8 border-2 border-brand/30 border-t-brand rounded-full animate-spin"></div>
                    <span class="text-sm text-surface-400">Loading...</span>
                </div>
            </div>

            <!-- ========== PIPELINE TAB ========== -->
            <main v-else-if="activeTab === 'pipeline'" class="flex-1 p-6 overflow-x-auto" :class="{ 'animate-fade-in': ready }">
                <div v-if="bids.length === 0" class="flex-1 flex items-center justify-center h-full">
                    <div class="text-center">
                        <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-surface-800/50 flex items-center justify-center">
                            <svg class="w-8 h-8 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </svg>
                        </div>
                        <p class="text-surface-300 font-medium">No bids yet</p>
                        <p class="text-sm text-surface-500 mt-1">Bids from your team will appear here</p>
                    </div>
                </div>

                <div v-else class="grid grid-cols-4 gap-4 h-full min-h-[500px]">
                    <div v-for="status in statusOptions" :key="status" class="flex flex-col rounded-2xl bg-surface-900/50 border border-surface-800/40 min-w-0">
                        <div class="px-4 py-3 border-b border-surface-800/30 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-2 h-2 rounded-full" :class="statusColors[status]?.dot"></div>
                                <h2 class="text-sm font-semibold text-surface-200">{{ status }}</h2>
                            </div>
                            <span class="text-xs font-medium text-surface-500 bg-surface-800/50 px-2 py-0.5 rounded-md">
                                {{ pipelineColumns[status]?.length || 0 }}
                            </span>
                        </div>

                        <div class="flex-1 p-3 space-y-2.5 overflow-y-auto scrollbar-thin">
                            <div v-for="bid in pipelineColumns[status]" :key="bid.bid_id"
                                class="card card-hover p-4 border-l-2 relative overflow-hidden"
                                :class="statusColors[status]?.border">
                                <p class="text-sm font-medium text-surface-200 mb-1.5 leading-snug truncate">
                                    {{ bid.job_title || truncateUrl(bid.job_url) }}
                                </p>
                                <div class="flex items-center gap-2 mb-3">
                                    <span class="text-xs text-surface-500">{{ bid.platform_name }}</span>
                                    <span class="text-surface-700">&middot;</span>
                                    <span class="text-xs text-surface-500">{{ bid.connects_used }} connects</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-[11px] text-surface-500">{{ toLocalDate(bid.created_at) }}</span>
                                    <div class="flex items-center gap-2">
                                        <!-- Status changer -->
                                        <div class="relative" @click.stop>
                                            <button @click="toggleStatusMenu(bid.bid_id)"
                                                class="text-[10px] font-medium px-2 py-0.5 rounded-md transition-colors cursor-pointer"
                                                :class="statusColors[status]?.badge">
                                                {{ status }}
                                            </button>
                                            <div v-if="statusMenuOpen === bid.bid_id"
                                                class="absolute right-0 bottom-full mb-1 w-32 bg-surface-800 border border-surface-700/50 rounded-lg shadow-xl z-20 py-1">
                                                <button v-for="s in statusOptions.filter(x => x !== status)" :key="s"
                                                    @click="updateBidStatus(bid.bid_id, s)"
                                                    class="w-full text-left px-3 py-1.5 text-xs text-surface-300 hover:bg-surface-700/50 transition-colors flex items-center gap-2">
                                                    <div class="w-1.5 h-1.5 rounded-full" :class="statusColors[s]?.dot"></div>
                                                    {{ s }}
                                                </button>
                                            </div>
                                        </div>
                                        <!-- User avatar -->
                                        <div v-if="bid.user" class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold"
                                            :class="getUserColor(bid.user.name)"
                                            :title="bid.user.name">
                                            {{ bid.user.name.charAt(0).toUpperCase() }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div v-if="!pipelineColumns[status]?.length" class="flex items-center justify-center h-32 text-xs text-surface-600">
                                No bids
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            <!-- ========== TEAM TAB ========== -->
            <main v-else-if="activeTab === 'team'" class="flex-1 p-6 overflow-auto" :class="{ 'animate-fade-in': ready }">
                <!-- Team stats -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                    <div class="stat-card">
                        <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Team Size</span>
                        <span class="text-2xl font-semibold text-surface-100 mt-1">{{ bidders.length }}</span>
                    </div>
                    <div class="stat-card">
                        <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Online Now</span>
                        <span class="text-2xl font-semibold text-emerald-400 mt-1">{{ bidders.filter(b => b.is_online).length }}</span>
                    </div>
                    <div class="stat-card">
                        <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Bids Today</span>
                        <span class="text-2xl font-semibold text-surface-100 mt-1">{{ bidders.reduce((s, b) => s + b.bids_today, 0) }}</span>
                    </div>
                    <div class="stat-card">
                        <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Total Bids</span>
                        <span class="text-2xl font-semibold text-surface-100 mt-1">{{ summary.total }}</span>
                    </div>
                </div>

                <!-- Bidders table -->
                <div class="card overflow-hidden">
                    <div class="px-6 py-4 border-b border-surface-700/30">
                        <h2 class="font-semibold text-surface-100">Team Members</h2>
                    </div>

                    <div v-if="bidders.length === 0" class="px-6 py-12 text-center">
                        <p class="text-surface-400">No bidders in your team yet</p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-surface-700/30">
                                    <th class="px-6 py-3 text-left table-header">Bidder</th>
                                    <th class="px-6 py-3 text-left table-header">Status</th>
                                    <th class="px-6 py-3 text-center table-header">Today's Bids</th>
                                    <th class="px-6 py-3 text-center table-header">This Week</th>
                                    <th class="px-6 py-3 text-center table-header">Total Bids</th>
                                    <th class="px-6 py-3 text-center table-header">Hours Today</th>
                                    <th class="px-6 py-3 text-left table-header">Joined</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm">
                                <tr v-for="bidder in bidders" :key="bidder.id"
                                    class="border-b border-surface-800/30 hover:bg-surface-800/20 transition-colors">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="relative">
                                                <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold"
                                                    :class="getUserColor(bidder.name)">
                                                    {{ bidder.name.charAt(0).toUpperCase() }}
                                                </div>
                                                <div v-if="bidder.is_online" class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-400 border-2 border-surface-900"></div>
                                            </div>
                                            <div>
                                                <p class="font-medium text-surface-200">{{ bidder.name }}</p>
                                                <p class="text-xs text-surface-500">{{ bidder.email }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span v-if="bidder.is_online" class="badge-success">Online</span>
                                        <span v-else class="badge-neutral">Offline</span>
                                    </td>
                                    <td class="px-6 py-4 text-center font-mono text-surface-300">{{ bidder.bids_today }}</td>
                                    <td class="px-6 py-4 text-center font-mono text-surface-300">{{ bidder.bids_this_week }}</td>
                                    <td class="px-6 py-4 text-center font-mono text-surface-300">{{ bidder.total_bids }}</td>
                                    <td class="px-6 py-4 text-center font-mono text-surface-300">{{ bidder.today_hours }}h</td>
                                    <td class="px-6 py-4 text-surface-500 text-xs">{{ toLocalDate(bidder.joined) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>

            <!-- ========== REPORTS TAB ========== -->
            <main v-else-if="activeTab === 'reports'" class="flex-1 p-6 overflow-auto" :class="{ 'animate-fade-in': ready }">
                <!-- Stats cards -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                    <div class="stat-card">
                        <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Total Proposals</span>
                        <span class="text-2xl font-semibold text-surface-100 mt-1">{{ summary.total }}</span>
                    </div>
                    <div class="stat-card">
                        <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Conversion Rate</span>
                        <span class="text-2xl font-semibold text-brand mt-1">{{ summary.conversion_rate }}%</span>
                    </div>
                    <div class="stat-card">
                        <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Connects Used</span>
                        <span class="text-2xl font-semibold text-surface-100 mt-1">{{ summary.connects_used }}</span>
                    </div>
                    <div class="stat-card">
                        <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Bids Today</span>
                        <span class="text-2xl font-semibold text-surface-100 mt-1">{{ summary.bids_today }}</span>
                    </div>
                </div>

                <!-- Status breakdown -->
                <div class="card overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-surface-700/30">
                        <h2 class="font-semibold text-surface-100">Pipeline Breakdown</h2>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            <div v-for="status in statusOptions" :key="status"
                                class="rounded-xl bg-surface-800/30 border border-surface-700/20 p-4 text-center">
                                <div class="w-3 h-3 rounded-full mx-auto mb-2" :class="statusColors[status]?.dot"></div>
                                <p class="text-2xl font-bold text-surface-100">{{ summary.status_counts?.[status] || 0 }}</p>
                                <p class="text-xs text-surface-400 mt-1">{{ status }}</p>
                                <p class="text-[11px] text-surface-500 mt-0.5" v-if="summary.total > 0">
                                    {{ Math.round(((summary.status_counts?.[status] || 0) / summary.total) * 100) }}%
                                </p>
                            </div>
                        </div>

                        <!-- Progress bar -->
                        <div v-if="summary.total > 0" class="mt-6">
                            <div class="h-2.5 rounded-full bg-surface-800 flex overflow-hidden">
                                <div v-for="status in statusOptions" :key="status"
                                    class="h-full transition-all duration-500"
                                    :class="{
                                        'bg-blue-400': status === 'Submitted',
                                        'bg-amber-400': status === 'Interviewing',
                                        'bg-emerald-400': status === 'Hired',
                                        'bg-red-400': status === 'Rejected',
                                    }"
                                    :style="{ width: ((summary.status_counts?.[status] || 0) / summary.total * 100) + '%' }">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top bidders -->
                <div class="card overflow-hidden">
                    <div class="px-6 py-4 border-b border-surface-700/30">
                        <h2 class="font-semibold text-surface-100">Top Performers</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-surface-700/30">
                                    <th class="px-6 py-3 text-left table-header">Rank</th>
                                    <th class="px-6 py-3 text-left table-header">Bidder</th>
                                    <th class="px-6 py-3 text-center table-header">Total Bids</th>
                                    <th class="px-6 py-3 text-center table-header">Today</th>
                                    <th class="px-6 py-3 text-center table-header">Hours Today</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm">
                                <tr v-for="(bidder, i) in [...bidders].sort((a, b) => b.total_bids - a.total_bids)" :key="bidder.id"
                                    class="border-b border-surface-800/30 hover:bg-surface-800/20 transition-colors">
                                    <td class="px-6 py-3">
                                        <span v-if="i === 0" class="text-amber-400 font-semibold">#1</span>
                                        <span v-else-if="i === 1" class="text-surface-400 font-semibold">#2</span>
                                        <span v-else-if="i === 2" class="text-orange-400 font-semibold">#3</span>
                                        <span v-else class="text-surface-500 font-medium">#{{ i + 1 }}</span>
                                    </td>
                                    <td class="px-6 py-3">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold"
                                                :class="getUserColor(bidder.name)">
                                                {{ bidder.name.charAt(0).toUpperCase() }}
                                            </div>
                                            <span class="text-surface-200 font-medium">{{ bidder.name }}</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3 text-center font-mono text-surface-300">{{ bidder.total_bids }}</td>
                                    <td class="px-6 py-3 text-center font-mono text-surface-300">{{ bidder.bids_today }}</td>
                                    <td class="px-6 py-3 text-center font-mono text-surface-300">{{ bidder.today_hours }}h</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>
</template>
