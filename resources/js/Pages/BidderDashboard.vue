<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PitchFlowLogo from '@/Components/PitchFlowLogo.vue';
import MessagingPanel from '@/Components/Messaging/MessagingPanel.vue';
import { useMessaging } from '@/composables/useMessaging';
import axios from 'axios';

const props = defineProps(['auth', 'impersonating']);

const { totalUnread, fetchUnreadCount, handleIncomingMessage, togglePanel } = useMessaging();

async function stopImpersonating() {
    try {
        const { data } = await axios.post('/api/admin/stop-impersonate');
        if (data.redirect) window.location.href = data.redirect;
    } catch (e) {}
}

// --- Punch Clock State ---
const isPunchedIn = ref(false);
const punchedInAt = ref(null);
const elapsedSeconds = ref(0);
const todayHours = ref(0);
let clockInterval = null;

const formattedElapsed = computed(() => {
    const h = Math.floor(elapsedSeconds.value / 3600);
    const m = Math.floor((elapsedSeconds.value % 3600) / 60);
    const s = elapsedSeconds.value % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
});

function startClock(startTime) {
    stopClock();
    const start = new Date(startTime);
    if (isNaN(start.getTime())) return;
    const tick = () => {
        const diff = Math.floor((Date.now() - start.getTime()) / 1000);
        elapsedSeconds.value = Math.max(0, diff);
    };
    tick();
    clockInterval = setInterval(tick, 1000);
}

function stopClock() {
    if (clockInterval) {
        clearInterval(clockInterval);
        clockInterval = null;
    }
}

async function fetchTimeStatus() {
    try {
        const { data } = await axios.get('/api/time/status');
        isPunchedIn.value = data.is_punched_in;
        todayHours.value = data.today_hours;
        if (data.is_punched_in && data.punched_in_at) {
            punchedInAt.value = data.punched_in_at;
            startClock(data.punched_in_at);
        }
    } catch (e) {}
}

async function togglePunch() {
    try {
        if (isPunchedIn.value) {
            await axios.post('/api/time/punch-out');
            isPunchedIn.value = false;
            punchedInAt.value = null;
            stopClock();
            elapsedSeconds.value = 0;
            fetchTimeStatus();
        } else {
            const { data } = await axios.post('/api/time/punch-in');
            isPunchedIn.value = true;
            punchedInAt.value = data.punched_in_at || data.log?.login_time;
            startClock(punchedInAt.value);
        }
    } catch (e) {
        console.error(e);
    }
}

// --- URL Checker ---
const url = ref('');
const checkStatus = ref(null);
const checkMessage = ref('');
const isChecking = ref(false);

async function checkUrl() {
    if (!url.value.trim()) return;
    isChecking.value = true;
    checkStatus.value = null;
    try {
        const { data } = await axios.post('/api/bids/check', { url: url.value });
        checkStatus.value = data.status;
        checkMessage.value = data.message;
    } catch (e) {
        checkStatus.value = 'error';
        checkMessage.value = e.response?.data?.message || 'Could not validate this URL';
    } finally {
        isChecking.value = false;
    }
}

// --- Submit Bid ---
const showSubmitForm = ref(false);
const submitForm = ref({ connects: 6, platform: 'Upwork', job_title: '' });
const isSubmitting = ref(false);

async function submitBid() {
    isSubmitting.value = true;
    try {
        await axios.post('/api/bids/submit', {
            url: url.value,
            connects: submitForm.value.connects,
            platform: submitForm.value.platform,
            job_title: submitForm.value.job_title,
        });
        url.value = '';
        checkStatus.value = null;
        showSubmitForm.value = false;
        submitForm.value = { connects: 6, platform: 'Upwork', job_title: '' };
        fetchBids();
    } catch (e) {
        console.error(e);
    } finally {
        isSubmitting.value = false;
    }
}

// --- Proposals List ---
const bids = ref([]);
const bidStats = ref({ total: 0, today: 0, this_week: 0, connects_today: 0 });
const bidsPagination = ref({});
const isLoadingBids = ref(false);
const activeFilter = ref('all');
const customFrom = ref('');
const customTo = ref('');
const statusFilter = ref('');

async function fetchBids(page = 1) {
    isLoadingBids.value = true;
    try {
        const params = { page };

        if (activeFilter.value === 'today') params.filter = 'today';
        else if (activeFilter.value === '7days') params.filter = '7days';
        else if (activeFilter.value === '30days') params.filter = '30days';
        else if (activeFilter.value === 'this_month') params.filter = 'this_month';
        else if (activeFilter.value === 'custom') {
            params.filter = 'custom';
            params.from = customFrom.value;
            params.to = customTo.value;
        }

        if (statusFilter.value) params.status = statusFilter.value;

        const { data } = await axios.get('/api/bids/mine', { params });
        bids.value = data.bids.data;
        bidsPagination.value = {
            current_page: data.bids.current_page,
            last_page: data.bids.last_page,
            total: data.bids.total,
        };
        bidStats.value = data.stats;
    } catch (e) {
        console.error(e);
    } finally {
        isLoadingBids.value = false;
    }
}

function applyFilter(filter) {
    activeFilter.value = filter;
    if (filter !== 'custom') {
        fetchBids();
    }
}

function applyCustomRange() {
    if (customFrom.value && customTo.value) {
        activeFilter.value = 'custom';
        fetchBids();
    }
}

function formatDate(dateStr) {
    const d = new Date(dateStr);
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatTime(dateStr) {
    if (!dateStr) return '--';
    const d = new Date(dateStr);
    return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

function toLocalTimeShort(isoStr) {
    if (!isoStr) return null;
    const d = new Date(isoStr);
    return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: true });
}

// --- Attendance ---
const attendanceDays = ref([]);
const attendanceSummary = ref({ total_worked_hours: 0, present_days: 0, absent_days: 0, avg_hours_per_day: 0 });
const attendanceMonth = ref(new Date().getMonth() + 1);
const attendanceYear = ref(new Date().getFullYear());
const attendanceMonthName = ref('');
const isLoadingAttendance = ref(false);
const expandedDay = ref(null);

async function fetchAttendance() {
    isLoadingAttendance.value = true;
    try {
        const { data } = await axios.get('/api/time/attendance', {
            params: { year: attendanceYear.value, month: attendanceMonth.value }
        });
        attendanceDays.value = data.days;
        attendanceSummary.value = data.summary;
        attendanceMonthName.value = data.month_name;
    } catch (e) {
        console.error(e);
    } finally {
        isLoadingAttendance.value = false;
    }
}

function prevMonth() {
    if (attendanceMonth.value === 1) {
        attendanceMonth.value = 12;
        attendanceYear.value--;
    } else {
        attendanceMonth.value--;
    }
    expandedDay.value = null;
    fetchAttendance();
}

function nextMonth() {
    const now = new Date();
    const currentYM = now.getFullYear() * 12 + now.getMonth() + 1;
    const targetYM = attendanceYear.value * 12 + attendanceMonth.value + 1;
    if (targetYM > currentYM) return;
    if (attendanceMonth.value === 12) {
        attendanceMonth.value = 1;
        attendanceYear.value++;
    } else {
        attendanceMonth.value++;
    }
    expandedDay.value = null;
    fetchAttendance();
}

const isCurrentMonth = computed(() => {
    const now = new Date();
    return attendanceYear.value === now.getFullYear() && attendanceMonth.value === now.getMonth() + 1;
});

function toggleDayDetail(dateKey) {
    expandedDay.value = expandedDay.value === dateKey ? null : dateKey;
}

function formatHours(h) {
    if (!h || h === 0) return '0h 0m';
    const val = Math.abs(h);
    const hours = Math.floor(val);
    const mins = Math.round((val - hours) * 60);
    if (hours === 0) return `${mins}m`;
    if (mins === 0) return `${hours}h`;
    return `${hours}h ${mins}m`;
}

// --- Pipeline (Senior BDE) ---
const isSeniorBDE = computed(() => props.auth?.user?.designation === 'Senior BDE');
const pipelineBids = ref([]);
const pipelineSummary = ref({ total: 0, conversion_rate: 0, status_counts: {} });
const pipelineLoading = ref(false);
const pipelineStatusOptions = ['Submitted', 'Interviewing', 'Hired', 'Rejected'];
const pipelineStatusColors = {
    Submitted: { dot: 'bg-blue-400', border: 'border-l-blue-400/50', badge: 'bg-blue-500/10 text-blue-400' },
    Interviewing: { dot: 'bg-amber-400', border: 'border-l-amber-400/50', badge: 'bg-amber-500/10 text-amber-400' },
    Hired: { dot: 'bg-emerald-400', border: 'border-l-emerald-400/50', badge: 'bg-emerald-500/10 text-emerald-400' },
    Rejected: { dot: 'bg-red-400', border: 'border-l-red-400/50', badge: 'bg-red-500/10 text-red-400' },
};
const pipelineColumns = computed(() => {
    const cols = {};
    for (const s of pipelineStatusOptions) cols[s] = pipelineBids.value.filter(b => b.status === s);
    return cols;
});
const pDragBidId = ref(null);
const pDragOverStatus = ref(null);
const pStatusMenuOpen = ref(null);

async function fetchPipeline() {
    pipelineLoading.value = true;
    try {
        const { data } = await axios.get('/api/pipeline/bids');
        pipelineBids.value = data.bids;
        pipelineSummary.value = data.summary;
    } catch (e) {} finally { pipelineLoading.value = false; }
}

async function updatePipelineStatus(bidId, status) {
    try {
        await axios.put(`/api/pipeline/bids/${bidId}/status`, { status });
        pStatusMenuOpen.value = null;
        await fetchPipeline();
    } catch (e) {}
}

function pOnDragStart(e, bidId) { pDragBidId.value = bidId; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', bidId); e.target.style.opacity = '0.5'; }
function pOnDragEnd(e) { e.target.style.opacity = '1'; pDragBidId.value = null; pDragOverStatus.value = null; }
function pOnDragOver(e, status) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; pDragOverStatus.value = status; }
function pOnDragLeave(e, status) { if (!e.currentTarget.contains(e.relatedTarget) && pDragOverStatus.value === status) pDragOverStatus.value = null; }
async function pOnDrop(e, targetStatus) {
    e.preventDefault(); pDragOverStatus.value = null;
    const bidId = parseInt(e.dataTransfer.getData('text/plain'));
    const bid = pipelineBids.value.find(b => b.bid_id === bidId);
    if (bid && bid.status !== targetStatus) { bid.status = targetStatus; await updatePipelineStatus(bidId, targetStatus); }
    pDragBidId.value = null;
}

function truncateUrl(url, max = 35) {
    if (!url) return '';
    try { const u = new URL(url); const p = u.pathname.length > max ? u.pathname.substring(0, max) + '...' : u.pathname; return u.hostname + p; }
    catch { return url.length > max ? url.substring(0, max) + '...' : url; }
}

// --- Tab navigation ---
const activeTab = ref('checker');

function switchTab(tab) {
    activeTab.value = tab;
    if (tab === 'attendance' && attendanceDays.value.length === 0) {
        fetchAttendance();
    }
    if (tab === 'pipeline' && pipelineBids.value.length === 0) {
        fetchPipeline();
    }
}

// --- Lifecycle ---
onMounted(() => {
    fetchTimeStatus();
    fetchBids();
    fetchUnreadCount();
    if (props.auth.user?.id && window.Echo) {
        window.Echo.private(`messages.${props.auth.user.id}`).listen('.message.sent', handleIncomingMessage);
    }
});

onUnmounted(() => {
    stopClock();
    if (props.auth.user?.id && window.Echo) {
        window.Echo.leaveChannel(`private-messages.${props.auth.user.id}`);
    }
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="min-h-screen bg-surface-950 text-surface-100 flex flex-col">
        <!-- Impersonation Banner -->
        <div v-if="impersonating" class="bg-amber-500/10 border-b border-amber-500/30 px-4 py-2 flex items-center justify-center gap-3 sticky top-0 z-[60]">
            <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            </svg>
            <span class="text-sm text-amber-300">
                You are viewing as <strong class="text-amber-200">{{ auth.user.name }}</strong>
                <span class="text-amber-400/70 ml-1">(impersonated by {{ impersonating.admin_name }})</span>
            </span>
            <button @click="stopImpersonating"
                class="ml-2 px-3 py-1 text-xs font-medium rounded-lg bg-amber-500/20 text-amber-300 hover:bg-amber-500/30 transition-colors">
                Stop &amp; Return to Admin
            </button>
        </div>

        <!-- Top nav -->
        <nav class="sticky top-0 z-50 bg-surface-950/80 backdrop-blur-xl border-b border-surface-800/50">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <PitchFlowLogo size="w-7 h-7" />
                    <span class="text-sm font-bold hidden sm:block">PitchFlow</span>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Punch clock in nav -->
                    <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-lg border border-surface-700/40 bg-surface-800/40">
                        <span class="w-2 h-2 rounded-full" :class="isPunchedIn ? 'bg-emerald-400 animate-pulse-soft' : 'bg-surface-600'"></span>
                        <span class="text-xs font-mono font-medium" :class="isPunchedIn ? 'text-emerald-400' : 'text-surface-500'">
                            {{ isPunchedIn ? formattedElapsed : 'Off shift' }}
                        </span>
                    </div>

                    <button
                        @click="togglePunch"
                        class="px-4 py-2 rounded-lg text-xs font-semibold transition-all duration-200"
                        :class="isPunchedIn
                            ? 'bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/15'
                            : 'bg-brand text-surface-950 hover:bg-brand-light'"
                    >
                        {{ isPunchedIn ? 'Punch Out' : 'Punch In' }}
                    </button>

                    <button @click="togglePanel" class="relative p-2 rounded-lg text-surface-400 hover:text-surface-100 hover:bg-surface-800/50 transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>
                        </svg>
                        <span v-if="totalUnread > 0" class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] rounded-full bg-brand flex items-center justify-center px-1">
                            <span class="text-[9px] font-bold text-surface-950 leading-none">{{ totalUnread > 99 ? '99+' : totalUnread }}</span>
                        </span>
                    </button>
                    <span class="text-sm text-surface-400 hidden md:block">{{ auth.user.name }}</span>
                    <button @click="router.post('/logout')" class="btn-ghost text-xs">Sign out</button>
                </div>
            </div>
        </nav>

        <!-- Main content -->
        <main class="flex-1 max-w-6xl mx-auto w-full px-4 sm:px-6 py-6">
            <!-- Stats row -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Today's Bids</span>
                    <span class="text-xl font-semibold text-surface-100 mt-1">{{ bidStats.today }}</span>
                </div>
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">This Week</span>
                    <span class="text-xl font-semibold text-surface-100 mt-1">{{ bidStats.this_week }}</span>
                </div>
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Connects Today</span>
                    <span class="text-xl font-semibold text-surface-100 mt-1">{{ bidStats.connects_today }}</span>
                </div>
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Hours Today</span>
                    <span class="text-xl font-semibold text-surface-100 mt-1 font-mono">{{ Math.abs(todayHours).toFixed(1) }}h</span>
                </div>
            </div>

            <!-- Tab navigation -->
            <div class="flex items-center gap-1 mb-6 border-b border-surface-800/50 pb-px">
                <button
                    @click="switchTab('checker')"
                    class="px-4 py-2.5 text-sm font-medium rounded-t-lg transition-colors relative"
                    :class="activeTab === 'checker' ? 'text-surface-100 bg-surface-800/50' : 'text-surface-400 hover:text-surface-300'"
                >
                    URL Checker
                    <div v-if="activeTab === 'checker'" class="absolute bottom-0 left-0 right-0 h-0.5 bg-brand rounded-full"></div>
                </button>
                <button
                    @click="switchTab('proposals')"
                    class="px-4 py-2.5 text-sm font-medium rounded-t-lg transition-colors relative"
                    :class="activeTab === 'proposals' ? 'text-surface-100 bg-surface-800/50' : 'text-surface-400 hover:text-surface-300'"
                >
                    My Proposals
                    <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-md bg-surface-700/50 text-surface-400">{{ bidStats.total }}</span>
                    <div v-if="activeTab === 'proposals'" class="absolute bottom-0 left-0 right-0 h-0.5 bg-brand rounded-full"></div>
                </button>
                <button
                    @click="switchTab('attendance')"
                    class="px-4 py-2.5 text-sm font-medium rounded-t-lg transition-colors relative"
                    :class="activeTab === 'attendance' ? 'text-surface-100 bg-surface-800/50' : 'text-surface-400 hover:text-surface-300'"
                >
                    Attendance
                    <div v-if="activeTab === 'attendance'" class="absolute bottom-0 left-0 right-0 h-0.5 bg-brand rounded-full"></div>
                </button>
                <button v-if="isSeniorBDE"
                    @click="switchTab('pipeline')"
                    class="px-4 py-2.5 text-sm font-medium rounded-t-lg transition-colors relative"
                    :class="activeTab === 'pipeline' ? 'text-surface-100 bg-surface-800/50' : 'text-surface-400 hover:text-surface-300'"
                >
                    Pipeline
                    <div v-if="activeTab === 'pipeline'" class="absolute bottom-0 left-0 right-0 h-0.5 bg-brand rounded-full"></div>
                </button>
            </div>

            <!-- Tab: URL Checker -->
            <div v-show="activeTab === 'checker'">
                <div class="card p-6 sm:p-8 max-w-2xl">
                    <h2 class="text-lg font-semibold text-surface-100 mb-1">Check Job Availability</h2>
                    <p class="text-sm text-surface-400 mb-6">Paste a job URL to verify no one on your team has already bid.</p>

                    <div class="space-y-4">
                        <div class="relative">
                            <input
                                type="url"
                                v-model="url"
                                @keyup.enter="checkUrl"
                                placeholder="https://www.upwork.com/jobs/..."
                                class="input-field py-3.5 pr-10"
                            />
                            <svg class="w-4 h-4 text-surface-500 absolute right-4 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                            </svg>
                        </div>

                        <button
                            @click="checkUrl"
                            :disabled="isChecking || !url.trim()"
                            class="btn-primary w-full py-3 text-sm"
                            :class="{ 'opacity-50 pointer-events-none': isChecking || !url.trim() }"
                        >
                            <svg v-if="!isChecking" class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                            <svg v-else class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            {{ isChecking ? 'Checking...' : 'Check Availability' }}
                        </button>
                    </div>

                    <!-- Result -->
                    <div v-if="checkStatus" class="mt-5">
                        <div
                            class="flex items-start gap-3 p-4 rounded-xl border"
                            :class="{
                                'bg-emerald-500/5 border-emerald-500/20': checkStatus === 'clear',
                                'bg-red-500/5 border-red-500/20': checkStatus === 'collision',
                                'bg-amber-500/5 border-amber-500/20': checkStatus === 'error',
                            }"
                        >
                            <svg v-if="checkStatus === 'clear'" class="w-5 h-5 text-emerald-400 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <svg v-else-if="checkStatus === 'collision'" class="w-5 h-5 text-red-400 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                            </svg>
                            <svg v-else class="w-5 h-5 text-amber-400 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                            <div>
                                <p class="text-sm font-medium" :class="{
                                    'text-emerald-300': checkStatus === 'clear',
                                    'text-red-300': checkStatus === 'collision',
                                    'text-amber-300': checkStatus === 'error',
                                }">{{ checkMessage }}</p>
                                <p v-if="checkStatus === 'clear'" class="text-xs text-surface-500 mt-1">You're clear to submit your proposal.</p>
                            </div>
                        </div>

                        <!-- Submit form (shows when clear) -->
                        <div v-if="checkStatus === 'clear'" class="mt-4">
                            <button v-if="!showSubmitForm" @click="showSubmitForm = true" class="btn-primary text-sm w-full py-3">
                                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                Record This Bid
                            </button>

                            <div v-else class="card p-5 space-y-4">
                                <div>
                                    <label class="block text-xs font-medium text-surface-400 mb-1.5">Job Title (optional)</label>
                                    <input type="text" v-model="submitForm.job_title" placeholder="e.g. Senior Laravel Dev for SaaS" class="input-field text-sm" />
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-surface-400 mb-1.5">Platform</label>
                                        <select v-model="submitForm.platform" class="input-field text-sm">
                                            <option>Upwork</option>
                                            <option>Freelancer</option>
                                            <option>LinkedIn</option>
                                            <option>Other</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-surface-400 mb-1.5">Connects Used</label>
                                        <input type="number" v-model="submitForm.connects" min="1" class="input-field text-sm" />
                                    </div>
                                </div>
                                <div class="flex gap-3">
                                    <button @click="submitBid" :disabled="isSubmitting" class="btn-primary text-sm flex-1 py-2.5" :class="{ 'opacity-50': isSubmitting }">
                                        {{ isSubmitting ? 'Saving...' : 'Save Proposal' }}
                                    </button>
                                    <button @click="showSubmitForm = false" class="btn-secondary text-sm px-4">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Proposals -->
            <div v-show="activeTab === 'proposals'">
                <!-- Filters -->
                <div class="card p-4 mb-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-medium text-surface-400 mr-1">Filter:</span>
                        <button
                            v-for="f in [{key:'all',label:'All'},{key:'today',label:'Today'},{key:'7days',label:'7 Days'},{key:'30days',label:'30 Days'},{key:'this_month',label:'This Month'}]"
                            :key="f.key"
                            @click="applyFilter(f.key)"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium transition-all"
                            :class="activeFilter === f.key ? 'bg-brand/15 text-brand border border-brand/30' : 'bg-surface-800/50 text-surface-400 border border-surface-700/30 hover:text-surface-200'"
                        >
                            {{ f.label }}
                        </button>

                        <div class="flex items-center gap-2 ml-auto">
                            <input type="date" v-model="customFrom" class="input-field text-xs py-1.5 px-2.5 w-32" />
                            <span class="text-xs text-surface-500">to</span>
                            <input type="date" v-model="customTo" class="input-field text-xs py-1.5 px-2.5 w-32" />
                            <button @click="applyCustomRange" class="btn-secondary text-xs px-3 py-1.5">Apply</button>
                        </div>
                    </div>

                    <!-- Status filter row -->
                    <div class="flex items-center gap-2 mt-3 pt-3 border-t border-surface-700/30">
                        <span class="text-xs font-medium text-surface-400 mr-1">Status:</span>
                        <button
                            v-for="s in [{key:'',label:'All'},{key:'Submitted',label:'Submitted'},{key:'Interviewing',label:'Interviewing'},{key:'Hired',label:'Hired'},{key:'Rejected',label:'Rejected'}]"
                            :key="s.key"
                            @click="statusFilter = s.key; fetchBids()"
                            class="px-2.5 py-1 rounded-md text-xs font-medium transition-all"
                            :class="statusFilter === s.key ? 'bg-surface-700 text-surface-100' : 'text-surface-500 hover:text-surface-300'"
                        >
                            {{ s.label }}
                        </button>
                    </div>
                </div>

                <!-- Proposals table -->
                <div class="card overflow-hidden">
                    <!-- Loading state -->
                    <div v-if="isLoadingBids" class="p-8 text-center">
                        <svg class="animate-spin w-6 h-6 text-surface-500 mx-auto" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <p class="text-sm text-surface-500 mt-2">Loading proposals...</p>
                    </div>

                    <!-- Empty state -->
                    <div v-else-if="bids.length === 0" class="p-12 text-center">
                        <svg class="w-12 h-12 text-surface-700 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                        </svg>
                        <p class="text-sm text-surface-400">No proposals found for this filter.</p>
                        <p class="text-xs text-surface-600 mt-1">Submit your first bid using the URL Checker tab.</p>
                    </div>

                    <!-- Table -->
                    <div v-else class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-surface-700/30">
                                    <th class="px-5 py-3 text-left table-header">Job</th>
                                    <th class="px-5 py-3 text-left table-header">Platform</th>
                                    <th class="px-5 py-3 text-left table-header">Connects</th>
                                    <th class="px-5 py-3 text-left table-header">Status</th>
                                    <th class="px-5 py-3 text-left table-header">Date</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm">
                                <tr v-for="bid in bids" :key="bid.bid_id" class="border-b border-surface-800/30 hover:bg-surface-800/20 transition-colors">
                                    <td class="px-5 py-3.5">
                                        <div class="max-w-xs">
                                            <p class="font-medium text-surface-200 truncate">{{ bid.job_title || 'Untitled Job' }}</p>
                                            <a :href="bid.job_url" target="_blank" class="text-xs text-surface-500 hover:text-brand truncate block mt-0.5 transition-colors">
                                                {{ bid.job_url.substring(0, 50) }}...
                                            </a>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <span class="badge-neutral">{{ bid.platform_name }}</span>
                                    </td>
                                    <td class="px-5 py-3.5 font-mono text-surface-300">{{ bid.connects_used }}</td>
                                    <td class="px-5 py-3.5">
                                        <span :class="{
                                            'badge-success': bid.status === 'Hired',
                                            'badge-warning': bid.status === 'Interviewing',
                                            'badge-neutral': bid.status === 'Submitted',
                                            'badge-danger': bid.status === 'Rejected',
                                        }">{{ bid.status }}</span>
                                    </td>
                                    <td class="px-5 py-3.5 text-surface-400 text-xs whitespace-nowrap">
                                        <div>{{ formatDate(bid.created_at) }}</div>
                                        <div class="text-surface-600">{{ formatTime(bid.created_at) }}</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="bidsPagination.last_page > 1" class="px-5 py-3 border-t border-surface-700/30 flex items-center justify-between">
                        <span class="text-xs text-surface-500">
                            Page {{ bidsPagination.current_page }} of {{ bidsPagination.last_page }} ({{ bidsPagination.total }} total)
                        </span>
                        <div class="flex gap-1">
                            <button
                                @click="fetchBids(bidsPagination.current_page - 1)"
                                :disabled="bidsPagination.current_page <= 1"
                                class="btn-ghost text-xs px-3 py-1.5"
                                :class="{ 'opacity-30 pointer-events-none': bidsPagination.current_page <= 1 }"
                            >Previous</button>
                            <button
                                @click="fetchBids(bidsPagination.current_page + 1)"
                                :disabled="bidsPagination.current_page >= bidsPagination.last_page"
                                class="btn-ghost text-xs px-3 py-1.5"
                                :class="{ 'opacity-30 pointer-events-none': bidsPagination.current_page >= bidsPagination.last_page }"
                            >Next</button>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Tab: Attendance -->
            <div v-show="activeTab === 'attendance'">
                <!-- Month navigation + summary -->
                <div class="card p-4 mb-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <button @click="prevMonth" class="btn-ghost p-2">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>
                            <h2 class="text-base font-semibold text-surface-100 min-w-[160px] text-center">
                                {{ attendanceMonthName }} {{ attendanceYear }}
                            </h2>
                            <button @click="nextMonth" class="btn-ghost p-2" :class="{ 'opacity-30 pointer-events-none': isCurrentMonth }">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                        </div>

                        <div class="hidden sm:flex items-center gap-5 text-xs">
                            <div class="flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-sm bg-brand/30"></span>
                                <span class="text-surface-400">Present</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-sm bg-red-500/40"></span>
                                <span class="text-surface-400">Absent</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-sm bg-surface-700/50"></span>
                                <span class="text-surface-400">Weekend</span>
                            </div>
                        </div>
                    </div>

                    <!-- Summary stats -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 pt-4 border-t border-surface-700/30">
                        <div>
                            <span class="text-xs text-surface-500">Days Present</span>
                            <p class="text-lg font-semibold text-brand mt-0.5">{{ attendanceSummary.present_days }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-surface-500">Days Absent</span>
                            <p class="text-lg font-semibold text-red-400 mt-0.5">{{ attendanceSummary.absent_days }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-surface-500">Total Hours</span>
                            <p class="text-lg font-semibold text-surface-100 mt-0.5">{{ formatHours(attendanceSummary.total_worked_hours) }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-surface-500">Avg / Day</span>
                            <p class="text-lg font-semibold text-surface-100 mt-0.5">{{ formatHours(attendanceSummary.avg_hours_per_day) }}</p>
                        </div>
                    </div>
                </div>

                <!-- Loading -->
                <div v-if="isLoadingAttendance" class="card p-8 text-center">
                    <svg class="animate-spin w-6 h-6 text-surface-500 mx-auto" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>

                <!-- Attendance sheet -->
                <div v-else class="card overflow-hidden">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-surface-700/30">
                                <th class="px-4 py-3 text-left table-header w-12">Day</th>
                                <th class="px-4 py-3 text-left table-header">Date</th>
                                <th class="px-4 py-3 text-left table-header">Status</th>
                                <th class="px-4 py-3 text-left table-header">Hours Worked</th>
                                <th class="px-4 py-3 text-left table-header hidden sm:table-cell">Sessions</th>
                                <th class="px-4 py-3 text-right table-header w-10"></th>
                            </tr>
                        </thead>
                        <tbody class="text-sm">
                            <template v-for="day in attendanceDays" :key="day.date">
                                <tr
                                    class="border-b border-surface-800/20 transition-colors cursor-pointer"
                                    :class="{
                                        'bg-red-500/[0.04] hover:bg-red-500/[0.08]': day.status === 'absent',
                                        'hover:bg-surface-800/20': day.status !== 'absent',
                                        'opacity-40': day.status === 'future' || day.status === 'na',
                                    }"
                                    @click="day.sessions.length > 0 ? toggleDayDetail(day.date) : null"
                                >
                                    <td class="px-4 py-3 font-mono text-xs text-surface-500">{{ String(day.day).padStart(2, '0') }}</td>
                                    <td class="px-4 py-3">
                                        <span class="text-surface-200">{{ day.day_name }}</span>
                                        <span class="text-surface-600 ml-1.5 text-xs">{{ day.date }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span v-if="day.status === 'present'" class="badge-success">Present</span>
                                        <span v-else-if="day.status === 'absent'" class="badge-danger">Absent</span>
                                        <span v-else-if="day.status === 'weekend'" class="badge-neutral">Weekend</span>
                                        <span v-else-if="day.status === 'future'" class="text-xs text-surface-600">--</span>
                                        <span v-else class="text-xs text-surface-600">--</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div v-if="day.status === 'present'" class="flex items-center gap-2">
                                            <div class="flex-1 max-w-[120px] h-1.5 rounded-full bg-surface-700/50 overflow-hidden">
                                                <div
                                                    class="h-full rounded-full transition-all duration-300"
                                                    :class="day.hours >= 8 ? 'bg-brand' : day.hours >= 4 ? 'bg-amber-400' : 'bg-red-400'"
                                                    :style="{ width: Math.min(day.hours / 8 * 100, 100) + '%' }"
                                                ></div>
                                            </div>
                                            <span class="font-mono text-xs font-medium" :class="day.hours >= 8 ? 'text-brand' : day.hours >= 4 ? 'text-amber-400' : 'text-red-400'">
                                                {{ formatHours(day.hours) }}
                                            </span>
                                        </div>
                                        <span v-else-if="day.status === 'absent'" class="text-xs text-red-400/60">0h</span>
                                        <span v-else class="text-xs text-surface-600">--</span>
                                    </td>
                                    <td class="px-4 py-3 text-surface-500 text-xs hidden sm:table-cell">
                                        <span v-if="day.sessions.length > 0">{{ day.sessions.length }} session{{ day.sessions.length > 1 ? 's' : '' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <svg v-if="day.sessions.length > 0" class="w-4 h-4 text-surface-600 inline-block transition-transform" :class="{ 'rotate-180': expandedDay === day.date }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </td>
                                </tr>

                                <!-- Expanded session detail -->
                                <tr v-if="expandedDay === day.date && day.sessions.length > 0">
                                    <td colspan="6" class="px-4 py-0">
                                        <div class="py-3 pl-10 space-y-2 border-b border-surface-800/20">
                                            <div v-for="(session, i) in day.sessions" :key="i" class="flex items-center gap-4 text-xs">
                                                <span class="text-surface-500 w-4">{{ i + 1 }}.</span>
                                                <span class="font-mono text-surface-300">
                                                    {{ toLocalTimeShort(session.in) || '--' }}
                                                </span>
                                                <svg class="w-3 h-3 text-surface-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                                </svg>
                                                <span class="font-mono" :class="session.out ? 'text-surface-300' : 'text-emerald-400'">
                                                    {{ session.out ? toLocalTimeShort(session.out) : 'Active now' }}
                                                </span>
                                                <span class="text-surface-500 ml-auto">{{ formatHours(session.hours) }}</span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab: Pipeline (Senior BDE only) -->
            <div v-if="isSeniorBDE" v-show="activeTab === 'pipeline'" @click="pStatusMenuOpen = null">
                <div v-if="pipelineLoading" class="flex items-center justify-center py-20">
                    <div class="w-7 h-7 border-2 border-brand/30 border-t-brand rounded-full animate-spin"></div>
                </div>

                <div v-else-if="pipelineBids.length === 0" class="text-center py-16">
                    <p class="text-surface-400 font-medium">No bids in the pipeline yet</p>
                </div>

                <div v-else>
                    <!-- Pipeline stats -->
                    <div class="flex items-center gap-4 mb-5">
                        <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-surface-800/50 border border-surface-700/30">
                            <span class="text-xs text-surface-400">Total</span>
                            <span class="text-sm font-semibold text-surface-200">{{ pipelineSummary.total }}</span>
                        </div>
                        <div v-if="pipelineSummary.conversion_rate > 0" class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-surface-800/50 border border-surface-700/30">
                            <span class="text-xs text-surface-400">Conversion</span>
                            <span class="text-sm font-semibold text-brand">{{ pipelineSummary.conversion_rate }}%</span>
                        </div>
                    </div>

                    <!-- Kanban board -->
                    <div class="grid grid-cols-4 gap-3 min-h-[450px]">
                        <div v-for="status in pipelineStatusOptions" :key="status"
                            class="flex flex-col rounded-2xl bg-surface-900/50 border-2 min-w-0 transition-colors duration-200"
                            :class="pDragOverStatus === status ? 'border-brand/50 bg-brand/5' : 'border-surface-800/40'"
                            @dragover="pOnDragOver($event, status)"
                            @dragleave="pOnDragLeave($event, status)"
                            @drop="pOnDrop($event, status)">
                            <div class="px-3 py-2.5 border-b border-surface-800/30 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="w-2 h-2 rounded-full" :class="pipelineStatusColors[status]?.dot"></div>
                                    <h3 class="text-xs font-semibold text-surface-200">{{ status }}</h3>
                                </div>
                                <span class="text-[10px] font-medium text-surface-500 bg-surface-800/50 px-1.5 py-0.5 rounded">
                                    {{ pipelineColumns[status]?.length || 0 }}
                                </span>
                            </div>
                            <div class="flex-1 p-2.5 space-y-2 overflow-y-auto scrollbar-thin">
                                <div v-for="bid in pipelineColumns[status]" :key="bid.bid_id"
                                    draggable="true"
                                    @dragstart="pOnDragStart($event, bid.bid_id)"
                                    @dragend="pOnDragEnd"
                                    class="card card-hover p-3 border-l-2 overflow-hidden cursor-grab active:cursor-grabbing select-none"
                                    :class="[pipelineStatusColors[status]?.border, pDragBidId === bid.bid_id ? 'opacity-50 scale-95' : '']">
                                    <p class="text-xs font-medium text-surface-200 mb-1 truncate">
                                        {{ bid.job_title || truncateUrl(bid.job_url) }}
                                    </p>
                                    <div class="flex items-center gap-1.5 mb-2">
                                        <span class="text-[10px] text-surface-500">{{ bid.platform_name }}</span>
                                        <span class="text-surface-700">&middot;</span>
                                        <span class="text-[10px] text-surface-500">{{ bid.connects_used }}c</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <div v-if="bid.user" class="flex items-center gap-1.5">
                                            <div class="w-4 h-4 rounded-full bg-surface-700 flex items-center justify-center text-[8px] font-bold text-surface-400">
                                                {{ bid.user.name.charAt(0).toUpperCase() }}
                                            </div>
                                            <span class="text-[10px] text-surface-500">{{ bid.user.name.split(' ')[0] }}</span>
                                        </div>
                                        <div class="relative" @click.stop>
                                            <button @click="pStatusMenuOpen = pStatusMenuOpen === bid.bid_id ? null : bid.bid_id"
                                                class="text-[10px] font-medium px-1.5 py-0.5 rounded cursor-pointer"
                                                :class="pipelineStatusColors[status]?.badge">
                                                {{ status }}
                                            </button>
                                            <div v-if="pStatusMenuOpen === bid.bid_id"
                                                class="absolute right-0 bottom-full mb-1 w-28 bg-surface-800 border border-surface-700/50 rounded-lg shadow-xl z-20 py-1">
                                                <button v-for="s in pipelineStatusOptions.filter(x => x !== status)" :key="s"
                                                    @click="updatePipelineStatus(bid.bid_id, s)"
                                                    class="w-full text-left px-2.5 py-1 text-[11px] text-surface-300 hover:bg-surface-700/50 transition-colors flex items-center gap-1.5">
                                                    <div class="w-1.5 h-1.5 rounded-full" :class="pipelineStatusColors[s]?.dot"></div>
                                                    {{ s }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="!pipelineColumns[status]?.length" class="flex items-center justify-center h-24 text-[11px] text-surface-600">
                                    No bids
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        <MessagingPanel />
    </div>
</template>
