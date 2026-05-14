<script setup>
import { Head, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, computed } from 'vue';
import PitchFlowLogo from '@/Components/PitchFlowLogo.vue';
import MessagingPanel from '@/Components/Messaging/MessagingPanel.vue';
import { useMessaging } from '@/composables/useMessaging';
import { useTabBadge } from '@/composables/useTabBadge';
import { vProtectedSrc } from '@/directives/protectedSrc';
import axios from 'axios';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';

const props = defineProps(['auth', 'face_recognition']);

const { totalUnread, fetchUnreadCount, handleIncomingMessage, handleTypingEvent, handleReadEvent, handlePresenceEvent, togglePanel, startHeartbeat, openConversationByHash } = useMessaging();
useTabBadge(totalUnread, 'Admin Dashboard - PitchFlow');

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
    name: '', email: '', password: '', position_ids: [],
    joining_date: new Date().toISOString().split('T')[0],
    salary: '', min_hours_per_day: '8',
});
const addMemberErrors = ref({});
const addMemberError = ref('');
const addMemberLoading = ref(false);

const positionColors = ['sky', 'violet', 'amber', 'emerald', 'rose', 'cyan', 'indigo', 'orange', 'teal', 'fuchsia'];
function getPositionBadge(title) {
    const idx = (title || '').charCodeAt(0) % positionColors.length;
    const c = positionColors[idx];
    return `bg-${c}-500/10 text-${c}-400`;
}

function resetMemberForm() {
    addMemberForm.value = {
        name: '', email: '', password: '', position_ids: [],
        joining_date: new Date().toISOString().split('T')[0],
        salary: '', min_hours_per_day: '8',
    };
    addMemberErrors.value = {};
    addMemberError.value = '';
}

function togglePositionSelection(posId, formRef) {
    const idx = formRef.indexOf(posId);
    if (idx === -1) formRef.push(posId);
    else formRef.splice(idx, 1);
}

function validateMemberForm() {
    const errors = {};
    const f = addMemberForm.value;
    if (!f.name || !f.name.trim()) errors.name = 'Name is required';
    if (!f.email || !f.email.trim()) errors.email = 'Email is required';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email)) errors.email = 'Invalid email format';
    if (!f.password || f.password.length < 6) errors.password = 'Minimum 6 characters';
    if (!f.position_ids || f.position_ids.length === 0) errors.position_ids = 'At least one position is required';
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
            position_ids: addMemberForm.value.position_ids.map(Number),
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
        position_ids: (bidder.positions || []).map(p => p.id),
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
            position_ids: editForm.value.position_ids.map(Number),
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
const dayEditForm = ref({ status: 'present', manual_hours: '', note: '', show_status_to_member: false, show_note_to_member: false });
const dayEditLoading = ref(false);

function formatHours(h) {
    if (!h || h === 0) return '0h 0m';
    const val = Math.abs(h);
    const hours = Math.floor(val);
    const mins = Math.round((val - hours) * 60);
    if (hours === 0) return `${mins}m`;
    if (mins === 0) return `${hours}h`;
    return `${hours}h ${mins}m`;
}

function parseDevice(ua) {
    if (!ua) return null;
    let browser = 'Unknown';
    let os = 'Unknown';
    if (ua.includes('Edg/')) browser = 'Edge';
    else if (ua.includes('OPR/') || ua.includes('Opera')) browser = 'Opera';
    else if (ua.includes('Chrome/') && !ua.includes('Chromium')) browser = 'Chrome';
    else if (ua.includes('Firefox/')) browser = 'Firefox';
    else if (ua.includes('Safari/') && !ua.includes('Chrome')) browser = 'Safari';

    if (ua.includes('iPhone')) os = 'iPhone';
    else if (ua.includes('iPad')) os = 'iPad';
    else if (ua.includes('Android')) os = 'Android';
    else if (ua.includes('Windows')) os = 'Windows';
    else if (ua.includes('Mac OS')) os = 'macOS';
    else if (ua.includes('Linux')) os = 'Linux';

    return `${browser} · ${os}`;
}

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
    await Promise.all([fetchMemberAttendance(), fetchMemberBidReport(), fetchMemberFaceVideos()]);
    if (faceEnabled.value) startVideoPoll();
}

function closeMemberProfile() {
    stopVideoPoll();
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

const memberFaceVideos = ref([]);
const faceVideoModalUrl = ref(null);
let videoPollInterval = null;

const faceEnabled = computed(() => props.face_recognition?.enabled === true);

async function fetchMemberFaceVideos() {
    if (!viewMember.value) return;
    try {
        const { data } = await axios.get(`/api/admin/bidders/${viewMember.value.id}/face-videos`);
        memberFaceVideos.value = data.videos;
    } catch (e) { memberFaceVideos.value = []; }
}

const sessionVideoPopup = ref(null);

function getSessionVideos(session) {
    const empty = { punch_in: [], punch_out: [], punch_in_pending: false, punch_out_pending: false };
    if (!session.log_id) return empty;
    if (!faceEnabled.value) return empty;

    const pinAll = memberFaceVideos.value.filter(v => v.type === 'punch_in' && v.time_log_id === session.log_id);
    const poutAll = memberFaceVideos.value.filter(v => v.type === 'punch_out' && v.time_log_id === session.log_id);

    return {
        punch_in: pinAll,
        punch_out: poutAll,
        punch_in_pending: pinAll.length === 0 && !!session.in,
        punch_out_pending: poutAll.length === 0 && !!session.out,
    };
}

function openSessionVideoPopup(session, punchType) {
    const sv = getSessionVideos(session);
    const videos = (punchType === 'in' ? sv.punch_in : sv.punch_out)
        .slice()
        .sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
    if (!videos.length) return;
    sessionVideoPopup.value = {
        punchType,
        videos,
        time: punchType === 'in' ? session.in : session.out,
    };
}

function closeSessionVideoPopup() {
    sessionVideoPopup.value = null;
}

const hasPendingVideos = computed(() => {
    if (!faceEnabled.value || !memberAttendance.value) return false;
    for (const day of memberAttendance.value.days) {
        for (const s of day.sessions || []) {
            if (!s.log_id) continue;
            const v = getSessionVideos(s);
            if (v.punch_in_pending || v.punch_out_pending) return true;
        }
    }
    return false;
});

function startVideoPoll() {
    stopVideoPoll();
    videoPollInterval = setInterval(() => {
        if (hasPendingVideos.value && viewMember.value) {
            fetchMemberFaceVideos();
        } else {
            stopVideoPoll();
        }
    }, 10000);
}

function stopVideoPoll() {
    if (videoPollInterval) {
        clearInterval(videoPollInterval);
        videoPollInterval = null;
    }
}

const enrollmentVideo = computed(() => memberFaceVideos.value.find(v => v.type === 'enrollment'));

function openVideoModal(videoId) {
    faceVideoModalUrl.value = `/api/face/video/${videoId}`;
}
function closeVideoModal() {
    faceVideoModalUrl.value = null;
}

const selectedDaySessions = computed(() => {
    if (!dayEditOpen.value || !memberAttendance.value) return [];
    const day = memberAttendance.value.days.find(d => d.date === dayEditOpen.value);
    return day?.sessions || [];
});

function openDayEdit(day) {
    if (day.status === 'future' || day.status === 'na') return;
    dayEditOpen.value = day.date;
    dayEditForm.value = {
        status: day.override?.status || day.status || 'present',
        manual_hours: day.override?.hours ?? (day.hours || ''),
        note: day.override?.note || '',
        show_status_to_member: day.override?.show_status_to_member || false,
        show_note_to_member: day.override?.show_note_to_member || false,
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
            show_status_to_member: dayEditForm.value.show_status_to_member,
            show_note_to_member: dayEditForm.value.show_note_to_member,
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

// --- Positions ---
const positions = ref([]);
const positionsLoading = ref(false);
const showAddPosition = ref(false);
const addPositionForm = ref({ title: '', description: '' });
const addPositionErrors = ref({});
const addPositionLoading = ref(false);
const editPosition = ref(null);
const editPositionForm = ref({ title: '', description: '' });
const editPositionErrors = ref({});
const editPositionLoading = ref(false);
const deleteConfirm = ref(null);
const deleteLoading = ref(false);

async function fetchPositions() {
    positionsLoading.value = true;
    try {
        const { data } = await axios.get('/api/admin/positions');
        positions.value = data.positions;
    } catch (e) {} finally { positionsLoading.value = false; }
}

function resetPositionForm() {
    addPositionForm.value = { title: '', description: '' };
    addPositionErrors.value = {};
}

async function addPosition() {
    addPositionErrors.value = {};
    if (!addPositionForm.value.title.trim()) {
        addPositionErrors.value.title = 'Title is required';
        return;
    }
    addPositionLoading.value = true;
    try {
        await axios.post('/api/admin/positions', addPositionForm.value);
        showAddPosition.value = false;
        resetPositionForm();
        await fetchPositions();
    } catch (e) {
        if (e.response?.status === 422) {
            addPositionErrors.value.title = e.response.data.message || 'Validation error';
        }
    } finally { addPositionLoading.value = false; }
}

function openEditPosition(pos) {
    editPosition.value = pos;
    editPositionForm.value = { title: pos.title, description: pos.description || '' };
    editPositionErrors.value = {};
}

async function saveEditPosition() {
    editPositionErrors.value = {};
    if (!editPositionForm.value.title.trim()) {
        editPositionErrors.value.title = 'Title is required';
        return;
    }
    editPositionLoading.value = true;
    try {
        await axios.put(`/api/admin/positions/${editPosition.value.id}`, editPositionForm.value);
        editPosition.value = null;
        await fetchPositions();
        await fetchTeamData();
    } catch (e) {
        if (e.response?.status === 422) {
            editPositionErrors.value.title = e.response.data.message || 'Validation error';
        }
    } finally { editPositionLoading.value = false; }
}

async function togglePositionActive(pos) {
    try {
        await axios.put(`/api/admin/positions/${pos.id}`, { is_active: !pos.is_active });
        await fetchPositions();
    } catch (e) {}
}

async function deletePosition(pos) {
    deleteLoading.value = true;
    try {
        await axios.delete(`/api/admin/positions/${pos.id}`);
        deleteConfirm.value = null;
        await fetchPositions();
    } catch (e) {
        if (e.response?.status === 422) {
            alert(e.response.data.message);
        }
        deleteConfirm.value = null;
    } finally { deleteLoading.value = false; }
}

const activePositions = computed(() => positions.value.filter(p => p.is_active));

// --- Position Drag & Drop ---
const dragPosId = ref(null);
const dragOverPosId = ref(null);

function onPosDragStart(e, pos) {
    dragPosId.value = pos.id;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', pos.id);
    e.target.closest('[data-pos-item]').style.opacity = '0.4';
}

function onPosDragEnd(e) {
    const el = e.target.closest('[data-pos-item]');
    if (el) el.style.opacity = '1';
    dragPosId.value = null;
    dragOverPosId.value = null;
}

function onPosDragOver(e, pos) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    dragOverPosId.value = pos.id;
}

function onPosDragLeave(e, pos) {
    if (!e.currentTarget.contains(e.relatedTarget)) {
        if (dragOverPosId.value === pos.id) dragOverPosId.value = null;
    }
}

async function onPosDrop(e, targetPos) {
    e.preventDefault();
    dragOverPosId.value = null;
    const sourceId = parseInt(e.dataTransfer.getData('text/plain'));
    if (sourceId === targetPos.id) { dragPosId.value = null; return; }

    const list = [...positions.value];
    const fromIdx = list.findIndex(p => p.id === sourceId);
    const toIdx = list.findIndex(p => p.id === targetPos.id);
    if (fromIdx === -1 || toIdx === -1) { dragPosId.value = null; return; }

    const [moved] = list.splice(fromIdx, 1);
    list.splice(toIdx, 0, moved);
    positions.value = list;
    dragPosId.value = null;

    try {
        await axios.post('/api/admin/positions/reorder', { ids: list.map(p => p.id) });
    } catch (e) {
        await fetchPositions();
    }
}

// --- Agency Profile ---
const agencyProfile = ref({ skills: '', tech_stack: '', description: '', can_build: '' });
const agencySaving = ref(false);
const agencySaved = ref(false);

async function fetchAgencyProfile() {
    try {
        const { data } = await axios.get('/api/admin/agency-profile');
        agencyProfile.value = { skills: data.skills || '', tech_stack: data.tech_stack || '', description: data.description || '', can_build: data.can_build || '' };
    } catch {}
}

async function saveAgencyProfile() {
    agencySaving.value = true;
    agencySaved.value = false;
    try {
        await axios.post('/api/admin/agency-profile', agencyProfile.value);
        agencySaved.value = true;
        setTimeout(() => { agencySaved.value = false; }, 2500);
    } catch {}
    finally { agencySaving.value = false; }
}

// --- Settings ---
const settingsForm = ref({
    name: '',
    email: '',
    notification_email: '',
    current_password: '',
    password: '',
    password_confirmation: '',
});
const settingsSaving = ref(false);
const settingsSaved = ref(false);
const settingsErrors = ref({});
const passwordSaving = ref(false);
const passwordSaved = ref(false);
const passwordErrors = ref({});
const profilePicFile = ref(null);
const profilePicPreview = ref(null);

function handleProfilePic(e) {
    const file = e.target.files[0];
    if (!file) return;
    profilePicFile.value = file;
    const reader = new FileReader();
    reader.onload = (ev) => profilePicPreview.value = ev.target.result;
    reader.readAsDataURL(file);
}

async function loadProfilePicPreview() {
    const url = props.auth.user.profile_picture_url;
    if (!url) return;
    try {
        const res = await fetch(url, {
            headers: { 'X-PF-Token': '1' },
            credentials: 'same-origin',
        });
        if (res.ok) {
            const blob = await res.blob();
            profilePicPreview.value = URL.createObjectURL(blob);
        }
    } catch {}
}

function loadSettingsForm() {
    settingsForm.value.name = props.auth.user.name;
    settingsForm.value.email = props.auth.user.email;
    settingsForm.value.notification_email = props.auth.user.notification_email || '';
    loadProfilePicPreview();
}

async function saveSettings() {
    settingsSaving.value = true;
    settingsSaved.value = false;
    settingsErrors.value = {};
    try {
        const fd = new FormData();
        fd.append('name', settingsForm.value.name);
        fd.append('email', settingsForm.value.email);
        if (settingsForm.value.notification_email) fd.append('notification_email', settingsForm.value.notification_email);
        if (profilePicFile.value) fd.append('profile_picture', profilePicFile.value);

        await axios.post('/api/profile/basic', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        profilePicFile.value = null;
        settingsSaved.value = true;
        setTimeout(() => { settingsSaved.value = false; }, 2500);
    } catch (e) {
        if (e.response?.status === 422) {
            settingsErrors.value = e.response.data.errors || {};
        }
    } finally {
        settingsSaving.value = false;
    }
}

async function savePassword() {
    passwordSaving.value = true;
    passwordSaved.value = false;
    passwordErrors.value = {};
    try {
        await axios.post('/api/profile/password', {
            current_password: settingsForm.value.current_password,
            password: settingsForm.value.password,
            password_confirmation: settingsForm.value.password_confirmation,
        });
        settingsForm.value.current_password = '';
        settingsForm.value.password = '';
        settingsForm.value.password_confirmation = '';
        passwordSaved.value = true;
        setTimeout(() => { passwordSaved.value = false; }, 2500);
    } catch (e) {
        if (e.response?.status === 422) {
            passwordErrors.value = e.response.data.errors || {};
        }
    } finally {
        passwordSaving.value = false;
    }
}

onMounted(async () => {
    ready.value = true;
    loadSettingsForm();
    await Promise.all([fetchPipelineData(), fetchTeamData(), fetchAgencyProfile(), fetchPositions()]);
    loading.value = false;
    fetchUnreadCount();
    startHeartbeat();
    if (props.auth.user?.id && window.Echo) {
        window.Echo.private(`messages.${props.auth.user.id}`)
            .listen('.message.sent', handleIncomingMessage)
            .listen('.user.typing', handleTypingEvent)
            .listen('.messages.read', handleReadEvent)
            .listen('.user.presence', handlePresenceEvent);
    }

    const params = new URLSearchParams(window.location.search);
    const convHash = params.get('conversation');
    if (convHash) {
        openConversationByHash(convHash);
    }
});

onUnmounted(() => {
    stopVideoPoll();
    if (props.auth.user?.id && window.Echo) {
        window.Echo.leaveChannel(`private-messages.${props.auth.user.id}`);
    }
});
</script>

<template>
    <Head title="Admin Dashboard" />

    <div class="min-h-screen bg-surface-950 text-surface-100 flex" @click="statusMenuOpen = null">
        <!-- Sidebar -->
        <aside class="hidden lg:flex w-60 flex-col border-r border-surface-800/50 bg-surface-900/50 flex-shrink-0">
            <div class="p-5 border-b border-surface-800/50">
                <div class="flex items-center gap-2">
                    <PitchFlowLogo size="w-7 h-7" />
                    <span class="text-sm font-bold">PitchFlow</span>
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
                <button @click="activeTab = 'positions'"
                    :class="activeTab === 'positions' ? 'bg-surface-800/60 text-surface-100' : 'text-surface-400 hover:text-surface-200 hover:bg-surface-800/30'"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0M12 12.75h.008v.008H12v-.008z" />
                    </svg>
                    Positions
                </button>
                <button @click="activeTab = 'reports'"
                    :class="activeTab === 'reports' ? 'bg-surface-800/60 text-surface-100' : 'text-surface-400 hover:text-surface-200 hover:bg-surface-800/30'"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                    </svg>
                    Reports
                </button>
                <button @click="activeTab = 'agency'"
                    :class="activeTab === 'agency' ? 'bg-surface-800/60 text-surface-100' : 'text-surface-400 hover:text-surface-200 hover:bg-surface-800/30'"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                    </svg>
                    Agency Profile
                </button>
                <button @click="activeTab = 'settings'"
                    :class="activeTab === 'settings' ? 'bg-surface-800/60 text-surface-100' : 'text-surface-400 hover:text-surface-200 hover:bg-surface-800/30'"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    Settings
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
                <button @click="togglePanel" class="mt-3 w-full btn-ghost text-xs justify-center relative">
                    Messages
                    <span v-if="totalUnread > 0" class="ml-1.5 min-w-[18px] h-[18px] rounded-full bg-brand inline-flex items-center justify-center px-1 animate-badge-blink">
                        <span class="text-[9px] font-bold text-surface-950 leading-none">{{ totalUnread > 99 ? '99+' : totalUnread }}</span>
                    </span>
                </button>
                <button @click="router.post('/logout')" class="mt-1 w-full btn-ghost text-xs justify-center">
                    Sign out
                </button>
            </div>
        </aside>

        <!-- Main content -->
        <div class="flex-1 flex flex-col min-h-screen overflow-hidden">
            <!-- Mobile header -->
            <header class="lg:hidden border-b border-surface-800/50 flex-shrink-0">
                <div class="h-14 px-4 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <PitchFlowLogo size="w-6 h-6" />
                        <span class="text-sm font-bold">PitchFlow</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="togglePanel" class="relative p-1.5 rounded-md text-surface-400 hover:text-surface-100 transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>
                            </svg>
                            <span v-if="totalUnread > 0" class="absolute -top-1 -right-1 min-w-[14px] h-[14px] rounded-full bg-brand flex items-center justify-center animate-badge-blink">
                                <span class="text-[8px] font-bold text-surface-950 leading-none">{{ totalUnread > 9 ? '9+' : totalUnread }}</span>
                            </span>
                        </button>
                        <button @click="router.post('/logout')" class="p-1.5 rounded-md text-surface-400 hover:text-red-400 transition-colors" title="Sign out">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3-3h-9m9 0l-3-3m3 3l-3 3" />
                            </svg>
                        </button>
                    </div>
                </div>
                <!-- Mobile tab bar -->
                <div class="px-4 pb-2 flex gap-1">
                    <button v-for="tab in ['pipeline', 'team', 'positions', 'reports', 'agency', 'settings']" :key="tab"
                        @click="activeTab = tab"
                        :class="activeTab === tab ? 'bg-surface-700 text-surface-100' : 'text-surface-400'"
                        class="flex-1 py-1.5 rounded-md text-xs font-medium capitalize transition-colors text-center">
                        {{ tab }}
                    </button>
                </div>
            </header>

            <!-- Top bar -->
            <header class="h-14 border-b border-surface-800/50 px-4 sm:px-6 flex items-center justify-between flex-shrink-0">
                <h1 class="text-base sm:text-lg font-semibold text-surface-100 capitalize">{{ activeTab }}</h1>
                <div class="flex items-center gap-2 sm:gap-3">
                    <div v-if="summary.conversion_rate > 0" class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-lg bg-surface-800/50 border border-surface-700/30">
                        <span class="text-xs text-surface-400">Conversion</span>
                        <span class="text-sm font-semibold text-brand">{{ summary.conversion_rate }}%</span>
                    </div>
                    <div class="flex items-center gap-1.5 sm:gap-2 px-2 sm:px-3 py-1.5 rounded-lg bg-surface-800/50 border border-surface-700/30">
                        <span class="text-[10px] sm:text-xs text-surface-400">Bids</span>
                        <span class="text-xs sm:text-sm font-semibold text-surface-200">{{ summary.total }}</span>
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
            <main v-else-if="activeTab === 'pipeline'" class="flex-1 p-4 sm:p-6 overflow-y-auto" :class="{ 'animate-fade-in': ready }">
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

                <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 min-h-0">
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
                                    <!-- Positions (multi-select) -->
                                    <div>
                                        <label class="block text-xs font-medium text-surface-400 mb-1.5">Position(s) *</label>
                                        <div class="input-field w-full min-h-[38px] flex flex-wrap gap-1.5 cursor-pointer"
                                            :class="{ 'border-red-500/50': addMemberErrors.position_ids }">
                                            <button v-for="p in activePositions" :key="p.id" type="button"
                                                @click="togglePositionSelection(p.id, addMemberForm.position_ids)"
                                                class="text-[11px] font-medium px-2 py-1 rounded-md transition-colors"
                                                :class="addMemberForm.position_ids.includes(p.id) ? 'bg-brand/20 text-brand ring-1 ring-brand/30' : 'bg-surface-700/50 text-surface-400 hover:text-surface-200'">
                                                {{ p.title }}
                                            </button>
                                        </div>
                                        <p v-if="addMemberErrors.position_ids" class="text-[11px] text-red-400 mt-1">{{ addMemberErrors.position_ids }}</p>
                                        <p v-if="activePositions.length === 0" class="text-[11px] text-amber-400 mt-1">No positions created yet. Create one in the Positions tab first.</p>
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
                                <!-- Positions (multi-select) -->
                                <div>
                                    <label class="block text-xs font-medium text-surface-400 mb-1.5">Position(s)</label>
                                    <div class="input-field w-full min-h-[38px] flex flex-wrap gap-1.5">
                                        <button v-for="p in activePositions" :key="p.id" type="button"
                                            @click="togglePositionSelection(p.id, editForm.position_ids)"
                                            class="text-[11px] font-medium px-2 py-1 rounded-md transition-colors"
                                            :class="editForm.position_ids.includes(p.id) ? 'bg-brand/20 text-brand ring-1 ring-brand/30' : 'bg-surface-700/50 text-surface-400 hover:text-surface-200'">
                                            {{ p.title }}
                                        </button>
                                    </div>
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
                        <div class="relative bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col animate-slide-up overflow-hidden">
                            <!-- Modal Header -->
                            <div class="flex items-center justify-between px-4 sm:px-6 py-3 sm:py-4 border-b border-surface-800/50 flex-shrink-0">
                                <div class="flex items-center gap-2.5 sm:gap-3 min-w-0">
                                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full flex items-center justify-center text-xs sm:text-sm font-bold flex-shrink-0"
                                        :class="getUserColor(viewMember.name)">
                                        {{ viewMember.name.charAt(0).toUpperCase() }}
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="text-base sm:text-lg font-semibold text-surface-100 truncate">{{ viewMember.name }}</h3>
                                        <p class="text-[10px] sm:text-xs text-surface-500 truncate">{{ viewMember.employee_id || 'No ID' }} &middot; {{ viewMember.positions && viewMember.positions.length > 0 ? viewMember.positions.map(p => p.title).join(', ') : (viewMember.designation || 'Bidder') }}</p>
                                    </div>
                                </div>
                                <button @click="closeMemberProfile()"
                                    class="w-8 h-8 rounded-lg bg-surface-800/50 flex items-center justify-center text-surface-400 hover:text-surface-200 hover:bg-surface-800 transition-colors flex-shrink-0">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Tab switcher -->
                            <div class="px-4 sm:px-6 pt-3 pb-0 flex gap-1 border-b border-surface-800/50 flex-shrink-0">
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
                            <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-4 sm:py-5">
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
                                    <div class="px-3 sm:px-4 py-2.5 sm:py-3 bg-surface-800/30 border-b border-surface-700/30 flex items-center justify-between">
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

                                    <!-- Enrollment video -->
                                    <div v-if="faceEnabled" class="px-3 sm:px-4 py-2 border-b border-surface-700/30 bg-surface-800/20 flex items-center justify-between">
                                        <span class="text-[10px] text-surface-400">Face Enrollment</span>
                                        <button v-if="enrollmentVideo" @click="openVideoModal(enrollmentVideo.id)" class="inline-flex items-center gap-1.5 text-[10px] text-brand hover:text-brand/80 transition-colors font-medium">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                                            Watch Enrollment Video
                                        </button>
                                        <span v-else class="inline-flex items-center gap-1.5 text-[10px] text-surface-500">
                                            <div class="w-3 h-3 border-[1.5px] border-surface-600 border-t-surface-400 rounded-full animate-spin"></div>
                                            Uploading...
                                        </span>
                                    </div>

                                    <!-- Loading -->
                                    <div v-if="memberAttLoading" class="flex items-center justify-center py-12">
                                        <div class="w-6 h-6 border-2 border-brand/30 border-t-brand rounded-full animate-spin"></div>
                                    </div>

                                    <!-- Attendance summary -->
                                    <div v-else-if="memberAttendance" class="p-3 sm:p-4">
                                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 sm:gap-3 mb-4">
                                            <div class="text-center p-2 rounded-lg bg-surface-800/30">
                                                <p class="text-base sm:text-lg font-bold text-emerald-400">{{ memberAttendance.summary.present_days }}</p>
                                                <p class="text-[10px] text-surface-500">Present</p>
                                            </div>
                                            <div class="text-center p-2 rounded-lg bg-surface-800/30">
                                                <p class="text-base sm:text-lg font-bold text-amber-400">{{ memberAttendance.summary.half_days || 0 }}</p>
                                                <p class="text-[10px] text-surface-500">Half Day</p>
                                            </div>
                                            <div class="text-center p-2 rounded-lg bg-surface-800/30">
                                                <p class="text-base sm:text-lg font-bold text-red-400">{{ memberAttendance.summary.absent_days }}</p>
                                                <p class="text-[10px] text-surface-500">Absent</p>
                                            </div>
                                            <div class="text-center p-2 rounded-lg bg-surface-800/30">
                                                <p class="text-base sm:text-lg font-bold text-surface-200">{{ formatHours(memberAttendance.summary.total_worked_hours) }}</p>
                                                <p class="text-[10px] text-surface-500">Total Hours</p>
                                            </div>
                                            <div class="text-center p-2 rounded-lg bg-surface-800/30">
                                                <p class="text-base sm:text-lg font-bold text-surface-200">{{ formatHours(memberAttendance.summary.avg_hours_per_day) }}</p>
                                                <p class="text-[10px] text-surface-500">Avg/Day</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2 mb-4 px-1">
                                            <span class="text-[10px] text-surface-500">Min hours:</span>
                                            <span class="text-xs font-semibold text-surface-300">{{ memberAttendance.min_hours_per_day }}h/day</span>
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
                                                <div v-if="day.hours > 0 && day.status !== 'future' && day.status !== 'na'" class="w-full h-1.5 rounded-full bg-surface-700/50 mt-1 overflow-hidden">
                                                    <div class="h-full rounded-full transition-all" :style="{ width: Math.min(day.pct, 100) + '%' }"
                                                        :class="day.pct >= 100 ? 'bg-emerald-400' : day.pct >= 50 ? 'bg-amber-400' : 'bg-red-400'"></div>
                                                </div>
                                                <div v-else class="w-2 h-2 rounded-full mx-auto mt-0.5" :class="statusDotColors[day.status] || 'bg-surface-700'"></div>
                                                <p v-if="day.hours > 0" class="text-[9px] text-surface-500 mt-0.5">{{ formatHours(day.hours) }}</p>
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
                                        <div v-if="dayEditOpen" class="mt-4 p-3 sm:p-4 rounded-xl bg-surface-800/50 border border-surface-700/30">
                                            <div class="flex items-center justify-between mb-3">
                                                <h4 class="text-xs sm:text-sm font-semibold text-surface-200">Edit: {{ dayEditOpen }}</h4>
                                                <button @click="dayEditOpen = null" class="text-xs text-surface-500 hover:text-surface-300">&times; Close</button>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 sm:gap-3">
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
                                            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 mt-3 pt-3 border-t border-surface-700/20">
                                                <label class="flex items-center gap-2 cursor-pointer group">
                                                    <div class="relative w-8 h-4 rounded-full transition-colors" :class="dayEditForm.show_status_to_member ? 'bg-brand' : 'bg-surface-700'" @click="dayEditForm.show_status_to_member = !dayEditForm.show_status_to_member">
                                                        <div class="absolute top-0.5 w-3 h-3 rounded-full bg-white shadow transition-transform" :class="dayEditForm.show_status_to_member ? 'translate-x-4' : 'translate-x-0.5'"></div>
                                                    </div>
                                                    <span class="text-[10px] text-surface-400 group-hover:text-surface-300">Show adjusted to member</span>
                                                </label>
                                                <label class="flex items-center gap-2 cursor-pointer group">
                                                    <div class="relative w-8 h-4 rounded-full transition-colors" :class="dayEditForm.show_note_to_member ? 'bg-brand' : 'bg-surface-700'" @click="dayEditForm.show_note_to_member = !dayEditForm.show_note_to_member">
                                                        <div class="absolute top-0.5 w-3 h-3 rounded-full bg-white shadow transition-transform" :class="dayEditForm.show_note_to_member ? 'translate-x-4' : 'translate-x-0.5'"></div>
                                                    </div>
                                                    <span class="text-[10px] text-surface-400 group-hover:text-surface-300">Show note to member</span>
                                                </label>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-2 mt-3">
                                                <button @click="saveDayEdit" :disabled="dayEditLoading"
                                                    class="btn-primary text-xs px-4 py-1.5">
                                                    {{ dayEditLoading ? 'Saving...' : 'Save' }}
                                                </button>
                                                <button @click="removeDayOverride(dayEditOpen)"
                                                    class="btn-ghost text-xs text-red-400 hover:text-red-300 px-3 py-1.5">
                                                    Remove Override
                                                </button>
                                            </div>

                                            <!-- Sessions & Locations -->
                                            <div v-if="selectedDaySessions.length > 0" class="mt-4 pt-3 border-t border-surface-700/30">
                                                <p class="text-[10px] font-semibold text-surface-500 uppercase tracking-wider mb-2">Sessions</p>
                                                <div class="space-y-3">
                                                    <div v-for="(s, si) in selectedDaySessions" :key="si" class="p-2.5 sm:p-3 rounded-lg bg-surface-900/50 border border-surface-700/20">
                                                        <div class="flex flex-wrap items-center gap-2 sm:gap-3 text-xs">
                                                            <span class="text-surface-500 w-3">{{ si + 1 }}.</span>
                                                            <span class="font-mono text-emerald-400">{{ s.in ? new Date(s.in).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '--' }}</span>
                                                            <button v-if="getSessionVideos(s).punch_in.length" @click="openSessionVideoPopup(s, 'in')" class="p-0.5 rounded transition-colors" :class="getSessionVideos(s).punch_in.some(v => !v.verified) ? 'text-amber-400/70 hover:text-amber-400' : 'text-emerald-400/60 hover:text-emerald-400'" :title="`${getSessionVideos(s).punch_in.length} video(s)`">
                                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                                                            </button>
                                                            <span v-else-if="getSessionVideos(s).punch_in_pending" class="p-0.5" title="Video uploading...">
                                                                <div class="w-3.5 h-3.5 border-[1.5px] border-emerald-400/20 border-t-emerald-400/60 rounded-full animate-spin"></div>
                                                            </span>
                                                            <svg class="w-3 h-3 text-surface-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                                                            <span class="font-mono" :class="s.out ? 'text-red-400' : 'text-emerald-400'">{{ s.out ? new Date(s.out).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'Active' }}</span>
                                                            <button v-if="getSessionVideos(s).punch_out.length" @click="openSessionVideoPopup(s, 'out')" class="p-0.5 rounded transition-colors" :class="getSessionVideos(s).punch_out.some(v => !v.verified) ? 'text-amber-400/70 hover:text-amber-400' : 'text-red-400/60 hover:text-red-400'" :title="`${getSessionVideos(s).punch_out.length} video(s)`">
                                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                                                            </button>
                                                            <span v-else-if="getSessionVideos(s).punch_out_pending" class="p-0.5" title="Video uploading...">
                                                                <div class="w-3.5 h-3.5 border-[1.5px] border-red-400/20 border-t-red-400/60 rounded-full animate-spin"></div>
                                                            </span>
                                                            <span class="text-surface-500 ml-auto">{{ formatHours(s.hours) }}</span>
                                                        </div>
                                                        <!-- Punch In Location & Device -->
                                                        <div v-if="s.in_location || s.in_ip" class="mt-2 flex items-start gap-2">
                                                            <span class="text-[9px] font-semibold text-emerald-400/70 uppercase w-6 flex-shrink-0 pt-0.5">IN</span>
                                                            <div class="flex-1 min-w-0">
                                                                <p v-if="s.in_location" class="text-[10px] text-surface-400 leading-relaxed truncate" :title="s.in_location.address">{{ s.in_location.address || `${s.in_location.lat}, ${s.in_location.lng}` }}</p>
                                                                <a v-if="s.in_location" :href="`https://www.google.com/maps?q=${s.in_location.lat},${s.in_location.lng}`" target="_blank"
                                                                    class="inline-flex items-center gap-1 text-[9px] text-brand hover:underline mt-0.5">
                                                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                                                    View on map
                                                                </a>
                                                                <div v-if="s.in_ip || s.in_device" class="flex items-center gap-1.5 mt-1 text-[9px] text-surface-500">
                                                                    <svg class="w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25A2.25 2.25 0 0 1 5.25 3h13.5A2.25 2.25 0 0 1 21 5.25Z"/></svg>
                                                                    <span v-if="s.in_ip" class="font-mono">{{ s.in_ip }}</span>
                                                                    <span v-if="s.in_ip && s.in_device" class="text-surface-600">·</span>
                                                                    <span v-if="s.in_device" :title="s.in_device">{{ parseDevice(s.in_device) }}</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <!-- Punch Out Location & Device -->
                                                        <div v-if="s.out_location || s.out_ip" class="mt-1.5 flex items-start gap-2">
                                                            <span class="text-[9px] font-semibold text-red-400/70 uppercase w-6 flex-shrink-0 pt-0.5">OUT</span>
                                                            <div class="flex-1 min-w-0">
                                                                <p v-if="s.out_location" class="text-[10px] text-surface-400 leading-relaxed truncate" :title="s.out_location.address">{{ s.out_location.address || `${s.out_location.lat}, ${s.out_location.lng}` }}</p>
                                                                <a v-if="s.out_location" :href="`https://www.google.com/maps?q=${s.out_location.lat},${s.out_location.lng}`" target="_blank"
                                                                    class="inline-flex items-center gap-1 text-[9px] text-brand hover:underline mt-0.5">
                                                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                                                    View on map
                                                                </a>
                                                                <div v-if="s.out_ip || s.out_device" class="flex items-center gap-1.5 mt-1 text-[9px] text-surface-500">
                                                                    <svg class="w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25A2.25 2.25 0 0 1 5.25 3h13.5A2.25 2.25 0 0 1 21 5.25Z"/></svg>
                                                                    <span v-if="s.out_ip" class="font-mono">{{ s.out_ip }}</span>
                                                                    <span v-if="s.out_ip && s.out_device" class="text-surface-600">·</span>
                                                                    <span v-if="s.out_device" :title="s.out_device">{{ parseDevice(s.out_device) }}</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div v-if="!s.in_location && !s.out_location && !s.in_ip && !s.out_ip" class="mt-1.5">
                                                            <p class="text-[10px] text-surface-600 italic">No location data</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <!-- Face Video Player Modal (single video - for enrollment) -->
                <Teleport to="body">
                    <div v-if="faceVideoModalUrl" class="fixed inset-0 z-[110] flex items-center justify-center bg-black/80 backdrop-blur-md" @click.self="closeVideoModal">
                        <div class="bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
                            <div class="px-5 py-3 border-b border-surface-800/50 flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-surface-200">Face Enrollment Recording</h3>
                                <button @click="closeVideoModal" class="text-surface-500 hover:text-surface-300 transition-colors">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="p-4">
                                <video :src="faceVideoModalUrl" controls autoplay class="w-full rounded-xl bg-black"></video>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <!-- Session Video Attempts Popup -->
                <Teleport to="body">
                    <div v-if="sessionVideoPopup" class="fixed inset-0 z-[110] flex items-center justify-center bg-black/80 backdrop-blur-md" @click.self="closeSessionVideoPopup">
                        <div class="bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
                            <div class="px-5 py-3 border-b border-surface-800/50 flex items-center justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold text-surface-200">
                                        Punch {{ sessionVideoPopup.punchType === 'in' ? 'In' : 'Out' }} Verification
                                    </h3>
                                    <p class="text-[10px] text-surface-500 mt-0.5">
                                        {{ sessionVideoPopup.time ? new Date(sessionVideoPopup.time).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '' }}
                                        &middot; {{ sessionVideoPopup.videos.length }} attempt{{ sessionVideoPopup.videos.length !== 1 ? 's' : '' }}
                                    </p>
                                </div>
                                <button @click="closeSessionVideoPopup" class="text-surface-500 hover:text-surface-300 transition-colors">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="max-h-[70vh] overflow-y-auto scrollbar-thin">
                                <div v-for="(vid, vi) in sessionVideoPopup.videos" :key="vid.id" class="border-b border-surface-800/30 last:border-0">
                                    <div class="px-5 pt-3 pb-1 flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span class="text-[10px] font-mono text-surface-500">Attempt {{ vi + 1 }}</span>
                                            <span v-if="vid.verified" class="inline-flex items-center gap-1 text-[10px] font-medium text-emerald-400 bg-emerald-400/10 px-1.5 py-0.5 rounded">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                                Verified
                                            </span>
                                            <span v-else class="inline-flex items-center gap-1 text-[10px] font-medium text-red-400 bg-red-400/10 px-1.5 py-0.5 rounded">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                                Failed
                                            </span>
                                        </div>
                                        <span class="text-[10px] text-surface-600">{{ new Date(vid.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }) }}</span>
                                    </div>
                                    <div class="px-5 pb-3">
                                        <video :src="`/api/face/video/${vid.id}`" controls preload="metadata" class="w-full rounded-lg bg-black mt-1"></video>
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
                                    <th class="px-4 py-3 text-left table-header">Position</th>
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
                                        <div class="flex flex-wrap gap-1">
                                            <span v-if="bidder.positions && bidder.positions.length > 0"
                                                v-for="pos in bidder.positions" :key="pos.id"
                                                class="text-[11px] font-medium px-2 py-0.5 rounded-md"
                                                :class="getPositionBadge(pos.title)">
                                                {{ pos.title }}
                                            </span>
                                            <span v-else class="text-[11px] font-medium px-2 py-0.5 rounded-md bg-surface-700/50 text-surface-400">
                                                {{ bidder.designation || 'Unassigned' }}
                                            </span>
                                        </div>
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
                                        {{ formatHours(bidder.today_hours) }}
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

            <!-- ========== POSITIONS TAB ========== -->
            <main v-else-if="activeTab === 'positions'" class="flex-1 p-6 overflow-auto" :class="{ 'animate-fade-in': ready }">
                <div class="max-w-3xl">
                    <!-- Header -->
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-lg font-semibold text-surface-100">Manage Positions</h2>
                            <p class="text-sm text-surface-400 mt-0.5">Create custom positions for your team members</p>
                        </div>
                        <button @click="showAddPosition = true; resetPositionForm()"
                            class="btn-primary text-sm py-2 px-4 flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Add Position
                        </button>
                    </div>

                    <!-- Loading -->
                    <div v-if="positionsLoading && positions.length === 0" class="flex items-center justify-center py-16">
                        <div class="w-6 h-6 border-2 border-brand/30 border-t-brand rounded-full animate-spin"></div>
                    </div>

                    <!-- Empty state -->
                    <div v-else-if="positions.length === 0" class="flex flex-col items-center justify-center py-16">
                        <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-surface-800/50 flex items-center justify-center">
                            <svg class="w-8 h-8 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0M12 12.75h.008v.008H12v-.008z" />
                            </svg>
                        </div>
                        <p class="text-surface-300 font-medium">No positions created yet</p>
                        <p class="text-sm text-surface-500 mt-1">Create positions like Developer, Senior Developer, etc.</p>
                        <button @click="showAddPosition = true; resetPositionForm()"
                            class="mt-4 btn-primary text-sm py-2 px-4">Create Your First Position</button>
                    </div>

                    <!-- Positions list -->
                    <div v-else class="space-y-1.5">
                        <div v-for="(pos, idx) in positions" :key="pos.id"
                            data-pos-item
                            draggable="true"
                            @dragstart="onPosDragStart($event, pos)"
                            @dragend="onPosDragEnd"
                            @dragover="onPosDragOver($event, pos)"
                            @dragleave="onPosDragLeave($event, pos)"
                            @drop="onPosDrop($event, pos)"
                            class="card p-4 flex items-center gap-4 group hover:border-surface-600/50 transition-all cursor-grab active:cursor-grabbing"
                            :class="{ 'border-brand/40 bg-brand/5': dragOverPosId === pos.id && dragPosId !== pos.id }">
                            <!-- Drag handle + rank -->
                            <div class="flex flex-col items-center gap-0.5 flex-shrink-0 w-6">
                                <svg class="w-4 h-4 text-surface-600 group-hover:text-surface-400 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5" />
                                </svg>
                                <span class="text-[10px] font-mono text-surface-500">{{ idx + 1 }}</span>
                            </div>
                            <div class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center text-sm font-bold"
                                :class="getPositionBadge(pos.title)">
                                {{ pos.title.charAt(0).toUpperCase() }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-semibold text-surface-100 truncate">{{ pos.title }}</h3>
                                    <span v-if="!pos.is_active" class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-red-500/10 text-red-400">Inactive</span>
                                </div>
                                <p v-if="pos.description" class="text-xs text-surface-400 mt-0.5 truncate">{{ pos.description }}</p>
                                <p class="text-[10px] text-surface-500 mt-1">{{ pos.users_count }} member{{ pos.users_count !== 1 ? 's' : '' }} assigned</p>
                            </div>
                            <div class="flex items-center gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button @click="togglePositionActive(pos)"
                                    :title="pos.is_active ? 'Deactivate' : 'Activate'"
                                    class="w-8 h-8 rounded-lg flex items-center justify-center text-surface-400 hover:text-surface-200 hover:bg-surface-800 transition-colors">
                                    <svg v-if="pos.is_active" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                    </svg>
                                    <svg v-else class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                </button>
                                <button @click="openEditPosition(pos)"
                                    class="w-8 h-8 rounded-lg flex items-center justify-center text-surface-400 hover:text-surface-200 hover:bg-surface-800 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                    </svg>
                                </button>
                                <button @click="deleteConfirm = pos"
                                    class="w-8 h-8 rounded-lg flex items-center justify-center text-surface-400 hover:text-red-400 hover:bg-red-500/10 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Add Position Modal -->
                <Teleport to="body">
                    <div v-if="showAddPosition" class="fixed inset-0 z-[100] flex items-center justify-center p-4"
                        @click.self="showAddPosition = false; resetPositionForm()">
                        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
                        <div class="relative bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl w-full max-w-md animate-slide-up">
                            <div class="flex items-center justify-between px-6 py-4 border-b border-surface-800/50">
                                <h3 class="text-lg font-semibold text-surface-100">Add Position</h3>
                                <button @click="showAddPosition = false; resetPositionForm()"
                                    class="w-8 h-8 rounded-lg bg-surface-800/50 flex items-center justify-center text-surface-400 hover:text-surface-200 hover:bg-surface-800 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                            <form @submit.prevent="addPosition" class="px-6 py-5 space-y-4">
                                <div>
                                    <label class="block text-xs font-medium text-surface-400 mb-1.5">Title *</label>
                                    <input v-model="addPositionForm.title" type="text" placeholder="e.g. Senior Developer"
                                        class="input-field w-full" :class="{ 'border-red-500/50': addPositionErrors.title }" />
                                    <p v-if="addPositionErrors.title" class="text-[11px] text-red-400 mt-1">{{ addPositionErrors.title }}</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-surface-400 mb-1.5">Description</label>
                                    <textarea v-model="addPositionForm.description" rows="3"
                                        placeholder="Brief description of this position (optional)"
                                        class="input-field w-full resize-y text-sm"></textarea>
                                </div>
                                <div class="flex items-center justify-end gap-3 pt-3 border-t border-surface-800/50">
                                    <button type="button" @click="showAddPosition = false; resetPositionForm()"
                                        class="btn-ghost text-sm px-4 py-2">Cancel</button>
                                    <button type="submit" :disabled="addPositionLoading"
                                        class="btn-primary text-sm px-5 py-2.5 justify-center min-w-[120px]">
                                        <span v-if="addPositionLoading" class="flex items-center gap-2">
                                            <div class="w-3.5 h-3.5 border-2 border-surface-950/30 border-t-surface-950 rounded-full animate-spin"></div>
                                            Adding...
                                        </span>
                                        <span v-else>Add Position</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </Teleport>

                <!-- Edit Position Modal -->
                <Teleport to="body">
                    <div v-if="editPosition" class="fixed inset-0 z-[100] flex items-center justify-center p-4"
                        @click.self="editPosition = null">
                        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
                        <div class="relative bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl w-full max-w-md animate-slide-up">
                            <div class="flex items-center justify-between px-6 py-4 border-b border-surface-800/50">
                                <h3 class="text-lg font-semibold text-surface-100">Edit Position</h3>
                                <button @click="editPosition = null"
                                    class="w-8 h-8 rounded-lg bg-surface-800/50 flex items-center justify-center text-surface-400 hover:text-surface-200 hover:bg-surface-800 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                            <form @submit.prevent="saveEditPosition" class="px-6 py-5 space-y-4">
                                <div>
                                    <label class="block text-xs font-medium text-surface-400 mb-1.5">Title *</label>
                                    <input v-model="editPositionForm.title" type="text"
                                        class="input-field w-full" :class="{ 'border-red-500/50': editPositionErrors.title }" />
                                    <p v-if="editPositionErrors.title" class="text-[11px] text-red-400 mt-1">{{ editPositionErrors.title }}</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-surface-400 mb-1.5">Description</label>
                                    <textarea v-model="editPositionForm.description" rows="3"
                                        class="input-field w-full resize-y text-sm"></textarea>
                                </div>
                                <div class="rounded-lg bg-surface-800/40 border border-surface-700/20 p-3 text-xs">
                                    <span class="text-surface-500">Members assigned</span>
                                    <p class="text-surface-200 font-mono mt-0.5">{{ editPosition.users_count }}</p>
                                </div>
                                <div class="flex items-center justify-end gap-3 pt-3 border-t border-surface-800/50">
                                    <button type="button" @click="editPosition = null" class="btn-ghost text-sm px-4 py-2">Cancel</button>
                                    <button type="submit" :disabled="editPositionLoading"
                                        class="btn-primary text-sm px-5 py-2.5 justify-center min-w-[100px]">
                                        <span v-if="editPositionLoading" class="flex items-center gap-2">
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

                <!-- Delete Confirm Modal -->
                <Teleport to="body">
                    <div v-if="deleteConfirm" class="fixed inset-0 z-[100] flex items-center justify-center p-4"
                        @click.self="deleteConfirm = null">
                        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
                        <div class="relative bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl w-full max-w-sm animate-slide-up p-6">
                            <div class="flex flex-col items-center text-center">
                                <div class="w-12 h-12 rounded-full bg-red-500/10 flex items-center justify-center mb-4">
                                    <svg class="w-6 h-6 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                    </svg>
                                </div>
                                <h3 class="text-base font-semibold text-surface-100">Delete Position</h3>
                                <p class="text-sm text-surface-400 mt-2">Are you sure you want to delete <span class="font-medium text-surface-200">"{{ deleteConfirm.title }}"</span>?</p>
                                <p v-if="deleteConfirm.users_count > 0" class="text-xs text-amber-400 mt-2">This position has {{ deleteConfirm.users_count }} member(s) assigned. Reassign them first.</p>
                            </div>
                            <div class="flex items-center justify-center gap-3 mt-6">
                                <button @click="deleteConfirm = null" class="btn-ghost text-sm px-4 py-2">Cancel</button>
                                <button @click="deletePosition(deleteConfirm)" :disabled="deleteLoading || deleteConfirm.users_count > 0"
                                    class="text-sm px-5 py-2.5 rounded-lg font-medium transition-colors"
                                    :class="deleteConfirm.users_count > 0 ? 'bg-surface-700 text-surface-500 cursor-not-allowed' : 'bg-red-500/10 text-red-400 hover:bg-red-500/20'">
                                    <span v-if="deleteLoading" class="flex items-center gap-2">
                                        <div class="w-3.5 h-3.5 border-2 border-red-400/30 border-t-red-400 rounded-full animate-spin"></div>
                                        Deleting...
                                    </span>
                                    <span v-else>Delete</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>
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
                                    <td class="px-6 py-3 text-center font-mono text-surface-300">{{ formatHours(bidder.today_hours) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>

            <!-- Agency Profile Tab -->
            <main v-else-if="activeTab === 'agency'" class="flex-1 p-6 overflow-auto" :class="{ 'animate-fade-in': ready }">
                <div class="max-w-2xl">
                    <p class="text-sm text-surface-400 mb-6">Define your agency's capabilities. The AI Job Analyzer will use this to check if jobs match your team's skills.</p>

                    <div class="space-y-5">
                        <div>
                            <label class="block text-xs font-medium text-surface-400 mb-1.5">Agency Description</label>
                            <textarea v-model="agencyProfile.description" rows="3"
                                placeholder="e.g. We are a full-stack web development agency specializing in SaaS products, e-commerce, and enterprise solutions."
                                class="input-field text-sm leading-relaxed resize-y"></textarea>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-surface-400 mb-1.5">Skills</label>
                            <input v-model="agencyProfile.skills" type="text"
                                placeholder="e.g. Web Development, Mobile Apps, UI/UX Design, API Integration, DevOps"
                                class="input-field text-sm" />
                            <p class="text-[11px] text-surface-500 mt-1">Comma-separated list of your agency's core skills</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-surface-400 mb-1.5">Tech Stack</label>
                            <input v-model="agencyProfile.tech_stack" type="text"
                                placeholder="e.g. Laravel, Vue.js, React, Node.js, Flutter, AWS, Docker, MySQL, PostgreSQL"
                                class="input-field text-sm" />
                            <p class="text-[11px] text-surface-500 mt-1">Technologies and frameworks your team works with</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-surface-400 mb-1.5">What We Can Build</label>
                            <textarea v-model="agencyProfile.can_build" rows="3"
                                placeholder="e.g. SaaS platforms, e-commerce stores, CRM/ERP systems, REST APIs, mobile apps, admin dashboards, payment integrations, real-time chat systems"
                                class="input-field text-sm leading-relaxed resize-y"></textarea>
                        </div>

                        <div class="flex items-center gap-3">
                            <button @click="saveAgencyProfile" :disabled="agencySaving"
                                class="btn-primary text-sm py-2.5 px-6" :class="{ 'opacity-50': agencySaving }">
                                {{ agencySaving ? 'Saving...' : 'Save Profile' }}
                            </button>
                            <span v-if="agencySaved" class="text-sm text-emerald-400 font-medium">Saved!</span>
                        </div>
                    </div>
                </div>
            </main>

            <!-- ========== SETTINGS TAB ========== -->
            <main v-else-if="activeTab === 'settings'" class="flex-1 p-6 overflow-auto" :class="{ 'animate-fade-in': ready }">
                <div class="max-w-xl space-y-6">
                    <!-- Profile Info -->
                    <div class="card p-6">
                        <h2 class="text-base font-semibold text-surface-100 mb-1">Profile Information</h2>
                        <p class="text-sm text-surface-400 mb-5">Update your photo, name and email address.</p>

                        <div class="space-y-4">
                            <!-- Profile Picture -->
                            <div class="flex items-center gap-4">
                                <div class="relative group cursor-pointer" @click="$refs.profilePicInput.click()">
                                    <div class="w-20 h-20 rounded-full overflow-hidden ring-2 ring-surface-700 bg-surface-800 flex items-center justify-center">
                                        <img v-if="profilePicPreview" :src="profilePicPreview" class="w-full h-full object-cover" draggable="false" @contextmenu.prevent />
                                        <span v-else class="text-2xl font-bold text-surface-500">{{ (props.auth.user.name || '?')[0].toUpperCase() }}</span>
                                    </div>
                                    <div class="absolute inset-0 rounded-full bg-black/50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                    </div>
                                </div>
                                <div>
                                    <button @click="$refs.profilePicInput.click()" class="text-sm text-brand hover:text-brand/80 font-medium">
                                        {{ profilePicPreview ? 'Change Photo' : 'Upload Photo' }}
                                    </button>
                                    <p class="text-xs text-surface-500 mt-0.5">JPG, PNG up to 5MB</p>
                                </div>
                                <input ref="profilePicInput" type="file" accept="image/*" class="hidden" @change="handleProfilePic" />
                            </div>
                            <p v-if="settingsErrors.profile_picture" class="text-xs text-red-400 -mt-2">{{ settingsErrors.profile_picture[0] }}</p>

                            <div>
                                <label class="block text-xs font-medium text-surface-400 mb-1.5">Name</label>
                                <input v-model="settingsForm.name" type="text" class="input-field text-sm" placeholder="Your name" />
                                <p v-if="settingsErrors.name" class="text-xs text-red-400 mt-1">{{ settingsErrors.name[0] }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-surface-400 mb-1.5">Login Email</label>
                                <input v-model="settingsForm.email" type="email" class="input-field text-sm" placeholder="you@company.com" />
                                <p v-if="settingsErrors.email" class="text-xs text-red-400 mt-1">{{ settingsErrors.email[0] }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-surface-400 mb-1.5">Notification Email</label>
                                <input v-model="settingsForm.notification_email" type="email" class="input-field text-sm" placeholder="notifications@company.com (optional)" />
                                <p class="text-[11px] text-surface-500 mt-1">If set, all notifications will be sent to this email instead of your login email.</p>
                                <p v-if="settingsErrors.notification_email" class="text-xs text-red-400 mt-1">{{ settingsErrors.notification_email[0] }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <button @click="saveSettings" :disabled="settingsSaving"
                                    class="btn-primary text-sm py-2.5 px-6" :class="{ 'opacity-50': settingsSaving }">
                                    {{ settingsSaving ? 'Saving...' : 'Save Changes' }}
                                </button>
                                <span v-if="settingsSaved" class="text-sm text-emerald-400 font-medium">Saved!</span>
                            </div>
                        </div>
                    </div>

                    <!-- Change Password -->
                    <div class="card p-6">
                        <h2 class="text-base font-semibold text-surface-100 mb-1">Change Password</h2>
                        <p class="text-sm text-surface-400 mb-5">Use a strong, unique password to keep your account secure.</p>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-medium text-surface-400 mb-1.5">Current Password</label>
                                <input v-model="settingsForm.current_password" type="password" class="input-field text-sm" placeholder="Enter current password" />
                                <p v-if="passwordErrors.current_password" class="text-xs text-red-400 mt-1">{{ passwordErrors.current_password[0] }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-surface-400 mb-1.5">New Password</label>
                                <input v-model="settingsForm.password" type="password" class="input-field text-sm" placeholder="Enter new password" />
                                <p v-if="passwordErrors.password" class="text-xs text-red-400 mt-1">{{ passwordErrors.password[0] }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-surface-400 mb-1.5">Confirm New Password</label>
                                <input v-model="settingsForm.password_confirmation" type="password" class="input-field text-sm" placeholder="Confirm new password" />
                            </div>
                            <div class="flex items-center gap-3">
                                <button @click="savePassword" :disabled="passwordSaving"
                                    class="btn-primary text-sm py-2.5 px-6" :class="{ 'opacity-50': passwordSaving }">
                                    {{ passwordSaving ? 'Updating...' : 'Update Password' }}
                                </button>
                                <span v-if="passwordSaved" class="text-sm text-emerald-400 font-medium">Password updated!</span>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
        <MessagingPanel />
    </div>
</template>
