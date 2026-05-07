<script setup>
import { Head, router } from '@inertiajs/vue3';
import { onMounted, ref, computed } from 'vue';
import axios from 'axios';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';

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

const dragBidId = ref(null);
const dragOverStatus = ref(null);

function onDragStart(e, bidId) {
    dragBidId.value = bidId;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', bidId);
    e.target.style.opacity = '0.5';
}

function onDragEnd(e) {
    e.target.style.opacity = '1';
    dragBidId.value = null;
    dragOverStatus.value = null;
}

function onDragOver(e, status) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    dragOverStatus.value = status;
}

function onDragLeave(e, status) {
    if (!e.currentTarget.contains(e.relatedTarget)) {
        if (dragOverStatus.value === status) dragOverStatus.value = null;
    }
}

async function onDrop(e, targetStatus) {
    e.preventDefault();
    dragOverStatus.value = null;
    const bidId = parseInt(e.dataTransfer.getData('text/plain'));
    const bid = bids.value.find(b => b.bid_id === bidId);
    if (bid && bid.status !== targetStatus) {
        bid.status = targetStatus;
        await updateBidStatus(bidId, targetStatus);
    }
    dragBidId.value = null;
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

// --- Add Member ---
const showAddMember = ref(false);
const addMemberForm = ref({
    name: '', email: '', password: '', designation: 'BDE Bidder',
    joining_date: new Date().toISOString().split('T')[0],
    salary: '', min_hours_per_day: '8',
});
const addMemberErrors = ref({});
const addMemberError = ref('');
const addMemberLoading = ref(false);
const designations = ['Intern BDE', 'BDE Bidder', 'Senior BDE'];

const designationBadge = {
    'Intern BDE': 'bg-sky-500/10 text-sky-400',
    'BDE Bidder': 'bg-violet-500/10 text-violet-400',
    'Senior BDE': 'bg-amber-500/10 text-amber-400',
};

function resetMemberForm() {
    addMemberForm.value = {
        name: '', email: '', password: '', designation: 'BDE Bidder',
        joining_date: new Date().toISOString().split('T')[0],
        salary: '', min_hours_per_day: '8',
    };
    addMemberErrors.value = {};
    addMemberError.value = '';
}

function validateMemberForm() {
    const errors = {};
    const f = addMemberForm.value;
    if (!f.name || !f.name.trim()) errors.name = 'Name is required';
    if (!f.email || !f.email.trim()) errors.email = 'Email is required';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email)) errors.email = 'Invalid email format';
    if (!f.password || f.password.length < 6) errors.password = 'Minimum 6 characters';
    if (!f.joining_date) errors.joining_date = 'Joining date is required';
    const salaryNum = Number(f.salary);
    if (isNaN(salaryNum) || salaryNum <= 0) errors.salary = 'Salary must be greater than 0';
    const hoursNum = Number(f.min_hours_per_day);
    if (isNaN(hoursNum) || hoursNum < 1 || hoursNum > 24) errors.min_hours_per_day = 'Must be between 1-24 hours';
    addMemberErrors.value = errors;
    return Object.keys(errors).length === 0;
}

async function addMember() {
    if (!validateMemberForm()) return;
    addMemberError.value = '';
    addMemberLoading.value = true;
    try {
        await axios.post('/api/admin/bidders', {
            ...addMemberForm.value,
            salary: Number(addMemberForm.value.salary),
            min_hours_per_day: Number(addMemberForm.value.min_hours_per_day),
        });
        showAddMember.value = false;
        resetMemberForm();
        await fetchTeamData();
    } catch (e) {
        if (e.response?.data?.errors) {
            const serverErrors = e.response.data.errors;
            for (const key in serverErrors) {
                addMemberErrors.value[key] = serverErrors[key][0];
            }
        } else {
            addMemberError.value = e.response?.data?.message || 'Failed to add member.';
        }
    } finally {
        addMemberLoading.value = false;
    }
}

async function updateMemberDesignation(id, designation) {
    try {
        await axios.put(`/api/admin/bidders/${id}`, { designation });
        await fetchTeamData();
    } catch (e) {}
}

async function toggleMemberActive(id, isActive) {
    try {
        await axios.put(`/api/admin/bidders/${id}`, { is_active: !isActive });
        await fetchTeamData();
    } catch (e) {}
}

function formatSalary(amount) {
    if (!amount) return '-';
    return '₹' + Number(amount).toLocaleString('en-IN');
}

async function impersonateUser(id) {
    try {
        const { data } = await axios.post(`/api/admin/impersonate/${id}`);
        if (data.redirect) window.location.href = data.redirect;
    } catch (e) {}
}

// --- Edit Member ---
const editMember = ref(null);
const editForm = ref({});
const editErrors = ref({});
const editLoading = ref(false);

function openEditMember(bidder) {
    editMember.value = bidder;
    editForm.value = {
        designation: bidder.designation || 'BDE Bidder',
        salary: bidder.salary || '',
        min_hours_per_day: bidder.min_hours_per_day || '8',
    };
    editErrors.value = {};
}

function closeEditMember() {
    editMember.value = null;
    editForm.value = {};
    editErrors.value = {};
}

async function saveEditMember() {
    const errors = {};
    const salaryNum = Number(editForm.value.salary);
    if (isNaN(salaryNum) || salaryNum <= 0) errors.salary = 'Salary must be > 0';
    const hoursNum = Number(editForm.value.min_hours_per_day);
    if (isNaN(hoursNum) || hoursNum < 1 || hoursNum > 24) errors.min_hours_per_day = '1-24 hours';
    editErrors.value = errors;
    if (Object.keys(errors).length > 0) return;

    editLoading.value = true;
    try {
        await axios.put(`/api/admin/bidders/${editMember.value.id}`, {
            designation: editForm.value.designation,
            salary: Number(editForm.value.salary),
            min_hours_per_day: Number(editForm.value.min_hours_per_day),
        });
        closeEditMember();
        await fetchTeamData();
    } catch (e) {} finally { editLoading.value = false; }
}

// --- Member Profile & Attendance ---
const viewMember = ref(null);
const memberAttendance = ref(null);
const memberAttLoading = ref(false);
const attYear = ref(new Date().getFullYear());
const attMonth = ref(new Date().getMonth() + 1);
const dayEditOpen = ref(null);
const dayEditForm = ref({ status: 'present', manual_hours: '', note: '' });
const dayEditLoading = ref(false);

const statusLabels = { present: 'Present', absent: 'Absent', week_off: 'Week Off', half_day: 'Half Day', weekend: 'Weekend', future: 'Future', na: 'N/A' };
const statusDotColors = { present: 'bg-emerald-400', absent: 'bg-red-400', week_off: 'bg-violet-400', half_day: 'bg-amber-400', weekend: 'bg-surface-600', future: 'bg-surface-700', na: 'bg-surface-700' };

// --- Member Bid Report ---
const memberBidReport = ref(null);
const bidReportLoading = ref(false);
const bidReportFilter = ref('month');
const bidReportDateRange = ref(null);
const memberViewTab = ref('attendance');

async function fetchMemberBidReport() {
    bidReportLoading.value = true;
    try {
        const params = { filter: bidReportFilter.value };
        if (bidReportFilter.value === 'range' && bidReportDateRange.value) {
            const [from, to] = bidReportDateRange.value;
            params.from = formatDateParam(from);
            params.to = formatDateParam(to);
        }
        const { data } = await axios.get(`/api/admin/bidders/${viewMember.value.id}/bid-report`, { params });
        memberBidReport.value = data;
    } catch (e) {} finally { bidReportLoading.value = false; }
}

function formatDateParam(d) {
    if (!d) return '';
    const dt = new Date(d);
    return dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0');
}

function changeBidReportFilter(f) {
    bidReportFilter.value = f;
    if (f !== 'range') fetchMemberBidReport();
}

function onDateRangeChange(val) {
    bidReportDateRange.value = val;
    if (val && val[0] && val[1]) fetchMemberBidReport();
}

async function openMemberProfile(bidder) {
    viewMember.value = bidder;
    memberViewTab.value = 'report';
    attYear.value = new Date().getFullYear();
    attMonth.value = new Date().getMonth() + 1;
    bidReportFilter.value = 'month';
    bidReportDateRange.value = null;
    await Promise.all([fetchMemberAttendance(), fetchMemberBidReport()]);
}

function closeMemberProfile() {
    viewMember.value = null;
    memberAttendance.value = null;
    dayEditOpen.value = null;
}

async function fetchMemberAttendance() {
    memberAttLoading.value = true;
    try {
        const { data } = await axios.get(`/api/admin/bidders/${viewMember.value.id}/attendance`, {
            params: { year: attYear.value, month: attMonth.value }
        });
        memberAttendance.value = data;
    } catch (e) {} finally { memberAttLoading.value = false; }
}

function changeAttMonth(dir) {
    attMonth.value += dir;
    if (attMonth.value > 12) { attMonth.value = 1; attYear.value++; }
    if (attMonth.value < 1) { attMonth.value = 12; attYear.value--; }
    fetchMemberAttendance();
}

function openDayEdit(day) {
    if (day.status === 'future' || day.status === 'na') return;
    dayEditOpen.value = day.date;
    dayEditForm.value = {
        status: day.override?.status || day.status || 'present',
        manual_hours: day.override?.hours ?? (day.hours || ''),
        note: day.override?.note || '',
    };
}

async function saveDayEdit() {
    dayEditLoading.value = true;
    try {
        await axios.put(`/api/admin/bidders/${viewMember.value.id}/attendance`, {
            date: dayEditOpen.value,
            status: dayEditForm.value.status,
            manual_hours: dayEditForm.value.manual_hours !== '' ? Number(dayEditForm.value.manual_hours) : null,
            note: dayEditForm.value.note || null,
        });
        dayEditOpen.value = null;
        await fetchMemberAttendance();
    } catch (e) {} finally { dayEditLoading.value = false; }
}

async function removeDayOverride(date) {
    try {
        await axios.delete(`/api/admin/bidders/${viewMember.value.id}/attendance`, { data: { date } });
        await fetchMemberAttendance();
    } catch (e) {}
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
                    <div v-for="status in statusOptions" :key="status"
                        class="flex flex-col rounded-2xl bg-surface-900/50 border-2 min-w-0 transition-colors duration-200"
                        :class="dragOverStatus === status ? 'border-brand/50 bg-brand/5' : 'border-surface-800/40'"
                        @dragover="onDragOver($event, status)"
                        @dragleave="onDragLeave($event, status)"
                        @drop="onDrop($event, status)">
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
                                draggable="true"
                                @dragstart="onDragStart($event, bid.bid_id)"
                                @dragend="onDragEnd"
                                class="card card-hover p-4 border-l-2 relative overflow-hidden cursor-grab active:cursor-grabbing select-none"
                                :class="[statusColors[status]?.border, dragBidId === bid.bid_id ? 'opacity-50 scale-95' : '']">
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

                <!-- Add Member Modal Overlay -->
                <Teleport to="body">
                    <div v-if="showAddMember" class="fixed inset-0 z-[100] flex items-center justify-center p-4"
                        @click.self="showAddMember = false; resetMemberForm()">
                        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
                        <div class="relative bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl w-full max-w-lg animate-slide-up">
                            <!-- Modal Header -->
                            <div class="flex items-center justify-between px-6 py-4 border-b border-surface-800/50">
                                <div>
                                    <h3 class="text-lg font-semibold text-surface-100">Add Team Member</h3>
                                    <p class="text-xs text-surface-500 mt-0.5">Employee ID will be auto-assigned</p>
                                </div>
                                <button @click="showAddMember = false; resetMemberForm()"
                                    class="w-8 h-8 rounded-lg bg-surface-800/50 flex items-center justify-center text-surface-400 hover:text-surface-200 hover:bg-surface-800 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Modal Body -->
                            <form @submit.prevent="addMember" class="px-6 py-5 space-y-4">
                                <div v-if="addMemberError" class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/20 text-sm text-red-400">
                                    {{ addMemberError }}
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <!-- Name -->
                                    <div>
                                        <label class="block text-xs font-medium text-surface-400 mb-1.5">Full Name *</label>
                                        <input v-model="addMemberForm.name" type="text" placeholder="John Doe"
                                            class="input-field w-full" :class="{ 'border-red-500/50': addMemberErrors.name }" />
                                        <p v-if="addMemberErrors.name" class="text-[11px] text-red-400 mt-1">{{ addMemberErrors.name }}</p>
                                    </div>
                                    <!-- Email -->
                                    <div>
                                        <label class="block text-xs font-medium text-surface-400 mb-1.5">Email *</label>
                                        <input v-model="addMemberForm.email" type="email" placeholder="john@company.com"
                                            class="input-field w-full" :class="{ 'border-red-500/50': addMemberErrors.email }" />
                                        <p v-if="addMemberErrors.email" class="text-[11px] text-red-400 mt-1">{{ addMemberErrors.email }}</p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <!-- Password -->
                                    <div>
                                        <label class="block text-xs font-medium text-surface-400 mb-1.5">Password *</label>
                                        <input v-model="addMemberForm.password" type="password" placeholder="Min 6 characters"
                                            class="input-field w-full" :class="{ 'border-red-500/50': addMemberErrors.password }" />
                                        <p v-if="addMemberErrors.password" class="text-[11px] text-red-400 mt-1">{{ addMemberErrors.password }}</p>
                                    </div>
                                    <!-- Designation -->
                                    <div>
                                        <label class="block text-xs font-medium text-surface-400 mb-1.5">Designation *</label>
                                        <select v-model="addMemberForm.designation"
                                            class="input-field w-full appearance-none cursor-pointer">
                                            <option v-for="d in designations" :key="d" :value="d">{{ d }}</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="grid grid-cols-3 gap-4">
                                    <!-- Joining Date -->
                                    <div>
                                        <label class="block text-xs font-medium text-surface-400 mb-1.5">Joining Date *</label>
                                        <input v-model="addMemberForm.joining_date" type="date"
                                            class="input-field w-full" :class="{ 'border-red-500/50': addMemberErrors.joining_date }" />
                                        <p v-if="addMemberErrors.joining_date" class="text-[11px] text-red-400 mt-1">{{ addMemberErrors.joining_date }}</p>
                                    </div>
                                    <!-- Salary -->
                                    <div>
                                        <label class="block text-xs font-medium text-surface-400 mb-1.5">Salary (₹/month) *</label>
                                        <div class="relative">
                                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-surface-500 text-sm">₹</span>
                                            <input v-model="addMemberForm.salary" type="number" min="1" step="any" placeholder="25000"
                                                class="input-field w-full pl-7" :class="{ 'border-red-500/50': addMemberErrors.salary }" />
                                        </div>
                                        <p v-if="addMemberErrors.salary" class="text-[11px] text-red-400 mt-1">{{ addMemberErrors.salary }}</p>
                                    </div>
                                    <!-- Min Hours -->
                                    <div>
                                        <label class="block text-xs font-medium text-surface-400 mb-1.5">Min Hours/Day *</label>
                                        <div class="relative">
                                            <input v-model="addMemberForm.min_hours_per_day" type="number" min="1" max="24" step="0.5" placeholder="8"
                                                class="input-field w-full pr-8" :class="{ 'border-red-500/50': addMemberErrors.min_hours_per_day }" />
                                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-surface-500 text-xs">hrs</span>
                                        </div>
                                        <p v-if="addMemberErrors.min_hours_per_day" class="text-[11px] text-red-400 mt-1">{{ addMemberErrors.min_hours_per_day }}</p>
                                    </div>
                                </div>

                                <!-- Modal Footer -->
                                <div class="flex items-center justify-end gap-3 pt-3 border-t border-surface-800/50">
                                    <button type="button" @click="showAddMember = false; resetMemberForm()"
                                        class="btn-ghost text-sm px-4 py-2">Cancel</button>
                                    <button type="submit" :disabled="addMemberLoading"
                                        class="btn-primary text-sm px-5 py-2.5 justify-center min-w-[120px]">
                                        <span v-if="addMemberLoading" class="flex items-center gap-2">
                                            <div class="w-3.5 h-3.5 border-2 border-surface-950/30 border-t-surface-950 rounded-full animate-spin"></div>
                                            Adding...
                                        </span>
                                        <span v-else>Add Member</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </Teleport>

                <!-- Edit Member Modal -->
                <Teleport to="body">
                    <div v-if="editMember" class="fixed inset-0 z-[100] flex items-center justify-center p-4"
                        @click.self="closeEditMember()">
                        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
                        <div class="relative bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl w-full max-w-md animate-slide-up">
                            <div class="flex items-center justify-between px-6 py-4 border-b border-surface-800/50">
                                <div>
                                    <h3 class="text-lg font-semibold text-surface-100">Edit Member</h3>
                                    <p class="text-xs text-surface-500 mt-0.5">{{ editMember.name }} &mdash; {{ editMember.employee_id || 'No ID' }}</p>
                                </div>
                                <button @click="closeEditMember()"
                                    class="w-8 h-8 rounded-lg bg-surface-800/50 flex items-center justify-center text-surface-400 hover:text-surface-200 hover:bg-surface-800 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                            <form @submit.prevent="saveEditMember" class="px-6 py-5 space-y-4">
                                <!-- Designation -->
                                <div>
                                    <label class="block text-xs font-medium text-surface-400 mb-1.5">Designation</label>
                                    <select v-model="editForm.designation"
                                        class="input-field w-full appearance-none cursor-pointer">
                                        <option v-for="d in designations" :key="d" :value="d">{{ d }}</option>
                                    </select>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <!-- Salary -->
                                    <div>
                                        <label class="block text-xs font-medium text-surface-400 mb-1.5">Salary (₹/month)</label>
                                        <div class="relative">
                                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-surface-500 text-sm">₹</span>
                                            <input v-model="editForm.salary" type="number" min="1" step="any"
                                                class="input-field w-full pl-7" :class="{ 'border-red-500/50': editErrors.salary }" />
                                        </div>
                                        <p v-if="editErrors.salary" class="text-[11px] text-red-400 mt-1">{{ editErrors.salary }}</p>
                                    </div>
                                    <!-- Min Hours -->
                                    <div>
                                        <label class="block text-xs font-medium text-surface-400 mb-1.5">Min Hours/Day</label>
                                        <div class="relative">
                                            <input v-model="editForm.min_hours_per_day" type="number" min="1" max="24" step="0.5"
                                                class="input-field w-full pr-8" :class="{ 'border-red-500/50': editErrors.min_hours_per_day }" />
                                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-surface-500 text-xs">hrs</span>
                                        </div>
                                        <p v-if="editErrors.min_hours_per_day" class="text-[11px] text-red-400 mt-1">{{ editErrors.min_hours_per_day }}</p>
                                    </div>
                                </div>
                                <!-- Info row -->
                                <div class="rounded-lg bg-surface-800/40 border border-surface-700/20 p-3 grid grid-cols-2 gap-3 text-xs">
                                    <div>
                                        <span class="text-surface-500">Employee ID</span>
                                        <p class="text-surface-200 font-mono mt-0.5">{{ editMember.employee_id || '-' }}</p>
                                    </div>
                                    <div>
                                        <span class="text-surface-500">Joining Date</span>
                                        <p class="text-surface-200 mt-0.5">{{ editMember.joining_date || '-' }}</p>
                                    </div>
                                    <div>
                                        <span class="text-surface-500">Email</span>
                                        <p class="text-surface-200 mt-0.5">{{ editMember.email }}</p>
                                    </div>
                                    <div>
                                        <span class="text-surface-500">Total Bids</span>
                                        <p class="text-surface-200 font-mono mt-0.5">{{ editMember.total_bids }}</p>
                                    </div>
                                </div>
                                <!-- Footer -->
                                <div class="flex items-center justify-end gap-3 pt-3 border-t border-surface-800/50">
                                    <button type="button" @click="closeEditMember()" class="btn-ghost text-sm px-4 py-2">Cancel</button>
                                    <button type="submit" :disabled="editLoading"
                                        class="btn-primary text-sm px-5 py-2.5 justify-center min-w-[100px]">
                                        <span v-if="editLoading" class="flex items-center gap-2">
                                            <div class="w-3.5 h-3.5 border-2 border-surface-950/30 border-t-surface-950 rounded-full animate-spin"></div>
                                            Saving...
                                        </span>
                                        <span v-else>Save</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </Teleport>

                <!-- Member Profile & Attendance Modal -->
                <Teleport to="body">
                    <div v-if="viewMember" class="fixed inset-0 z-[100] flex items-center justify-center p-4"
                        @click.self="closeMemberProfile()">
                        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
                        <div class="relative bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col animate-slide-up">
                            <!-- Modal Header -->
                            <div class="flex items-center justify-between px-6 py-4 border-b border-surface-800/50 flex-shrink-0">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold"
                                        :class="getUserColor(viewMember.name)">
                                        {{ viewMember.name.charAt(0).toUpperCase() }}
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-semibold text-surface-100">{{ viewMember.name }}</h3>
                                        <p class="text-xs text-surface-500">{{ viewMember.employee_id || 'No ID' }} &middot; {{ viewMember.designation || 'Bidder' }}</p>
                                    </div>
                                </div>
                                <button @click="closeMemberProfile()"
                                    class="w-8 h-8 rounded-lg bg-surface-800/50 flex items-center justify-center text-surface-400 hover:text-surface-200 hover:bg-surface-800 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Tab switcher -->
                            <div class="px-6 pt-3 pb-0 flex gap-1 border-b border-surface-800/50 flex-shrink-0">
                                <button @click="memberViewTab = 'report'"
                                    :class="memberViewTab === 'report' ? 'text-brand border-brand' : 'text-surface-400 border-transparent hover:text-surface-200'"
                                    class="px-4 py-2 text-xs font-medium border-b-2 -mb-px transition-colors">
                                    Bid Report
                                </button>
                                <button @click="memberViewTab = 'attendance'"
                                    :class="memberViewTab === 'attendance' ? 'text-brand border-brand' : 'text-surface-400 border-transparent hover:text-surface-200'"
                                    class="px-4 py-2 text-xs font-medium border-b-2 -mb-px transition-colors">
                                    Attendance
                                </button>
                            </div>

                            <!-- Modal Body -->
                            <div class="flex-1 overflow-y-auto px-6 py-5">
                                <!-- Profile Info -->
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                                    <div class="rounded-lg bg-surface-800/40 border border-surface-700/20 p-3 text-center">
                                        <span class="text-[10px] text-surface-500 uppercase tracking-wider">Salary</span>
                                        <p class="text-sm font-semibold text-surface-200 mt-1">{{ formatSalary(viewMember.salary) }}</p>
                                    </div>
                                    <div class="rounded-lg bg-surface-800/40 border border-surface-700/20 p-3 text-center">
                                        <span class="text-[10px] text-surface-500 uppercase tracking-wider">Min Hours</span>
                                        <p class="text-sm font-semibold text-surface-200 mt-1">{{ viewMember.min_hours_per_day }}h/day</p>
                                    </div>
                                    <div class="rounded-lg bg-surface-800/40 border border-surface-700/20 p-3 text-center">
                                        <span class="text-[10px] text-surface-500 uppercase tracking-wider">Joined</span>
                                        <p class="text-sm font-semibold text-surface-200 mt-1">{{ viewMember.joining_date || '-' }}</p>
                                    </div>
                                    <div class="rounded-lg bg-surface-800/40 border border-surface-700/20 p-3 text-center">
                                        <span class="text-[10px] text-surface-500 uppercase tracking-wider">Total Bids</span>
                                        <p class="text-sm font-semibold text-surface-200 mt-1">{{ viewMember.total_bids }}</p>
                                    </div>
                                </div>

                                <!-- ===== BID REPORT TAB ===== -->
                                <div v-show="memberViewTab === 'report'">
                                    <!-- Filter bar -->
                                    <div class="flex flex-wrap items-center gap-2 mb-4">
                                        <button v-for="f in [{key:'today',label:'Today'},{key:'week',label:'This Week'},{key:'month',label:'This Month'},{key:'range',label:'Date Range'}]"
                                            :key="f.key" @click="changeBidReportFilter(f.key)"
                                            :class="bidReportFilter === f.key ? 'bg-brand/10 text-brand border-brand/30' : 'bg-surface-800/50 text-surface-400 border-surface-700/30 hover:text-surface-200'"
                                            class="px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors">
                                            {{ f.label }}
                                        </button>
                                    </div>
                                    <!-- Date range picker -->
                                    <div v-if="bidReportFilter === 'range'" class="mb-4 relative" style="z-index: 200;">
                                        <VueDatePicker
                                            v-model="bidReportDateRange"
                                            range
                                            :enable-time-picker="false"
                                            :max-date="new Date()"
                                            dark
                                            auto-apply
                                            placeholder="Select date range"
                                            format="dd MMM yyyy"
                                            @update:model-value="onDateRangeChange"
                                            teleport="body"
                                        />
                                    </div>

                                    <!-- Loading -->
                                    <div v-if="bidReportLoading" class="flex items-center justify-center py-8">
                                        <div class="w-6 h-6 border-2 border-brand/30 border-t-brand rounded-full animate-spin"></div>
                                    </div>

                                    <div v-else-if="memberBidReport">
                                        <!-- Stats cards -->
                                        <div class="grid grid-cols-5 gap-3 mb-5">
                                            <div class="rounded-lg bg-surface-800/40 border border-surface-700/20 p-3 text-center">
                                                <p class="text-xl font-bold text-surface-100">{{ memberBidReport.total }}</p>
                                                <p class="text-[10px] text-surface-500">Applied</p>
                                            </div>
                                            <div class="rounded-lg bg-blue-500/5 border border-blue-500/20 p-3 text-center">
                                                <p class="text-xl font-bold text-blue-400">{{ memberBidReport.status_counts.Submitted }}</p>
                                                <p class="text-[10px] text-surface-500">Submitted</p>
                                            </div>
                                            <div class="rounded-lg bg-amber-500/5 border border-amber-500/20 p-3 text-center">
                                                <p class="text-xl font-bold text-amber-400">{{ memberBidReport.status_counts.Interviewing }}</p>
                                                <p class="text-[10px] text-surface-500">Responded</p>
                                            </div>
                                            <div class="rounded-lg bg-emerald-500/5 border border-emerald-500/20 p-3 text-center">
                                                <p class="text-xl font-bold text-emerald-400">{{ memberBidReport.status_counts.Hired }}</p>
                                                <p class="text-[10px] text-surface-500">Hired</p>
                                            </div>
                                            <div class="rounded-lg bg-red-500/5 border border-red-500/20 p-3 text-center">
                                                <p class="text-xl font-bold text-red-400">{{ memberBidReport.status_counts.Rejected }}</p>
                                                <p class="text-[10px] text-surface-500">Rejected</p>
                                            </div>
                                        </div>

                                        <!-- Conversion bar -->
                                        <div v-if="memberBidReport.total > 0" class="mb-5">
                                            <div class="flex items-center justify-between mb-1.5">
                                                <span class="text-xs text-surface-400">Conversion breakdown</span>
                                                <span class="text-xs font-medium text-brand">{{ memberBidReport.total > 0 ? Math.round((memberBidReport.status_counts.Hired / memberBidReport.total) * 100) : 0 }}% hired</span>
                                            </div>
                                            <div class="h-2 rounded-full bg-surface-800 flex overflow-hidden">
                                                <div class="h-full bg-blue-400 transition-all" :style="{ width: (memberBidReport.status_counts.Submitted / memberBidReport.total * 100) + '%' }"></div>
                                                <div class="h-full bg-amber-400 transition-all" :style="{ width: (memberBidReport.status_counts.Interviewing / memberBidReport.total * 100) + '%' }"></div>
                                                <div class="h-full bg-emerald-400 transition-all" :style="{ width: (memberBidReport.status_counts.Hired / memberBidReport.total * 100) + '%' }"></div>
                                                <div class="h-full bg-red-400 transition-all" :style="{ width: (memberBidReport.status_counts.Rejected / memberBidReport.total * 100) + '%' }"></div>
                                            </div>
                                        </div>

                                        <!-- Bids list -->
                                        <div class="border border-surface-700/30 rounded-xl overflow-hidden">
                                            <div class="px-4 py-2.5 bg-surface-800/30 border-b border-surface-700/30">
                                                <span class="text-xs font-medium text-surface-400">Recent Bids ({{ memberBidReport.bids.length }})</span>
                                            </div>
                                            <div v-if="memberBidReport.bids.length === 0" class="px-4 py-8 text-center text-xs text-surface-500">
                                                No bids in this period
                                            </div>
                                            <div v-else class="max-h-[240px] overflow-y-auto scrollbar-thin">
                                                <div v-for="bid in memberBidReport.bids" :key="bid.bid_id"
                                                    class="px-4 py-2.5 border-b border-surface-800/30 last:border-0 flex items-center justify-between hover:bg-surface-800/20 transition-colors">
                                                    <div class="min-w-0 flex-1">
                                                        <p class="text-xs font-medium text-surface-200 truncate">{{ bid.job_title || truncateUrl(bid.job_url) }}</p>
                                                        <p class="text-[10px] text-surface-500 mt-0.5">{{ bid.platform_name }} &middot; {{ bid.connects_used }} connects &middot; {{ toLocalDate(bid.created_at) }}</p>
                                                    </div>
                                                    <span class="ml-3 text-[10px] font-medium px-2 py-0.5 rounded-md flex-shrink-0"
                                                        :class="statusColors[bid.status]?.badge || 'bg-surface-700/50 text-surface-400'">
                                                        {{ bid.status }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- ===== ATTENDANCE TAB ===== -->
                                <div v-show="memberViewTab === 'attendance'">
                                <div class="border border-surface-700/30 rounded-xl overflow-hidden">
                                    <!-- Month navigation -->
                                    <div class="px-4 py-3 bg-surface-800/30 border-b border-surface-700/30 flex items-center justify-between">
                                        <button @click="changeAttMonth(-1)" class="w-7 h-7 rounded-md bg-surface-700/50 flex items-center justify-center text-surface-400 hover:text-surface-200 hover:bg-surface-700 transition-colors">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                                            </svg>
                                        </button>
                                        <span class="text-sm font-semibold text-surface-200">
                                            {{ memberAttendance?.month_name || '' }} {{ attYear }}
                                        </span>
                                        <button @click="changeAttMonth(1)" class="w-7 h-7 rounded-md bg-surface-700/50 flex items-center justify-center text-surface-400 hover:text-surface-200 hover:bg-surface-700 transition-colors">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                            </svg>
                                        </button>
                                    </div>

                                    <!-- Loading -->
                                    <div v-if="memberAttLoading" class="flex items-center justify-center py-12">
                                        <div class="w-6 h-6 border-2 border-brand/30 border-t-brand rounded-full animate-spin"></div>
                                    </div>

                                    <!-- Attendance summary -->
                                    <div v-else-if="memberAttendance" class="p-4">
                                        <div class="grid grid-cols-4 gap-3 mb-4">
                                            <div class="text-center">
                                                <p class="text-lg font-bold text-emerald-400">{{ memberAttendance.summary.present_days }}</p>
                                                <p class="text-[10px] text-surface-500">Present</p>
                                            </div>
                                            <div class="text-center">
                                                <p class="text-lg font-bold text-red-400">{{ memberAttendance.summary.absent_days }}</p>
                                                <p class="text-[10px] text-surface-500">Absent</p>
                                            </div>
                                            <div class="text-center">
                                                <p class="text-lg font-bold text-surface-200">{{ memberAttendance.summary.total_worked_hours }}h</p>
                                                <p class="text-[10px] text-surface-500">Total Hours</p>
                                            </div>
                                            <div class="text-center">
                                                <p class="text-lg font-bold text-surface-200">{{ memberAttendance.summary.avg_hours_per_day }}h</p>
                                                <p class="text-[10px] text-surface-500">Avg/Day</p>
                                            </div>
                                        </div>

                                        <!-- Day grid -->
                                        <div class="grid grid-cols-7 gap-1 mb-2">
                                            <div v-for="d in ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']" :key="d"
                                                class="text-center text-[10px] font-medium text-surface-500 py-1">{{ d }}</div>
                                        </div>
                                        <div class="grid grid-cols-7 gap-1">
                                            <!-- Offset for first day of month -->
                                            <div v-for="n in ((memberAttendance.days[0]?.day_name === 'Mon' ? 0 : memberAttendance.days[0]?.day_name === 'Tue' ? 1 : memberAttendance.days[0]?.day_name === 'Wed' ? 2 : memberAttendance.days[0]?.day_name === 'Thu' ? 3 : memberAttendance.days[0]?.day_name === 'Fri' ? 4 : memberAttendance.days[0]?.day_name === 'Sat' ? 5 : 6))" :key="'blank-'+n"></div>
                                            <!-- Day cells -->
                                            <div v-for="day in memberAttendance.days" :key="day.date"
                                                @click="openDayEdit(day)"
                                                class="relative rounded-lg p-1.5 text-center transition-all cursor-pointer border"
                                                :class="[
                                                    dayEditOpen === day.date ? 'border-brand ring-1 ring-brand/30' : 'border-transparent hover:border-surface-600',
                                                    day.status === 'future' || day.status === 'na' ? 'opacity-30 cursor-default' : '',
                                                    day.override ? 'ring-1 ring-violet-500/30' : ''
                                                ]">
                                                <p class="text-xs font-medium text-surface-300">{{ day.day }}</p>
                                                <div class="w-2 h-2 rounded-full mx-auto mt-0.5" :class="statusDotColors[day.status] || 'bg-surface-700'"></div>
                                                <p v-if="day.hours > 0" class="text-[9px] text-surface-500 mt-0.5">{{ day.hours }}h</p>
                                            </div>
                                        </div>

                                        <!-- Legend -->
                                        <div class="flex flex-wrap gap-3 mt-4 pt-3 border-t border-surface-800/30">
                                            <div v-for="(label, key) in statusLabels" :key="key" class="flex items-center gap-1.5">
                                                <div class="w-2 h-2 rounded-full" :class="statusDotColors[key]"></div>
                                                <span class="text-[10px] text-surface-500">{{ label }}</span>
                                            </div>
                                        </div>

                                        <!-- Day Edit Panel -->
                                        <div v-if="dayEditOpen" class="mt-4 p-4 rounded-xl bg-surface-800/50 border border-surface-700/30">
                                            <div class="flex items-center justify-between mb-3">
                                                <h4 class="text-sm font-semibold text-surface-200">Edit: {{ dayEditOpen }}</h4>
                                                <button @click="dayEditOpen = null" class="text-xs text-surface-500 hover:text-surface-300">&times; Close</button>
                                            </div>
                                            <div class="grid grid-cols-3 gap-3">
                                                <div>
                                                    <label class="block text-[10px] font-medium text-surface-500 mb-1">Status</label>
                                                    <select v-model="dayEditForm.status" class="input-field w-full text-xs">
                                                        <option value="present">Present</option>
                                                        <option value="absent">Absent</option>
                                                        <option value="week_off">Week Off</option>
                                                        <option value="half_day">Half Day</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="block text-[10px] font-medium text-surface-500 mb-1">Manual Hours</label>
                                                    <input v-model="dayEditForm.manual_hours" type="number" min="0" max="24" step="0.5"
                                                        class="input-field w-full text-xs" placeholder="Auto" />
                                                </div>
                                                <div>
                                                    <label class="block text-[10px] font-medium text-surface-500 mb-1">Note</label>
                                                    <input v-model="dayEditForm.note" type="text" maxlength="255"
                                                        class="input-field w-full text-xs" placeholder="Optional" />
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2 mt-3">
                                                <button @click="saveDayEdit" :disabled="dayEditLoading"
                                                    class="btn-primary text-xs px-4 py-1.5">
                                                    {{ dayEditLoading ? 'Saving...' : 'Save' }}
                                                </button>
                                                <button @click="removeDayOverride(dayEditOpen)"
                                                    class="btn-ghost text-xs text-red-400 hover:text-red-300 px-3 py-1.5">
                                                    Remove Override
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <!-- Bidders table -->
                <div class="card overflow-hidden">
                    <div class="px-6 py-4 border-b border-surface-700/30 flex items-center justify-between">
                        <h2 class="font-semibold text-surface-100">Team Members</h2>
                        <button v-if="!showAddMember" @click="showAddMember = true" class="btn-primary text-xs px-4 py-2">
                            <svg class="w-3.5 h-3.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Add Member
                        </button>
                    </div>

                    <div v-if="bidders.length === 0" class="px-6 py-12 text-center">
                        <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-surface-800/50 flex items-center justify-center">
                            <svg class="w-8 h-8 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                            </svg>
                        </div>
                        <p class="text-surface-300 font-medium">No team members yet</p>
                        <p class="text-sm text-surface-500 mt-1">Click "Add Member" to invite your first bidder</p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-surface-700/30">
                                    <th class="px-4 py-3 text-left table-header">Emp ID</th>
                                    <th class="px-4 py-3 text-left table-header">Member</th>
                                    <th class="px-4 py-3 text-left table-header">Designation</th>
                                    <th class="px-4 py-3 text-left table-header">Status</th>
                                    <th class="px-4 py-3 text-center table-header">Salary</th>
                                    <th class="px-4 py-3 text-center table-header">Min Hrs</th>
                                    <th class="px-4 py-3 text-center table-header">Today</th>
                                    <th class="px-4 py-3 text-center table-header">Hours</th>
                                    <th class="px-4 py-3 text-right table-header">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm">
                                <tr v-for="bidder in bidders" :key="bidder.id"
                                    class="border-b border-surface-800/30 hover:bg-surface-800/20 transition-colors"
                                    :class="{ 'opacity-50': !bidder.is_active }">
                                    <td class="px-4 py-4">
                                        <span class="font-mono text-xs text-surface-400 bg-surface-800/50 px-2 py-0.5 rounded">
                                            {{ bidder.employee_id || '-' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="relative">
                                                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
                                                    :class="getUserColor(bidder.name)">
                                                    {{ bidder.name.charAt(0).toUpperCase() }}
                                                </div>
                                                <div v-if="bidder.is_online" class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-emerald-400 border-2 border-surface-900"></div>
                                            </div>
                                            <div>
                                                <p class="font-medium text-surface-200 text-sm">{{ bidder.name }}</p>
                                                <p class="text-[11px] text-surface-500">{{ bidder.email }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <select :value="bidder.designation || ''"
                                            @change="updateMemberDesignation(bidder.id, $event.target.value)"
                                            class="text-[11px] font-medium px-2 py-1 rounded-md border-0 cursor-pointer appearance-none"
                                            :class="designationBadge[bidder.designation] || 'bg-surface-700/50 text-surface-400'"
                                            style="background-image: none;">
                                            <option value="" disabled>Set role</option>
                                            <option v-for="d in designations" :key="d" :value="d"
                                                class="bg-surface-800 text-surface-200">{{ d }}</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span v-if="!bidder.is_active" class="badge-danger">Disabled</span>
                                        <span v-else-if="bidder.is_online" class="badge-success">Online</span>
                                        <span v-else class="badge-neutral">Offline</span>
                                    </td>
                                    <td class="px-4 py-4 text-center text-xs font-mono text-surface-300">{{ formatSalary(bidder.salary) }}</td>
                                    <td class="px-4 py-4 text-center text-xs font-mono text-surface-300">{{ bidder.min_hours_per_day }}h</td>
                                    <td class="px-4 py-4 text-center font-mono text-surface-300">{{ bidder.bids_today }}</td>
                                    <td class="px-4 py-4 text-center font-mono"
                                        :class="bidder.today_hours < bidder.min_hours_per_day && bidder.is_online ? 'text-amber-400' : 'text-surface-300'">
                                        {{ bidder.today_hours }}h
                                    </td>
                                    <td class="px-4 py-4 text-right space-x-1">
                                        <button @click="openMemberProfile(bidder)"
                                            class="btn-ghost text-xs text-surface-300 hover:text-surface-100"
                                            title="View profile & attendance">
                                            View
                                        </button>
                                        <button @click="impersonateUser(bidder.id)"
                                            class="btn-ghost text-xs text-brand hover:text-brand/80"
                                            title="Login as this user">
                                            Impersonate
                                        </button>
                                        <button @click="openEditMember(bidder)"
                                            class="btn-ghost text-xs text-surface-300 hover:text-surface-100">
                                            Edit
                                        </button>
                                        <button @click="toggleMemberActive(bidder.id, bidder.is_active)"
                                            :class="bidder.is_active ? 'text-red-400 hover:text-red-300' : 'text-emerald-400 hover:text-emerald-300'"
                                            class="btn-ghost text-xs">
                                            {{ bidder.is_active ? 'Disable' : 'Enable' }}
                                        </button>
                                    </td>
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
