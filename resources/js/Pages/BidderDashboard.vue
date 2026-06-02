<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PitchFlowLogo from '@/Components/PitchFlowLogo.vue';
import MessagingPanel from '@/Components/Messaging/MessagingPanel.vue';
import { useMessaging } from '@/composables/useMessaging';
import { useTabBadge } from '@/composables/useTabBadge';
import axios from 'axios';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import { VueTelInput } from 'vue-tel-input';
import 'vue-tel-input/vue-tel-input.css';
import { vProtectedSrc } from '@/directives/protectedSrc';
import FaceEnrollmentModal from '@/Components/FaceEnrollmentModal.vue';
import FaceScanCapture from '@/Components/FaceScanCapture.vue';
import GlobalMessagePopup from '@/Components/GlobalMessagePopup.vue';
import { useGlobalMessages } from '@/composables/useGlobalMessages';
import { useWebcam } from '@/composables/useWebcam';
import { useVideoUploader } from '@/composables/useVideoUploader';

const props = defineProps(['auth', 'impersonating', 'face_recognition']);

const { totalUnread, fetchUnreadCount, handleIncomingMessage, handleTypingEvent, handleReadEvent, handlePresenceEvent, togglePanel, startHeartbeat, openConversationByHash, startConversation, openPanel, setActiveConversation, fetchConversations, conversations } = useMessaging();
const { handleGlobalMessageEvent, init: initGlobalMessages, myAnnouncements, myAnnouncementsLoading, fetchMyAnnouncements, toggleReaction, fetchReactions, fetchComments, addComment } = useGlobalMessages();
useTabBadge(totalUnread, 'Dashboard - PitchFlow');

const showAnnouncementsPanel = ref(false);
const expandedAnnouncement = ref(null);
const announcementReactions = ref({});
const announcementComments = ref({});
const commentInput = ref({});
const availableEmojis = ['👍', '❤️', '🎉', '👀', '🚀', '💯'];

function renderAnnouncementBody(body) {
    if (!body) return '';
    return body.replace(
        /<span[^>]*data-type="mention"[^>]*data-id="(\d+)"[^>]*>/g,
        (match, id) => match.replace('>', ` style="color:#84cc16;cursor:pointer;font-weight:500;" data-user-id="${id}">`)
    );
}

function onAnnouncementBodyClick(e) {
    const el = e.target.closest('[data-user-id]');
    if (el) {
        const userId = parseInt(el.dataset.userId);
        if (userId) {
            showAnnouncementsPanel.value = false;
            openChatFromAnnouncement(userId);
        }
    }
}

async function openChatFromAnnouncement(userId) {
    try {
        const conversationId = await startConversation(userId);
        await fetchConversations();
        openPanel();
        const conv = conversations.value.find(c => c.id === conversationId);
        if (conv) setActiveConversation(conv);
    } catch {}
}

watch(showAnnouncementsPanel, (open) => {
    if (open) fetchMyAnnouncements();
});

async function toggleAnnouncementExpand(msgId) {
    if (expandedAnnouncement.value === msgId) {
        expandedAnnouncement.value = null;
        return;
    }
    expandedAnnouncement.value = msgId;
    const [reactionsData, commentsData] = await Promise.all([
        fetchReactions(msgId),
        fetchComments(msgId),
    ]);
    announcementReactions.value[msgId] = reactionsData;
    announcementComments.value[msgId] = commentsData;
}

async function handleReaction(msgId, emoji) {
    await toggleReaction(msgId, emoji);
    const data = await fetchReactions(msgId);
    announcementReactions.value[msgId] = data;
}

async function submitComment(msgId) {
    const body = (commentInput.value[msgId] || '').trim();
    if (!body) return;
    const comment = await addComment(msgId, body);
    if (comment) {
        if (!announcementComments.value[msgId]) announcementComments.value[msgId] = [];
        announcementComments.value[msgId].push(comment);
        commentInput.value[msgId] = '';
    }
}

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

const showPunchModal = ref(false);
const punchLocation = ref(null);
const punchLocationError = ref('');
const punchLocationDenied = ref(false);
const punchLoading = ref(false);
const punchLocating = ref(false);

// Face recognition
const faceEnabled = computed(() => props.face_recognition?.enabled === true);
const faceEnrolled = ref(props.face_recognition?.enrolled === true);
const showFaceEnrollment = computed(() => faceEnabled.value && !faceEnrolled.value);
const punchStep = ref('location');
const faceVerifying = ref(false);
const faceVerified = ref(false);
const faceError = ref('');
const punchSuccess = ref(false);
const punchSuccessType = ref('');
let punchSuccessLogId = null;
const faceAnalyzing = ref(false);
const faceAnalyzePhase = ref(0);
const faceAnalyzeLabels = [
    'Detecting face position...',
    'Mapping facial landmarks...',
    'Calculating face dimensions...',
    'Measuring inter-pupil distance...',
    'Analyzing biometric markers...',
    'Computing facial symmetry...',
    'Verifying identity match...',
];
const faceMetricsPhase = ref(0);
const faceMetricsLabels = [
    'Recording identity metrics...',
    'Capturing face depth data...',
    'Analyzing skin texture map...',
    'Logging biometric signature...',
    'Refining facial model...',
    'Updating identity profile...',
];
let analyzeInterval = null;
let metricsInterval = null;
let continuousUploadInterval = null;
let delayPunchSuccess = false;
const { videoRef: punchVideoRef, isStreaming: punchCamStreaming, error: punchCamError, startCamera: startPunchCam, stopCamera: stopPunchCam, captureFrame: capturePunchFrame, startRecording: startPunchRecording, stopRecording: stopPunchRecording } = useWebcam();
const { queueUpload: queueVideoUpload } = useVideoUploader();

function onFaceEnrolled() {
    faceEnrolled.value = true;
    router.reload({ only: ['face_recognition'] });
}

let punchRecordingActive = false;
let pendingAttemptClips = [];
const punchMapRef = ref(null);

async function closePunchModal() {
    if (punchRecordingActive) {
        const clip = await stopPunchRecording();
        punchRecordingActive = false;
        if (clip) pendingAttemptClips.push({ blob: clip, verified: false });
    }
    if (pendingAttemptClips.length) {
        const type = isPunchedIn.value ? 'punch_out' : 'punch_in';
        for (const entry of pendingAttemptClips) {
            queueVideoUpload(entry.blob, type, null, entry.verified);
        }
        pendingAttemptClips = [];
    }
    stopPunchCam();
    showPunchModal.value = false;
}

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

function openPunchModal() {
    if (faceEnabled.value && !faceEnrolled.value) return;
    punchLocation.value = null;
    punchLocationError.value = '';
    punchLocationDenied.value = false;
    punchLoading.value = false;
    punchStep.value = 'location';
    faceError.value = '';
    faceVerified.value = false;
    punchSuccess.value = false;
    punchSuccessType.value = '';
    punchSuccessLogId = null;
    showPunchModal.value = true;
    requestLocation();
}

function requestLocation() {
    if (!navigator.geolocation) {
        punchLocationError.value = 'Geolocation is not supported by your browser.';
        return;
    }

    punchLocating.value = true;
    punchLocationError.value = '';
    punchLocationDenied.value = false;

    if (navigator.permissions) {
        navigator.permissions.query({ name: 'geolocation' }).then(perm => {
            perm.onchange = () => {
                if (perm.state === 'granted' || perm.state === 'prompt') {
                    punchLocationDenied.value = false;
                    punchLocationError.value = '';
                    requestLocation();
                }
            };
        }).catch(() => {});
    }

    navigator.geolocation.getCurrentPosition(
        async (pos) => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            let address = '';
            try {
                const resp = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&addressdetails=1`, {
                    headers: { 'Accept-Language': 'en' },
                });
                const geo = await resp.json();
                address = geo.display_name || '';
            } catch {}
            punchLocation.value = { lat, lng, address };
            punchLocating.value = false;
            initPunchMap(lat, lng);
        },
        (err) => {
            punchLocating.value = false;
            if (err.code === 1) {
                punchLocationDenied.value = true;
                punchLocationError.value = 'Location access is blocked.';
            }
            else if (err.code === 2) punchLocationError.value = 'Location unavailable. Please try again.';
            else punchLocationError.value = 'Location request timed out. Please try again.';
        },
        { enableHighAccuracy: true, timeout: 15000 }
    );
}

function initPunchMap(lat, lng) {
    nextTick(() => {
        const container = punchMapRef.value;
        if (!container) return;
        container.innerHTML = `<iframe width="100%" height="100%" frameborder="0" style="border:0;border-radius:12px;" src="https://www.openstreetmap.org/export/embed.html?bbox=${lng - 0.005},${lat - 0.003},${lng + 0.005},${lat + 0.003}&layer=mapnik&marker=${lat},${lng}" allowfullscreen></iframe>`;
    });
}

async function confirmPunch() {
    if (!punchLocation.value || punchLoading.value) return;

    if (faceEnabled.value && faceEnrolled.value && punchStep.value === 'location') {
        punchStep.value = 'face';
        faceError.value = '';
        await startPunchCam();
        startPunchRecording();
        punchRecordingActive = true;
        return;
    }

    await executePunch();
}

function enhanceFrame(base64) {
    return new Promise((resolve) => {
        const img = new Image();
        img.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = img.width;
            canvas.height = img.height;
            const ctx = canvas.getContext('2d');
            ctx.filter = 'brightness(1.15) contrast(1.1)';
            ctx.drawImage(img, 0, 0);
            resolve(canvas.toDataURL('image/jpeg', 0.9));
        };
        img.onerror = () => resolve(base64);
        img.src = base64;
    });
}

function detectBackgroundFilter(videoEl) {
    return new Promise((resolve) => {
        if (!videoEl || !videoEl.videoWidth) { resolve(false); return; }
        const w = videoEl.videoWidth;
        const h = videoEl.videoHeight;

        const grabFrame = () => {
            const c = document.createElement('canvas');
            c.width = w; c.height = h;
            const ctx = c.getContext('2d');
            ctx.drawImage(videoEl, 0, 0);
            return ctx.getImageData(0, 0, w, h).data;
        };

        const frame1 = grabFrame();

        setTimeout(() => {
            const frame2 = grabFrame();

            setTimeout(() => {
                const frame3 = grabFrame();
                const flags = [];

                // Check 1: Temporal stability — border regions too static between frames
                const edgeW = Math.floor(w * 0.18);
                const topH = Math.floor(h * 0.22);
                let borderTotal = 0, borderZero12 = 0, borderZero23 = 0;

                for (let y = 0; y < h; y += 2) {
                    for (let x = 0; x < w; x += 2) {
                        if (x >= edgeW && x < w - edgeW && y >= topH) continue;
                        const i = (y * w + x) * 4;
                        const d12 = Math.abs(frame1[i] - frame2[i]) + Math.abs(frame1[i+1] - frame2[i+1]) + Math.abs(frame1[i+2] - frame2[i+2]);
                        const d23 = Math.abs(frame2[i] - frame3[i]) + Math.abs(frame2[i+1] - frame3[i+1]) + Math.abs(frame2[i+2] - frame3[i+2]);
                        borderTotal++;
                        if (d12 <= 1) borderZero12++;
                        if (d23 <= 1) borderZero23++;
                    }
                }
                const staticRatio = Math.max(borderZero12, borderZero23) / borderTotal;
                if (staticRatio > 0.90) flags.push('static');

                // Check 2: Background blur detection — compare sharpness of border vs center
                const laplacian = (data, x0, y0, x1, y1) => {
                    let sum = 0, count = 0;
                    for (let y = y0; y < y1; y += 3) {
                        for (let x = x0; x < x1; x += 3) {
                            if (x < 1 || y < 1 || x >= w - 1 || y >= h - 1) continue;
                            const ci = (y * w + x) * 4;
                            const gray = frame2[ci] * 0.299 + frame2[ci+1] * 0.587 + frame2[ci+2] * 0.114;
                            const up = frame2[((y-1)*w+x)*4] * 0.299 + frame2[((y-1)*w+x)*4+1] * 0.587 + frame2[((y-1)*w+x)*4+2] * 0.114;
                            const dn = frame2[((y+1)*w+x)*4] * 0.299 + frame2[((y+1)*w+x)*4+1] * 0.587 + frame2[((y+1)*w+x)*4+2] * 0.114;
                            const lt = frame2[(y*w+x-1)*4] * 0.299 + frame2[(y*w+x-1)*4+1] * 0.587 + frame2[(y*w+x-1)*4+2] * 0.114;
                            const rt = frame2[(y*w+x+1)*4] * 0.299 + frame2[(y*w+x+1)*4+1] * 0.587 + frame2[(y*w+x+1)*4+2] * 0.114;
                            sum += Math.abs(up + dn + lt + rt - 4 * gray);
                            count++;
                        }
                    }
                    return count > 0 ? sum / count : 0;
                };

                const centerSharpness = laplacian(frame2, Math.floor(w*0.3), Math.floor(h*0.2), Math.floor(w*0.7), Math.floor(h*0.8));
                const borderSharpness = (
                    laplacian(frame2, 0, 0, edgeW, h) +
                    laplacian(frame2, w - edgeW, 0, w, h) +
                    laplacian(frame2, edgeW, 0, w - edgeW, topH)
                ) / 3;

                if (centerSharpness > 0 && borderSharpness > 0) {
                    const blurRatio = borderSharpness / centerSharpness;
                    if (blurRatio < 0.20) { resolve(true); return; }
                    if (blurRatio < 0.35) flags.push('blur');
                }

                // Check 3: Segmentation halo — soft edges from virtual BG blending
                let softEdges = 0, sharpEdges = 0;
                for (let y = Math.floor(h*0.15); y < Math.floor(h*0.85); y += 5) {
                    const grads = [];
                    for (let x = 1; x < w-1; x++) {
                        const iL = (y*w+x-1)*4, iR = (y*w+x+1)*4;
                        const gL = frame2[iL]*0.299 + frame2[iL+1]*0.587 + frame2[iL+2]*0.114;
                        const gR = frame2[iR]*0.299 + frame2[iR+1]*0.587 + frame2[iR+2]*0.114;
                        grads[x] = Math.abs(gR - gL);
                    }
                    for (let x = 10; x < w-10; x++) {
                        if (grads[x] < 15) continue;
                        let isPeak = true;
                        for (let dx = -3; dx <= 3; dx++) {
                            if (dx === 0) continue;
                            if (grads[x+dx] !== undefined && grads[x+dx] > grads[x]) { isPeak = false; break; }
                        }
                        if (!isPeak) continue;
                        const thr = grads[x] * 0.3;
                        let edgeWidth = 0;
                        for (let dx = -15; dx <= 15; dx++) {
                            if (grads[x+dx] !== undefined && grads[x+dx] > thr) edgeWidth++;
                        }
                        if (edgeWidth >= 8) softEdges++; else sharpEdges++;
                        x += 15;
                    }
                }
                const totalEdges = softEdges + sharpEdges;
                const softRatio = totalEdges > 0 ? softEdges / totalEdges : 0;
                if (softRatio > 0.76) flags.push('halo');

                // Check 4: Color blend artifacts at transitions
                let blendCount = 0, transCount = 0;
                for (let y = Math.floor(h*0.2); y < Math.floor(h*0.8); y += 8) {
                    for (let x = 10; x < w-10; x++) {
                        const iC = (y*w+x)*4, iL = (y*w+x-5)*4, iR = (y*w+x+5)*4;
                        const gC = frame2[iC]*0.299 + frame2[iC+1]*0.587 + frame2[iC+2]*0.114;
                        const gL = frame2[iL]*0.299 + frame2[iL+1]*0.587 + frame2[iL+2]*0.114;
                        const gR = frame2[iR]*0.299 + frame2[iR+1]*0.587 + frame2[iR+2]*0.114;
                        if (Math.abs(gL - gR) < 20) continue;
                        transCount++;
                        if (Math.abs(gC - (gL+gR)/2) < 8) blendCount++;
                        x += 5;
                    }
                }
                const blendRatio = transCount > 0 ? blendCount / transCount : 0;
                if (blendRatio > 0.55) flags.push('blend');

                // Instant block: strong halo + blend together
                if (softRatio > 0.78 && blendRatio > 0.58) { resolve(true); return; }

                resolve(flags.length >= 2);
            }, 150);
        }, 150);
    });
}

async function verifyAndPunch() {
    faceVerifying.value = true;
    faceError.value = '';

    const filterDetected = await detectBackgroundFilter(punchVideoRef.value);
    if (filterDetected) {
        faceError.value = 'Background filter detected. Please disable any virtual background, blur, or camera filters and try again.';
        faceVerifying.value = false;
        return;
    }

    const images = [];
    for (let i = 0; i < 3; i++) {
        const frame = capturePunchFrame();
        if (frame) images.push(frame);
        if (i < 2) await new Promise(r => setTimeout(r, 300));
    }
    if (!images.length) {
        faceError.value = 'Could not capture image. Please try again.';
        faceVerifying.value = false;
        return;
    }

    let verified = false;
    try {
        const { data } = await axios.post('/api/face/verify', { images });
        verified = !!data.verified;
        if (!verified) {
            faceError.value = data.error || 'Face not recognized. Please try again.';
        }
    } catch (e) {
        faceError.value = e.response?.data?.error || 'Verification failed. Please try again.';
    }

    if (verified) {
        faceVerifying.value = false;
        faceAnalyzing.value = true;
        faceAnalyzePhase.value = 0;
        analyzeInterval = setInterval(() => {
            faceAnalyzePhase.value++;
        }, 1400);

        delayPunchSuccess = true;
        await Promise.all([
            executePunch(),
            new Promise(r => setTimeout(r, 10000)),
        ]);
        delayPunchSuccess = false;

        clearInterval(analyzeInterval);
        analyzeInterval = null;
        faceAnalyzing.value = false;
        faceVerified.value = true;
        punchSuccess.value = true;
        startFaceMetrics();
        startContinuousUpload(punchSuccessType.value, punchSuccessLogId);
        return;
    }

    const clip = punchRecordingActive ? await stopPunchRecording() : null;
    punchRecordingActive = false;

    if (clip) {
        pendingAttemptClips.push({ blob: clip, verified: false });
    }

    startPunchRecording();
    punchRecordingActive = true;
    faceVerifying.value = false;
}

async function executePunch() {
    punchLoading.value = true;
    const wasPunchedIn = isPunchedIn.value;
    const clips = [...pendingAttemptClips];
    pendingAttemptClips = [];

    try {
        const payload = {
            latitude: punchLocation.value.lat,
            longitude: punchLocation.value.lng,
            address: punchLocation.value.address,
        };
        const type = wasPunchedIn ? 'punch_out' : 'punch_in';
        let logId = null;

        if (wasPunchedIn) {
            const { data } = await axios.post('/api/time/punch-out', payload);
            logId = data.log_id;
            isPunchedIn.value = false;
            punchedInAt.value = null;
            stopClock();
            elapsedSeconds.value = 0;
            fetchTimeStatus();
        } else {
            const { data } = await axios.post('/api/time/punch-in', payload);
            logId = data.log_id;
            isPunchedIn.value = true;
            punchedInAt.value = data.punched_in_at || data.log?.login_time;
            startClock(punchedInAt.value);
        }

        for (const entry of clips) {
            queueVideoUpload(entry.blob, type, logId, entry.verified);
        }

        punchSuccessLogId = logId;
        punchSuccessType.value = type;
        if (!delayPunchSuccess) {
            punchSuccess.value = true;
            startFaceMetrics();
            startContinuousUpload(type, logId);
        }
    } catch (e) {
        const type = wasPunchedIn ? 'punch_out' : 'punch_in';
        for (const entry of clips) {
            queueVideoUpload(entry.blob, type, null, entry.verified);
        }
        punchLocationError.value = e.response?.data?.error || 'Something went wrong. Please try again.';
        punchStep.value = 'location';
    } finally {
        punchLoading.value = false;
    }
}

async function closeSuccessModal() {
    stopFaceMetrics();
    showPunchModal.value = false;
}

function stopBackgroundRecording() {
    stopContinuousUpload();
    if (punchRecordingActive) {
        stopPunchRecording().then(clip => {
            punchRecordingActive = false;
            if (clip) queueVideoUpload(clip, punchSuccessType.value, punchSuccessLogId, true);
            stopPunchCam();
        });
    } else {
        stopPunchCam();
    }
}

function startFaceMetrics() {
    faceMetricsPhase.value = 0;
    metricsInterval = setInterval(() => {
        faceMetricsPhase.value = (faceMetricsPhase.value + 1) % faceMetricsLabels.length;
    }, 3000);
}

function stopFaceMetrics() {
    if (metricsInterval) { clearInterval(metricsInterval); metricsInterval = null; }
}

function startContinuousUpload(type, logId) {
    continuousUploadInterval = setInterval(async () => {
        if (!punchRecordingActive) return;
        const clip = await stopPunchRecording();
        punchRecordingActive = false;
        if (clip) {
            queueVideoUpload(clip, type, logId, true);
        }
        startPunchRecording();
        punchRecordingActive = true;
    }, 10000);
}

function stopContinuousUpload() {
    if (continuousUploadInterval) { clearInterval(continuousUploadInterval); continuousUploadInterval = null; }
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
    showAnalyzePanel.value = false;
    analysis.value = null;
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

// --- Job AI Analysis ---
const jobText = ref('');
const isAnalyzing = ref(false);
const analysis = ref(null);
const analysisError = ref('');
const showAnalyzePanel = ref(false);
const analyzeTextarea = ref(null);
const waitingForPaste = ref(false);

async function checkAndAnalyze() {
    await checkUrl();
    showAnalyzePanel.value = true;
    analysis.value = null;
    analysisError.value = '';
    jobText.value = '';
    waitingForPaste.value = true;
    window.open(url.value, '_blank');
    await nextTick();
    analyzeTextarea.value?.focus();
}

function onJobTextPaste() {
    if (!waitingForPaste.value) return;
    waitingForPaste.value = false;
    setTimeout(() => {
        if (jobText.value.trim().length >= 20) analyzeJob();
    }, 100);
}

async function analyzeJob() {
    if (!jobText.value.trim() || jobText.value.trim().length < 20) return;
    isAnalyzing.value = true;
    analysis.value = null;
    analysisError.value = '';
    try {
        const { data } = await axios.post('/api/bids/analyze-job', { job_text: jobText.value });
        analysis.value = data;
    } catch (e) {
        analysisError.value = e.response?.data?.error || 'Analysis failed. Please try again.';
    } finally {
        isAnalyzing.value = false;
    }
}

function flagColor(level) {
    if (level === 'red') return { bg: 'bg-red-500/10', border: 'border-red-500/20', text: 'text-red-400', icon: '🔴' };
    if (level === 'yellow') return { bg: 'bg-amber-500/10', border: 'border-amber-500/20', text: 'text-amber-400', icon: '🟡' };
    return { bg: 'bg-emerald-500/10', border: 'border-emerald-500/20', text: 'text-emerald-400', icon: '✅' };
}

function verdictStyle(verdict) {
    if (verdict === 'AVOID') return 'bg-red-500/10 border-red-500/30 text-red-400';
    if (verdict === 'PROCEED WITH CAUTION') return 'bg-amber-500/10 border-amber-500/30 text-amber-400';
    return 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400';
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

// --- Edit Bid ---
const editingBidId = ref(null);
const editBidForm = ref({ job_title: '', connects_used: 1 });
const isSavingBid = ref(false);

function startEditBid(bid) {
    editingBidId.value = bid.bid_id;
    editBidForm.value = { job_title: bid.job_title || '', connects_used: bid.connects_used };
}

function cancelEditBid() {
    editingBidId.value = null;
}

async function saveEditBid() {
    isSavingBid.value = true;
    try {
        await axios.put(`/api/bids/${editingBidId.value}`, {
            job_title: editBidForm.value.job_title || null,
            connects_used: Number(editBidForm.value.connects_used),
        });
        const bid = bids.value.find(b => b.bid_id === editingBidId.value);
        if (bid) {
            bid.job_title = editBidForm.value.job_title;
            bid.connects_used = Number(editBidForm.value.connects_used);
        }
        editingBidId.value = null;
        fetchBids(bidsPagination.value.current_page);
    } catch (e) {
        console.error(e);
    } finally {
        isSavingBid.value = false;
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
const attendanceSummary = ref({ total_worked_hours: 0, present_days: 0, half_days: 0, absent_days: 0, avg_hours_per_day: 0 });
const attendanceMonth = ref(new Date().getMonth() + 1);
const attendanceYear = ref(new Date().getFullYear());
const attendanceMonthName = ref('');
const attendanceMinHours = ref(8);
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
        attendanceMinHours.value = data.min_hours_per_day || 8;
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

// --- Profile ---
const profileData = ref(null);
const showProfileModal = ref(false);
const profileTab = ref('profile');
const profileForm = ref({ name: '', address: '', higher_education: '', date_of_birth: null, phone_country_code: '', phone_number: '' });
const phoneInput = ref('');
const profilePicFile = ref(null);
const profilePicPreview = ref(null);
const savingProfile = ref(false);
const profileSuccess = ref(false);
const profileErrors = ref({});
const passwordForm = ref({ current_password: '', password: '', password_confirmation: '' });
const savingPassword = ref(false);
const passwordError = ref('');
const passwordSuccess = ref(false);

// Face enrollment in profile
const profileFaceStep = ref('idle');
const profileFaceEnrolling = ref(false);
const profileFaceError = ref('');
const profileFaceSuccess = ref(false);
const { queueUpload: queueProfileFaceUpload } = useVideoUploader();

function resetProfileFace() {
    profileFaceStep.value = 'idle';
    profileFaceEnrolling.value = false;
    profileFaceError.value = '';
}

function startProfileFaceScan() {
    profileFaceError.value = '';
    profileFaceSuccess.value = false;
    profileFaceStep.value = 'scanning';
}

async function onProfileFaceScanComplete(result) {
    profileFaceStep.value = 'processing';
    profileFaceEnrolling.value = true;
    profileFaceError.value = '';
    try {
        await axios.post('/api/face/enroll', { image: result.image });
        if (result.video) {
            queueProfileFaceUpload(result.video, 'enrollment');
        }
        faceEnrolled.value = true;
        profileFaceSuccess.value = true;
        profileFaceStep.value = 'idle';
        router.reload({ only: ['face_recognition'] });
    } catch (e) {
        profileFaceError.value = e.response?.data?.error || 'Enrollment failed. Please try again.';
        profileFaceStep.value = 'idle';
    } finally {
        profileFaceEnrolling.value = false;
    }
}

function onProfileFaceScanCancel() {
    profileFaceStep.value = 'idle';
}

const telInputOptions = { mode: 'international', preferredCountries: ['IN', 'US', 'GB', 'AE', 'CA', 'AU'], defaultCountry: 'IN' };
const telDropdownOptions = { showDialCodeInSelection: true, showFlags: true, showSearchBox: true };

async function fetchProfile() {
    try {
        const { data } = await axios.get('/api/profile');
        profileData.value = data;
    } catch {}
}

async function openProfileModal() {
    if (!profileData.value) return;
    const dob = profileData.value.date_of_birth ? new Date(profileData.value.date_of_birth + 'T00:00:00') : null;
    profileForm.value = {
        name: profileData.value.name || '',
        address: profileData.value.address || '',
        higher_education: profileData.value.higher_education || '',
        date_of_birth: dob,
        phone_country_code: profileData.value.phone_country_code || '',
        phone_number: profileData.value.phone_number || '',
    };
    phoneInput.value = profileData.value.phone_country_code && profileData.value.phone_number
        ? profileData.value.phone_country_code + profileData.value.phone_number
        : '';
    profilePicFile.value = null;
    profilePicPreview.value = null;
    if (profileData.value.profile_picture_url) {
        try {
            const res = await fetch(profileData.value.profile_picture_url, {
                headers: { 'X-PF-Token': document.head.querySelector('meta[name="csrf-token"]')?.content || '1' },
                credentials: 'same-origin',
            });
            if (res.ok) {
                const buf = await res.arrayBuffer();
                const blob = new Blob([buf], { type: res.headers.get('content-type') || 'image/jpeg' });
                profilePicPreview.value = URL.createObjectURL(blob);
            }
        } catch {}
    }
    profileTab.value = 'profile';
    passwordForm.value = { current_password: '', password: '', password_confirmation: '' };
    passwordError.value = '';
    passwordSuccess.value = false;
    resetProfileFace();
    showProfileModal.value = true;
}

function handleProfilePic(e) {
    const file = e.target.files[0];
    if (!file) return;
    profilePicFile.value = file;
    const reader = new FileReader();
    reader.onload = (ev) => profilePicPreview.value = ev.target.result;
    reader.readAsDataURL(file);
}

function onPhoneInput(phone, phoneObject) {
    if (phoneObject?.countryCallingCode) {
        profileForm.value.phone_country_code = '+' + phoneObject.countryCallingCode;
    }
    if (phoneObject?.nationalNumber) {
        profileForm.value.phone_number = phoneObject.nationalNumber;
    }
}

function formatDateStr(d) {
    if (!d) return '';
    const dt = new Date(d);
    return dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0');
}

async function saveProfile() {
    profileErrors.value = {};
    const errs = {};
    if (!profileForm.value.name?.trim()) errs.name = 'Name is required';
    if (!profileForm.value.phone_number?.trim()) errs.phone_number = 'Phone number is required';
    if (!profileForm.value.address?.trim()) errs.address = 'Address is required';
    if (!profileForm.value.higher_education) errs.higher_education = 'Education is required';
    if (!profilePicFile.value && !profileData.value?.profile_picture_url) errs.profile_picture = 'Profile picture is required';
    if (Object.keys(errs).length) { profileErrors.value = errs; return; }
    savingProfile.value = true;
    profileSuccess.value = false;
    try {
        const fd = new FormData();
        fd.append('name', profileForm.value.name);
        fd.append('address', profileForm.value.address);
        fd.append('higher_education', profileForm.value.higher_education);
        if (profileForm.value.date_of_birth) fd.append('date_of_birth', formatDateStr(profileForm.value.date_of_birth));
        fd.append('phone_country_code', profileForm.value.phone_country_code);
        fd.append('phone_number', profileForm.value.phone_number);
        if (profilePicFile.value) fd.append('profile_picture', profilePicFile.value);

        await axios.post('/api/profile', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
        await fetchProfile();
        profileSuccess.value = true;
        setTimeout(() => { profileSuccess.value = false; }, 3000);
    } catch {}
    savingProfile.value = false;
}

async function savePassword() {
    savingPassword.value = true;
    passwordError.value = '';
    passwordSuccess.value = false;
    try {
        await axios.post('/api/profile/password', passwordForm.value);
        passwordSuccess.value = true;
        passwordForm.value = { current_password: '', password: '', password_confirmation: '' };
    } catch (e) {
        passwordError.value = e.response?.data?.message || 'Failed to update password';
    }
    savingPassword.value = false;
}

// --- Lifecycle ---
onMounted(() => {
    fetchTimeStatus();
    fetchBids();
    fetchProfile();
    fetchUnreadCount();
    startHeartbeat();
    initGlobalMessages();
    if (props.auth.user?.id && window.Echo) {
        window.Echo.private(`messages.${props.auth.user.id}`)
            .listen('.message.sent', handleIncomingMessage)
            .listen('.user.typing', handleTypingEvent)
            .listen('.messages.read', handleReadEvent)
            .listen('.user.presence', handlePresenceEvent)
            .listen('.global.message', handleGlobalMessageEvent);
    }

    const params = new URLSearchParams(window.location.search);
    const convHash = params.get('conversation');
    if (convHash) {
        openConversationByHash(convHash);
    }
});

function handleBeforeUnload() {
    stopBackgroundRecording();
    stopFaceMetrics();
}

function handleVisibilityChange() {
    if (document.hidden && punchRecordingActive && !showPunchModal.value) {
        stopBackgroundRecording();
        stopFaceMetrics();
    }
}

window.addEventListener('beforeunload', handleBeforeUnload);
document.addEventListener('visibilitychange', handleVisibilityChange);

onUnmounted(() => {
    stopClock();
    stopBackgroundRecording();
    stopFaceMetrics();
    window.removeEventListener('beforeunload', handleBeforeUnload);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
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
                        @click="openPunchModal"
                        class="px-4 py-2 rounded-lg text-xs font-semibold transition-all duration-200"
                        :class="isPunchedIn
                            ? 'bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/15'
                            : 'bg-brand text-surface-950 hover:bg-brand-light'"
                    >
                        {{ isPunchedIn ? 'Punch Out' : 'Punch In' }}
                    </button>

                    <button @click="showAnnouncementsPanel = !showAnnouncementsPanel" class="relative p-2 rounded-lg text-surface-400 hover:text-surface-100 hover:bg-surface-800/50 transition-colors" title="Announcements">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 010 3.46" />
                        </svg>
                    </button>
                    <button @click="togglePanel" class="relative p-2 rounded-lg text-surface-400 hover:text-surface-100 hover:bg-surface-800/50 transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>
                        </svg>
                        <span v-if="totalUnread > 0" class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] rounded-full bg-brand flex items-center justify-center px-1 animate-badge-blink">
                            <span class="text-[9px] font-bold text-surface-950 leading-none">{{ totalUnread > 99 ? '99+' : totalUnread }}</span>
                        </span>
                    </button>
                    <!-- Profile avatar -->
                    <button @click="openProfileModal" class="relative flex items-center gap-2 px-2 py-1 rounded-lg hover:bg-surface-800/50 transition-colors">
                        <div v-if="profileData?.profile_picture_url" class="w-7 h-7 rounded-full overflow-hidden ring-1 ring-surface-700">
                            <img v-protected-src="profileData.profile_picture_url" class="w-full h-full object-cover" draggable="false" @contextmenu.prevent />
                        </div>
                        <div v-else class="w-7 h-7 rounded-full bg-surface-800 ring-1 ring-surface-700 flex items-center justify-center">
                            <span class="text-[10px] font-bold text-surface-400">{{ auth.user.name?.split(' ').map(w => w[0]).join('').toUpperCase().slice(0,2) }}</span>
                        </div>
                        <span class="text-sm text-surface-400 hidden md:block">{{ auth.user.name }}</span>
                    </button>
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
                    <span class="text-xl font-semibold text-surface-100 mt-1 font-mono">{{ formatHours(todayHours) }}</span>
                </div>
            </div>

            <!-- Profile Completion -->
            <div v-if="profileData && profileData.completion.percentage < 100"
                class="mb-6 rounded-2xl bg-surface-900 border border-surface-800/50 p-5">
                <div class="flex items-center gap-4">
                    <!-- Avatar -->
                    <div class="relative cursor-pointer" @click="openProfileModal">
                        <div v-if="profileData.profile_picture_url"
                            class="w-14 h-14 rounded-full overflow-hidden ring-2 ring-brand/30">
                            <img v-protected-src="profileData.profile_picture_url" class="w-full h-full object-cover" draggable="false" @contextmenu.prevent />
                        </div>
                        <div v-else class="w-14 h-14 rounded-full bg-surface-800 flex items-center justify-center ring-2 ring-surface-700">
                            <svg class="w-6 h-6 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/>
                            </svg>
                        </div>
                    </div>
                    <!-- Info -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3 mb-2">
                            <h3 class="text-sm font-semibold text-surface-100">Complete your profile</h3>
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full"
                                :class="profileData.completion.percentage >= 70 ? 'bg-emerald-500/15 text-emerald-400' : profileData.completion.percentage >= 40 ? 'bg-amber-500/15 text-amber-400' : 'bg-red-500/15 text-red-400'">
                                {{ profileData.completion.percentage }}%
                            </span>
                        </div>
                        <!-- Progress bar -->
                        <div class="w-full h-1.5 bg-surface-800 rounded-full overflow-hidden mb-2">
                            <div class="h-full rounded-full transition-all duration-500"
                                :class="profileData.completion.percentage >= 70 ? 'bg-emerald-500' : profileData.completion.percentage >= 40 ? 'bg-amber-500' : 'bg-red-500'"
                                :style="{ width: profileData.completion.percentage + '%' }"></div>
                        </div>
                        <!-- Missing fields -->
                        <div class="flex flex-wrap gap-1.5">
                            <span v-for="(done, field) in profileData.completion.fields" :key="field"
                                class="text-[10px] px-2 py-0.5 rounded-full"
                                :class="done ? 'bg-emerald-500/10 text-emerald-400' : 'bg-surface-800 text-surface-500'">
                                {{ field === 'profile_picture' ? 'Photo' : field === 'higher_education' ? 'Education' : field === 'date_of_birth' ? 'DOB' : field === 'phone_number' ? 'Phone' : field.charAt(0).toUpperCase() + field.slice(1) }}
                            </span>
                        </div>
                    </div>
                    <!-- Edit button -->
                    <button @click="openProfileModal"
                        class="px-4 py-2 text-sm font-medium bg-brand hover:bg-brand-light text-surface-950 rounded-xl transition-colors flex-shrink-0">
                        Complete
                    </button>
                </div>
            </div>

            <!-- Tab navigation -->
            <div class="flex items-center gap-1 mb-6 border-b border-surface-800/50 pb-px">
                <button
                    @click="switchTab('checker')"
                    class="px-4 py-2.5 text-sm font-medium rounded-t-lg transition-colors relative"
                    :class="activeTab === 'checker' ? 'text-surface-100 bg-surface-800/50' : 'text-surface-400 hover:text-surface-300'"
                >
                    Submit Job
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

            <!-- Tab: Submit Job -->
            <div v-show="activeTab === 'checker'">
                <div class="card p-6 sm:p-8 max-w-2xl">
                    <h2 class="text-lg font-semibold text-surface-100 mb-1">Submit Job</h2>
                    <p class="text-sm text-surface-400 mb-1">Paste a job URL to verify no one on your team has already bid.</p>
                    <p class="text-xs text-amber-400/80 mb-6">Important: Always submit your jobs here so that we can track your project, else your applied project may go untracked.</p>

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

                        <div class="flex gap-3">
                            <button
                                @click="checkUrl"
                                :disabled="isChecking || !url.trim()"
                                class="btn-primary flex-1 py-3 text-sm"
                                :class="{ 'opacity-50 pointer-events-none': isChecking || !url.trim() }"
                            >
                                <svg v-if="!isChecking" class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                </svg>
                                <svg v-else class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                {{ isChecking ? 'Submitting...' : 'Submit Job' }}
                            </button>
                            <button
                                @click="checkAndAnalyze"
                                :disabled="isChecking || isAnalyzing || !url.trim()"
                                class="flex-1 py-3 text-sm font-medium rounded-xl inline-flex items-center justify-center transition-all border border-purple-500/30 bg-purple-500/10 text-purple-400 hover:bg-purple-500/20"
                                :class="{ 'opacity-50 pointer-events-none': isChecking || isAnalyzing || !url.trim() }"
                            >
                                <svg v-if="!isAnalyzing" class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z" />
                                </svg>
                                <svg v-else class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                {{ isAnalyzing ? 'Analyzing...' : 'Check + AI Analyze' }}
                            </button>
                        </div>
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

                    <!-- AI Analysis: Job text input (shows when awaiting paste) -->
                    <div v-if="showAnalyzePanel" class="mt-5">
                        <div class="flex items-center gap-2 mb-3">
                            <svg class="w-4 h-4 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                            </svg>
                            <p class="text-sm font-medium text-purple-400">AI Red Flag Analysis</p>
                        </div>

                        <div v-if="waitingForPaste && !isAnalyzing && !analysis" class="p-4 rounded-xl bg-purple-500/5 border border-purple-500/20 mb-3">
                            <p class="text-sm text-purple-300 font-medium mb-2">Job opened in new tab. Now:</p>
                            <div class="flex items-center gap-6 text-xs text-surface-300">
                                <div class="flex items-center gap-2">
                                    <span class="w-5 h-5 rounded-full bg-purple-500/20 text-purple-400 text-[10px] font-bold flex items-center justify-center">1</span>
                                    <span><span class="text-surface-100 font-medium">Ctrl+A</span> on Upwork tab</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-5 h-5 rounded-full bg-purple-500/20 text-purple-400 text-[10px] font-bold flex items-center justify-center">2</span>
                                    <span><span class="text-surface-100 font-medium">Ctrl+C</span> to copy</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-5 h-5 rounded-full bg-purple-500/20 text-purple-400 text-[10px] font-bold flex items-center justify-center">3</span>
                                    <span><span class="text-surface-100 font-medium">Ctrl+V</span> below</span>
                                </div>
                            </div>
                        </div>

                        <textarea
                            ref="analyzeTextarea"
                            v-model="jobText"
                            @paste="onJobTextPaste"
                            rows="4"
                            :placeholder="waitingForPaste ? 'Paste here (Ctrl+V) — analysis starts automatically...' : 'Paste job page content here...'"
                            class="input-field text-sm leading-relaxed resize-y"
                            :class="waitingForPaste && !analysis ? 'border-purple-500/40 ring-1 ring-purple-500/20' : ''"
                        ></textarea>

                        <button
                            @click="analyzeJob"
                            :disabled="isAnalyzing || jobText.trim().length < 20"
                            class="w-full py-3 text-sm font-medium rounded-xl inline-flex items-center justify-center transition-all border border-purple-500/30 bg-purple-500/10 text-purple-400 hover:bg-purple-500/20 mt-3"
                            :class="{ 'opacity-50 pointer-events-none': isAnalyzing || jobText.trim().length < 20 }"
                        >
                            <svg v-if="!isAnalyzing" class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z" />
                            </svg>
                            <svg v-else class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            {{ isAnalyzing ? 'Analyzing with AI...' : 'Scan for Red Flags' }}
                        </button>
                    </div>

                    <!-- Error -->
                    <div v-if="analysisError" class="mt-4 p-4 rounded-xl bg-red-500/5 border border-red-500/20">
                        <p class="text-sm text-red-400">{{ analysisError }}</p>
                    </div>

                    <!-- Analysis Results -->
                    <div v-if="analysis" class="mt-5 space-y-4">
                        <!-- Verdict Banner -->
                        <div class="p-4 rounded-xl border" :class="verdictStyle(analysis.verdict)">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium uppercase tracking-wider opacity-70">Verdict</p>
                                    <p class="text-lg font-bold mt-0.5">{{ analysis.verdict }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs font-medium uppercase tracking-wider opacity-70">Score</p>
                                    <p class="text-2xl font-bold mt-0.5">{{ analysis.overall_score }}<span class="text-sm opacity-50">/10</span></p>
                                </div>
                            </div>
                            <p class="text-sm mt-3 opacity-80">{{ analysis.summary }}</p>
                        </div>

                        <!-- Flags List -->
                        <div class="space-y-2">
                            <p class="text-xs font-medium text-surface-400 uppercase tracking-wider">Red Flag Analysis</p>
                            <div
                                v-for="(flag, i) in analysis.flags"
                                :key="i"
                                class="flex items-start gap-3 p-3 rounded-lg border"
                                :class="[flagColor(flag.level).bg, flagColor(flag.level).border]"
                            >
                                <span class="text-sm mt-0.5 flex-shrink-0">{{ flagColor(flag.level).icon }}</span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium" :class="flagColor(flag.level).text">{{ flag.label }}</p>
                                    <p class="text-xs text-surface-400 mt-0.5">{{ flag.detail }}</p>
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

                        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto sm:ml-auto mt-2 sm:mt-0">
                            <input type="date" v-model="customFrom" class="input-field text-xs py-1.5 px-2.5 w-[calc(50%-20px)] sm:w-32" />
                            <span class="text-xs text-surface-500">to</span>
                            <input type="date" v-model="customTo" class="input-field text-xs py-1.5 px-2.5 w-[calc(50%-20px)] sm:w-32" />
                            <button @click="applyCustomRange" class="btn-secondary text-xs px-3 py-1.5 w-full sm:w-auto">Apply</button>
                        </div>
                    </div>

                    <!-- Status filter row -->
                    <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-surface-700/30">
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
                        <p class="text-xs text-surface-600 mt-1">Submit your first bid using the Submit Job tab.</p>
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
                                    <th class="px-5 py-3 text-right table-header w-10"></th>
                                </tr>
                            </thead>
                            <tbody class="text-sm">
                                <tr v-for="bid in bids" :key="bid.bid_id" class="border-b border-surface-800/30 hover:bg-surface-800/20 transition-colors">
                                    <td class="px-5 py-3.5">
                                        <div v-if="editingBidId === bid.bid_id" class="max-w-xs">
                                            <input v-model="editBidForm.job_title" type="text" placeholder="Job title" class="input-field text-sm w-full" @keydown.enter="saveEditBid" @keydown.esc="cancelEditBid" />
                                        </div>
                                        <div v-else class="max-w-xs">
                                            <p class="font-medium text-surface-200 truncate">{{ bid.job_title || 'Untitled Job' }}</p>
                                            <a :href="bid.job_url" target="_blank" class="text-xs text-surface-500 hover:text-brand truncate block mt-0.5 transition-colors">
                                                {{ bid.job_url.substring(0, 50) }}...
                                            </a>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <span class="badge-neutral">{{ bid.platform_name }}</span>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <input v-if="editingBidId === bid.bid_id" v-model="editBidForm.connects_used" type="number" min="1" class="input-field text-sm font-mono w-20" @keydown.enter="saveEditBid" @keydown.esc="cancelEditBid" />
                                        <span v-else class="font-mono text-surface-300">{{ bid.connects_used }}</span>
                                    </td>
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
                                    <td class="px-5 py-3.5 text-right">
                                        <div v-if="editingBidId === bid.bid_id" class="flex items-center justify-end gap-1.5">
                                            <button @click="saveEditBid" :disabled="isSavingBid" class="text-xs text-brand hover:text-brand/80 font-medium">{{ isSavingBid ? '...' : 'Save' }}</button>
                                            <button @click="cancelEditBid" class="text-xs text-surface-500 hover:text-surface-300">Cancel</button>
                                        </div>
                                        <button v-else @click="startEditBid(bid)" class="text-surface-600 hover:text-surface-300 transition-colors" title="Edit">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                                        </button>
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
                        <div class="flex items-center gap-2 sm:gap-3">
                            <button @click="prevMonth" class="btn-ghost p-1.5 sm:p-2">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>
                            <h2 class="text-sm sm:text-base font-semibold text-surface-100 min-w-[120px] sm:min-w-[160px] text-center">
                                {{ attendanceMonthName }} {{ attendanceYear }}
                            </h2>
                            <button @click="nextMonth" class="btn-ghost p-1.5 sm:p-2" :class="{ 'opacity-30 pointer-events-none': isCurrentMonth }">
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
                                <span class="w-2.5 h-2.5 rounded-sm bg-amber-400/40"></span>
                                <span class="text-surface-400">Half Day</span>
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

                    <!-- Mobile legend -->
                    <div class="flex sm:hidden items-center justify-center gap-4 mt-3 text-[10px] flex-wrap">
                        <div class="flex items-center gap-1">
                            <span class="w-2 h-2 rounded-sm bg-brand/30"></span>
                            <span class="text-surface-400">Present</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="w-2 h-2 rounded-sm bg-amber-400/40"></span>
                            <span class="text-surface-400">Half Day</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="w-2 h-2 rounded-sm bg-red-500/40"></span>
                            <span class="text-surface-400">Absent</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="w-2 h-2 rounded-sm bg-surface-700/50"></span>
                            <span class="text-surface-400">Weekend</span>
                        </div>
                    </div>

                    <!-- Summary stats -->
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mt-4 pt-4 border-t border-surface-700/30">
                        <div>
                            <span class="text-xs text-surface-500">Days Present</span>
                            <p class="text-lg font-semibold text-brand mt-0.5">{{ attendanceSummary.present_days }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-surface-500">Half Days</span>
                            <p class="text-lg font-semibold text-amber-400 mt-0.5">{{ attendanceSummary.half_days || 0 }}</p>
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
                    <div class="flex items-center gap-2 mt-3">
                        <span class="text-xs text-surface-500">Min hours:</span>
                        <span class="text-sm font-semibold text-surface-300">{{ attendanceMinHours }}h/day</span>
                    </div>
                </div>

                <!-- Loading -->
                <div v-if="isLoadingAttendance" class="card p-8 text-center">
                    <svg class="animate-spin w-6 h-6 text-surface-500 mx-auto" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>

                <!-- Attendance sheet - Desktop table -->
                <div v-else class="card overflow-hidden hidden sm:block">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-surface-700/30">
                                <th class="px-4 py-3 text-left table-header w-12">Day</th>
                                <th class="px-4 py-3 text-left table-header">Date</th>
                                <th class="px-4 py-3 text-left table-header">Status</th>
                                <th class="px-4 py-3 text-left table-header">Hours Worked</th>
                                <th class="px-4 py-3 text-left table-header">Sessions</th>
                                <th class="px-4 py-3 text-right table-header w-10"></th>
                            </tr>
                        </thead>
                        <tbody class="text-sm">
                            <template v-for="day in attendanceDays" :key="day.date">
                                <tr
                                    class="border-b border-surface-800/20 transition-colors cursor-pointer"
                                    :class="{
                                        'bg-red-500/[0.04] hover:bg-red-500/[0.08]': day.status === 'absent',
                                        'bg-amber-500/[0.04] hover:bg-amber-500/[0.08]': day.status === 'half_day',
                                        'hover:bg-surface-800/20': day.status !== 'absent' && day.status !== 'half_day',
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
                                        <div class="flex items-center gap-1.5">
                                            <span v-if="day.status === 'present'" class="badge-success">Present</span>
                                            <span v-else-if="day.status === 'half_day'" class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-500/10 text-amber-400 border border-amber-500/20">Half Day</span>
                                            <span v-else-if="day.status === 'absent'" class="badge-danger">Absent</span>
                                            <span v-else-if="day.status === 'week_off'" class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-violet-500/10 text-violet-400 border border-violet-500/20">Week Off</span>
                                            <span v-else-if="day.status === 'weekend'" class="badge-neutral">Weekend</span>
                                            <span v-else-if="day.status === 'future'" class="text-xs text-surface-600">--</span>
                                            <span v-else class="text-xs text-surface-600">--</span>
                                            <span v-if="day.override?.adjusted" class="text-[9px] text-violet-400/70 italic">adjusted</span>
                                        </div>
                                        <p v-if="day.override?.note" class="text-[10px] text-surface-500 mt-0.5 italic">{{ day.override.note }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div v-if="day.hours > 0 && day.status !== 'future' && day.status !== 'na'" class="flex items-center gap-2">
                                            <div class="flex-1 max-w-[120px] h-1.5 rounded-full bg-surface-700/50 overflow-hidden">
                                                <div
                                                    class="h-full rounded-full transition-all duration-300"
                                                    :class="day.pct >= 100 ? 'bg-brand' : day.pct >= 50 ? 'bg-amber-400' : 'bg-red-400'"
                                                    :style="{ width: Math.min(day.pct, 100) + '%' }"
                                                ></div>
                                            </div>
                                            <span class="font-mono text-xs font-medium" :class="day.pct >= 100 ? 'text-brand' : day.pct >= 50 ? 'text-amber-400' : 'text-red-400'">
                                                {{ formatHours(day.hours) }}
                                            </span>
                                        </div>
                                        <span v-else-if="day.status === 'absent'" class="text-xs text-red-400/60">0h</span>
                                        <span v-else class="text-xs text-surface-600">--</span>
                                    </td>
                                    <td class="px-4 py-3 text-surface-500 text-xs">
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
                                        <div class="py-3 pl-10 space-y-3 border-b border-surface-800/20">
                                            <div v-for="(session, i) in day.sessions" :key="i">
                                                <div class="flex items-center gap-4 text-xs">
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
                                                <div v-if="session.in_location || session.out_location" class="ml-8 mt-1 space-y-1">
                                                    <div v-if="session.in_location" class="flex items-center gap-1.5 text-[10px] text-surface-500">
                                                        <svg class="w-3 h-3 text-emerald-500/60 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                                        <span class="truncate">In: {{ session.in_location.address || `${session.in_location.lat}, ${session.in_location.lng}` }}</span>
                                                    </div>
                                                    <div v-if="session.out_location" class="flex items-center gap-1.5 text-[10px] text-surface-500">
                                                        <svg class="w-3 h-3 text-red-500/60 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                                        <span class="truncate">Out: {{ session.out_location.address || `${session.out_location.lat}, ${session.out_location.lng}` }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Attendance sheet - Mobile card layout -->
                <div v-if="!isLoadingAttendance" class="sm:hidden space-y-2">
                    <template v-for="day in attendanceDays" :key="'m-'+day.date">
                        <div
                            class="card p-3 transition-colors"
                            :class="{
                                'bg-red-500/[0.04]': day.status === 'absent',
                                'bg-amber-500/[0.04]': day.status === 'half_day',
                                'opacity-40': day.status === 'future' || day.status === 'na',
                            }"
                            @click="day.sessions.length > 0 ? toggleDayDetail(day.date) : null"
                        >
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2.5">
                                    <span class="font-mono text-xs text-surface-500 w-6">{{ String(day.day).padStart(2, '0') }}</span>
                                    <div>
                                        <span class="text-sm text-surface-200 font-medium">{{ day.day_name }}</span>
                                        <span class="text-surface-600 ml-1.5 text-xs">{{ day.date }}</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2.5">
                                    <span v-if="day.status === 'present'" class="badge-success text-[10px]">Present</span>
                                    <span v-else-if="day.status === 'half_day'" class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-amber-500/10 text-amber-400 border border-amber-500/20">Half Day</span>
                                    <span v-else-if="day.status === 'absent'" class="badge-danger text-[10px]">Absent</span>
                                    <span v-else-if="day.status === 'week_off'" class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-violet-500/10 text-violet-400 border border-violet-500/20">Week Off</span>
                                    <span v-else-if="day.status === 'weekend'" class="badge-neutral text-[10px]">Weekend</span>
                                    <span v-else class="text-[10px] text-surface-600">--</span>
                                    <svg v-if="day.sessions.length > 0" class="w-3.5 h-3.5 text-surface-600 transition-transform" :class="{ 'rotate-180': expandedDay === day.date }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </div>
                            </div>
                            <p v-if="day.override?.note" class="text-[10px] text-surface-500 mt-1 italic pl-8">{{ day.override.note }}</p>
                            <div v-if="day.hours > 0 && day.status !== 'future' && day.status !== 'na'" class="flex items-center gap-2 mt-2">
                                <div class="flex-1 h-1.5 rounded-full bg-surface-700/50 overflow-hidden">
                                    <div
                                        class="h-full rounded-full transition-all duration-300"
                                        :class="day.pct >= 100 ? 'bg-brand' : day.pct >= 50 ? 'bg-amber-400' : 'bg-red-400'"
                                        :style="{ width: Math.min(day.pct, 100) + '%' }"
                                    ></div>
                                </div>
                                <span class="font-mono text-xs font-medium flex-shrink-0" :class="day.pct >= 100 ? 'text-brand' : day.pct >= 50 ? 'text-amber-400' : 'text-red-400'">
                                    {{ formatHours(day.hours) }}
                                </span>
                                <span v-if="day.sessions.length > 0" class="text-[10px] text-surface-500 flex-shrink-0">{{ day.sessions.length }}s</span>
                            </div>

                            <!-- Mobile expanded sessions -->
                            <div v-if="expandedDay === day.date && day.sessions.length > 0" class="mt-3 pt-3 border-t border-surface-800/30 space-y-2">
                                <div v-for="(session, i) in day.sessions" :key="i">
                                    <div class="flex items-center gap-2 text-xs">
                                        <span class="text-surface-500">{{ i + 1 }}.</span>
                                        <span class="font-mono text-surface-300">{{ toLocalTimeShort(session.in) || '--' }}</span>
                                        <svg class="w-3 h-3 text-surface-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                                        <span class="font-mono" :class="session.out ? 'text-surface-300' : 'text-emerald-400'">{{ session.out ? toLocalTimeShort(session.out) : 'Active' }}</span>
                                        <span class="text-surface-500 ml-auto">{{ formatHours(session.hours) }}</span>
                                    </div>
                                    <div v-if="session.in_location || session.out_location" class="ml-4 mt-1 space-y-1">
                                        <div v-if="session.in_location" class="flex items-center gap-1 text-[10px] text-surface-500">
                                            <svg class="w-3 h-3 text-emerald-500/60 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                            <span class="truncate">{{ session.in_location.address || `${session.in_location.lat}, ${session.in_location.lng}` }}</span>
                                        </div>
                                        <div v-if="session.out_location" class="flex items-center gap-1 text-[10px] text-surface-500">
                                            <svg class="w-3 h-3 text-red-500/60 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                            <span class="truncate">{{ session.out_location.address || `${session.out_location.lat}, ${session.out_location.lng}` }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
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
        <GlobalMessagePopup />

        <!-- Announcements History Panel -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showAnnouncementsPanel" class="fixed inset-0 z-[80] flex justify-end">
                    <div class="absolute inset-0 bg-black/40" @click="showAnnouncementsPanel = false"></div>
                    <div class="relative w-full max-w-md bg-surface-900 border-l border-surface-800/50 shadow-2xl flex flex-col h-full animate-slide-in-right">
                        <div class="flex items-center justify-between px-5 py-4 border-b border-surface-800/50">
                            <h2 class="text-base font-semibold text-surface-100">Announcements</h2>
                            <button @click="showAnnouncementsPanel = false" class="p-1.5 rounded-lg text-surface-400 hover:text-surface-100 hover:bg-surface-800/50 transition-colors">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div class="flex-1 overflow-y-auto p-4 space-y-3">
                            <div v-if="myAnnouncementsLoading" class="py-8 text-center text-surface-500 text-sm">Loading...</div>
                            <div v-else-if="myAnnouncements.length === 0" class="py-8 text-center text-surface-500 text-sm">No announcements yet.</div>
                            <div v-else v-for="msg in myAnnouncements" :key="msg.id" class="rounded-xl border bg-surface-800/30 overflow-hidden"
                                 :class="msg.priority === 'urgent' ? 'border-red-500/30' : 'border-surface-700/40'">
                                <div class="p-4">
                                    <div class="flex items-center gap-2 mb-2">
                                        <h3 class="text-sm font-semibold text-surface-100 truncate">{{ msg.title }}</h3>
                                        <span v-if="msg.priority === 'urgent'" class="text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded bg-red-500/20 text-red-400">Urgent</span>
                                    </div>
                                    <div class="prose-popup text-sm text-surface-300 leading-relaxed mb-3" @click="onAnnouncementBodyClick" v-html="renderAnnouncementBody(msg.body)"></div>
                                    <div class="flex items-center gap-2 text-[11px] text-surface-500 mb-3">
                                        <span>From {{ msg.sender_name }}</span>
                                        <span>&middot;</span>
                                        <span>{{ new Date(msg.created_at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) }}</span>
                                    </div>

                                    <!-- Reactions display -->
                                    <div v-if="announcementReactions[msg.id]?.reactions?.length" class="flex flex-wrap gap-1.5 mb-3">
                                        <button v-for="r in announcementReactions[msg.id].reactions" :key="r.emoji"
                                            @click="handleReaction(msg.id, r.emoji)"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs border transition-colors"
                                            :class="announcementReactions[msg.id]?.user_reactions?.includes(r.emoji) ? 'bg-brand/10 border-brand/30 text-brand' : 'bg-surface-800 border-surface-700/50 text-surface-400 hover:border-surface-600'">
                                            <span>{{ r.emoji }}</span>
                                            <span class="font-medium">{{ r.count }}</span>
                                        </button>
                                    </div>

                                    <!-- Action buttons -->
                                    <div class="flex items-center gap-2">
                                        <button @click="toggleAnnouncementExpand(msg.id)" class="text-xs font-medium text-surface-400 hover:text-surface-200 transition-colors">
                                            {{ expandedAnnouncement === msg.id ? 'Hide' : 'React & Comment' }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Expanded: Reactions + Comments -->
                                <div v-if="expandedAnnouncement === msg.id" class="border-t border-surface-700/40 p-4 bg-surface-900/40">
                                    <!-- Quick reactions -->
                                    <div class="flex items-center gap-1 mb-4">
                                        <button v-for="emoji in availableEmojis" :key="emoji"
                                            @click="handleReaction(msg.id, emoji)"
                                            class="w-8 h-8 rounded-lg flex items-center justify-center text-base hover:bg-surface-700/50 transition-colors"
                                            :class="announcementReactions[msg.id]?.user_reactions?.includes(emoji) ? 'bg-brand/10 ring-1 ring-brand/30' : ''">
                                            {{ emoji }}
                                        </button>
                                    </div>

                                    <!-- Comments -->
                                    <div class="space-y-2.5 mb-3">
                                        <div v-for="c in (announcementComments[msg.id] || [])" :key="c.id" class="flex gap-2">
                                            <div class="w-6 h-6 rounded-full bg-surface-700 flex items-center justify-center text-[9px] font-semibold text-surface-300 flex-shrink-0 mt-0.5">
                                                {{ c.user_name.charAt(0).toUpperCase() }}
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-baseline gap-2">
                                                    <span class="text-xs font-medium text-surface-200">{{ c.user_name }}</span>
                                                    <span class="text-[10px] text-surface-500">{{ new Date(c.created_at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) }}</span>
                                                </div>
                                                <p class="text-xs text-surface-300 mt-0.5">{{ c.body }}</p>
                                            </div>
                                        </div>
                                        <p v-if="!announcementComments[msg.id]?.length" class="text-xs text-surface-500">No comments yet. Be the first to share your thoughts.</p>
                                    </div>

                                    <!-- Add comment -->
                                    <div class="flex gap-2">
                                        <input v-model="commentInput[msg.id]" @keydown.enter="submitComment(msg.id)" type="text" placeholder="Add your thoughts..." class="flex-1 input-field text-xs py-2" />
                                        <button @click="submitComment(msg.id)" :disabled="!(commentInput[msg.id] || '').trim()" class="px-3 py-2 text-xs font-medium rounded-lg bg-brand text-surface-950 hover:bg-brand/90 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                                            Send
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Profile Edit Modal -->
        <Teleport to="body">
            <div v-if="showProfileModal" class="fixed inset-0 z-[90] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="resetProfileFace(); showProfileModal = false">
                <div class="w-full max-w-lg mx-4 bg-surface-900 border border-surface-800/50 rounded-2xl shadow-2xl overflow-hidden max-h-[90vh] flex flex-col">
                    <!-- Header -->
                    <div class="px-6 py-4 border-b border-surface-800/50 flex items-center justify-between flex-shrink-0">
                        <h2 class="text-base font-semibold text-surface-100">My Profile</h2>
                        <button @click="resetProfileFace(); showProfileModal = false" class="text-surface-400 hover:text-surface-200 transition-colors">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <!-- Tabs -->
                    <div class="px-6 pt-3 flex gap-1 border-b border-surface-800/50 flex-shrink-0">
                        <button @click="profileTab = 'profile'"
                            class="px-4 py-2 text-sm font-medium rounded-t-lg transition-colors relative"
                            :class="profileTab === 'profile' ? 'text-surface-100 bg-surface-800/50' : 'text-surface-400 hover:text-surface-300'">
                            Profile
                            <div v-if="profileTab === 'profile'" class="absolute bottom-0 left-0 right-0 h-0.5 bg-brand rounded-full"></div>
                        </button>
                        <button @click="profileTab = 'password'"
                            class="px-4 py-2 text-sm font-medium rounded-t-lg transition-colors relative"
                            :class="profileTab === 'password' ? 'text-surface-100 bg-surface-800/50' : 'text-surface-400 hover:text-surface-300'">
                            Password
                            <div v-if="profileTab === 'password'" class="absolute bottom-0 left-0 right-0 h-0.5 bg-brand rounded-full"></div>
                        </button>
                        <button v-if="faceEnabled" @click="profileTab = 'face'; resetProfileFace()"
                            class="px-4 py-2 text-sm font-medium rounded-t-lg transition-colors relative"
                            :class="profileTab === 'face' ? 'text-surface-100 bg-surface-800/50' : 'text-surface-400 hover:text-surface-300'">
                            Face ID
                            <div v-if="profileTab === 'face'" class="absolute bottom-0 left-0 right-0 h-0.5 bg-brand rounded-full"></div>
                        </button>
                    </div>
                    <!-- Profile Tab -->
                    <div v-show="profileTab === 'profile'" class="px-6 py-5 space-y-5 overflow-y-auto flex-1 scrollbar-thin">
                        <div v-if="profileSuccess" class="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-sm text-emerald-400">Profile updated successfully!</div>
                        <!-- Profile picture -->
                        <div class="flex items-center gap-4">
                            <div class="relative">
                                <div v-if="profilePicPreview" class="w-20 h-20 rounded-full overflow-hidden ring-2 ring-brand/30">
                                    <img :src="profilePicPreview" class="w-full h-full object-cover" draggable="false" @contextmenu.prevent @load="e => { if (e.target.src.startsWith('blob:')) URL.revokeObjectURL(e.target.src); }" />
                                </div>
                                <div v-else class="w-20 h-20 rounded-full bg-surface-800 flex items-center justify-center ring-2" :class="profileErrors.profile_picture ? 'ring-red-500/50' : 'ring-surface-700'">
                                    <svg class="w-8 h-8 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/>
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <label class="px-3 py-1.5 text-xs font-medium bg-surface-800 hover:bg-surface-700 text-surface-300 rounded-lg cursor-pointer transition-colors inline-block">
                                    Upload Photo *
                                    <input type="file" accept="image/*" class="hidden" @change="handleProfilePic" />
                                </label>
                                <p class="text-[10px] text-surface-500 mt-1.5">Max 5MB, JPG/PNG</p>
                                <p v-if="profileErrors.profile_picture" class="text-[10px] text-red-400 mt-0.5">{{ profileErrors.profile_picture }}</p>
                            </div>
                        </div>
                        <!-- Name -->
                        <div>
                            <label class="text-xs font-medium text-surface-400 mb-1.5 block">Full Name *</label>
                            <input v-model="profileForm.name" type="text"
                                class="w-full px-3.5 py-2.5 text-sm bg-surface-800/60 border rounded-xl text-surface-100 placeholder-surface-500 focus:outline-none transition-colors"
                                :class="profileErrors.name ? 'border-red-500/50 focus:border-red-500/70' : 'border-surface-700/30 focus:border-brand/50'" />
                            <p v-if="profileErrors.name" class="text-[10px] text-red-400 mt-1">{{ profileErrors.name }}</p>
                        </div>
                        <!-- Email (read-only) -->
                        <div>
                            <label class="text-xs font-medium text-surface-400 mb-1.5 block">Email</label>
                            <input :value="profileData?.email" type="email" disabled
                                class="w-full px-3.5 py-2.5 text-sm bg-surface-800/30 border border-surface-700/20 rounded-xl text-surface-500 cursor-not-allowed" />
                        </div>
                        <!-- Designation (read-only, managed by admin) -->
                        <div>
                            <label class="text-xs font-medium text-surface-400 mb-1.5 block">Designation</label>
                            <input :value="profileData?.designation || '—'" type="text" disabled
                                class="w-full px-3.5 py-2.5 text-sm bg-surface-800/30 border border-surface-700/20 rounded-xl text-surface-500 cursor-not-allowed" />
                        </div>
                        <!-- Phone with flags -->
                        <div>
                            <label class="text-xs font-medium text-surface-400 mb-1.5 block">Phone Number *</label>
                            <VueTelInput
                                v-model="phoneInput"
                                @on-input="onPhoneInput"
                                :inputOptions="telInputOptions"
                                :dropdownOptions="telDropdownOptions"
                                class="profile-tel-input"
                            />
                            <p v-if="profileErrors.phone_number" class="text-[10px] text-red-400 mt-1">{{ profileErrors.phone_number }}</p>
                        </div>
                        <!-- Date of Birth -->
                        <div>
                            <label class="text-xs font-medium text-surface-400 mb-1.5 block">Date of Birth</label>
                            <VueDatePicker
                                v-model="profileForm.date_of_birth"
                                :enable-time-picker="false"
                                :max-date="new Date()"
                                :year-range="[1950, new Date().getFullYear()]"
                                auto-apply
                                dark
                                :teleport="true"
                                placeholder="Select date"
                            />
                        </div>
                        <!-- Address -->
                        <div>
                            <label class="text-xs font-medium text-surface-400 mb-1.5 block">Address *</label>
                            <textarea v-model="profileForm.address" rows="2" placeholder="Your address"
                                class="w-full px-3.5 py-2.5 text-sm bg-surface-800/60 border rounded-xl text-surface-100 placeholder-surface-500 focus:outline-none resize-none transition-colors"
                                :class="profileErrors.address ? 'border-red-500/50 focus:border-red-500/70' : 'border-surface-700/30 focus:border-brand/50'"></textarea>
                            <p v-if="profileErrors.address" class="text-[10px] text-red-400 mt-1">{{ profileErrors.address }}</p>
                        </div>
                        <!-- Higher Education -->
                        <div>
                            <label class="text-xs font-medium text-surface-400 mb-1.5 block">Highest Education *</label>
                            <select v-model="profileForm.higher_education"
                                class="w-full px-3.5 py-2.5 text-sm bg-surface-800/60 border rounded-xl text-surface-100 focus:outline-none transition-colors"
                                :class="profileErrors.higher_education ? 'border-red-500/50 focus:border-red-500/70' : 'border-surface-700/30 focus:border-brand/50'">
                                <option value="">Select</option>
                                <option value="High School">High School</option>
                                <option value="Diploma">Diploma</option>
                                <option value="Bachelor's">Bachelor's</option>
                                <option value="Master's">Master's</option>
                                <option value="PhD">PhD</option>
                                <option value="Other">Other</option>
                            </select>
                            <p v-if="profileErrors.higher_education" class="text-[10px] text-red-400 mt-1">{{ profileErrors.higher_education }}</p>
                        </div>
                    </div>
                    <!-- Password Tab -->
                    <div v-show="profileTab === 'password'" class="px-6 py-5 space-y-5 overflow-y-auto flex-1 scrollbar-thin">
                        <div v-if="passwordSuccess" class="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-sm text-emerald-400">
                            Password updated successfully.
                        </div>
                        <div v-if="passwordError" class="p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-sm text-red-400">
                            {{ passwordError }}
                        </div>
                        <div>
                            <label class="text-xs font-medium text-surface-400 mb-1.5 block">Current Password</label>
                            <input v-model="passwordForm.current_password" type="password" placeholder="Enter current password"
                                class="w-full px-3.5 py-2.5 text-sm bg-surface-800/60 border border-surface-700/30 rounded-xl text-surface-100 placeholder-surface-500 focus:outline-none focus:border-brand/50 transition-colors" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-surface-400 mb-1.5 block">New Password</label>
                            <input v-model="passwordForm.password" type="password" placeholder="Enter new password"
                                class="w-full px-3.5 py-2.5 text-sm bg-surface-800/60 border border-surface-700/30 rounded-xl text-surface-100 placeholder-surface-500 focus:outline-none focus:border-brand/50 transition-colors" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-surface-400 mb-1.5 block">Confirm New Password</label>
                            <input v-model="passwordForm.password_confirmation" type="password" placeholder="Confirm new password"
                                class="w-full px-3.5 py-2.5 text-sm bg-surface-800/60 border border-surface-700/30 rounded-xl text-surface-100 placeholder-surface-500 focus:outline-none focus:border-brand/50 transition-colors" />
                        </div>
                    </div>
                    <!-- Face ID Tab -->
                    <div v-if="faceEnabled" v-show="profileTab === 'face'" class="px-6 py-5 overflow-y-auto flex-1 scrollbar-thin">
                        <!-- Success message -->
                        <div v-if="profileFaceSuccess" class="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-sm text-emerald-400 mb-4">
                            Face registered successfully!
                        </div>

                        <!-- Error message -->
                        <div v-if="profileFaceError" class="p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-sm text-red-400 mb-4">
                            {{ profileFaceError }}
                        </div>

                        <!-- Current status -->
                        <div class="flex items-center gap-3 mb-5 p-3 rounded-xl bg-surface-800/40 border border-surface-700/30">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center" :class="faceEnrolled ? 'bg-emerald-500/10' : 'bg-amber-500/10'">
                                <svg v-if="faceEnrolled" class="w-5 h-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                                <svg v-else class="w-5 h-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126Z"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium" :class="faceEnrolled ? 'text-emerald-400' : 'text-amber-400'">
                                    {{ faceEnrolled ? 'Face Registered' : 'Not Registered' }}
                                </p>
                                <p class="text-[10px] text-surface-500 mt-0.5">
                                    {{ faceEnrolled ? 'You can update your face below' : 'Register your face to use punch in/out' }}
                                </p>
                            </div>
                        </div>

                        <!-- Idle state: show start button -->
                        <div v-if="profileFaceStep === 'idle'">
                            <button @click="startProfileFaceScan" class="w-full py-3 text-sm font-medium bg-brand hover:bg-brand-light text-surface-950 rounded-xl transition-colors flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75H6A2.25 2.25 0 0 0 3.75 6v1.5M16.5 3.75H18A2.25 2.25 0 0 1 20.25 6v1.5m0 9V18A2.25 2.25 0 0 1 18 20.25h-1.5m-9 0H6A2.25 2.25 0 0 1 3.75 18v-1.5"/></svg>
                                {{ faceEnrolled ? 'Update Face ID' : 'Start Face Scan' }}
                            </button>
                        </div>

                        <!-- Scanning -->
                        <FaceScanCapture v-else-if="profileFaceStep === 'scanning'" @complete="onProfileFaceScanComplete" @cancel="onProfileFaceScanCancel" />

                        <!-- Processing -->
                        <div v-else-if="profileFaceStep === 'processing'" class="text-center py-8">
                            <div class="w-14 h-14 rounded-full bg-brand/10 flex items-center justify-center mx-auto mb-4">
                                <div class="w-7 h-7 border-2 border-brand/30 border-t-brand rounded-full animate-spin"></div>
                            </div>
                            <p class="text-sm font-medium text-surface-200 mb-1">Registering your face...</p>
                            <p class="text-xs text-surface-500">This may take a moment</p>
                        </div>
                    </div>
                    <!-- Footer -->
                    <div class="px-6 py-4 border-t border-surface-800/50 flex items-center justify-end gap-3 flex-shrink-0">
                        <button @click="resetProfileFace(); showProfileModal = false" class="px-4 py-2 text-sm font-medium text-surface-400 hover:text-surface-200 transition-colors">Cancel</button>
                        <button v-if="profileTab === 'profile'" @click="saveProfile" :disabled="savingProfile || !profileForm.name.trim()"
                            class="px-5 py-2 text-sm font-medium bg-brand hover:bg-brand-light disabled:opacity-40 text-surface-950 rounded-xl transition-colors flex items-center gap-2">
                            <div v-if="savingProfile" class="w-3.5 h-3.5 border-2 border-surface-950/30 border-t-surface-950 rounded-full animate-spin"></div>
                            Save Profile
                        </button>
                        <button v-else-if="profileTab === 'password'" @click="savePassword" :disabled="savingPassword || !passwordForm.current_password || !passwordForm.password || !passwordForm.password_confirmation"
                            class="px-5 py-2 text-sm font-medium bg-brand hover:bg-brand-light disabled:opacity-40 text-surface-950 rounded-xl transition-colors flex items-center gap-2">
                            <div v-if="savingPassword" class="w-3.5 h-3.5 border-2 border-surface-950/30 border-t-surface-950 rounded-full animate-spin"></div>
                            Update Password
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Face Enrollment Modal (blocking) -->
        <FaceEnrollmentModal v-if="showFaceEnrollment" @enrolled="onFaceEnrolled" />

        <!-- Punch In/Out Modal -->
        <Teleport to="body">
            <div v-if="showPunchModal" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="!faceAnalyzing && closePunchModal();">
                <div class="bg-surface-900 border border-surface-700/50 rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
                    <!-- Header -->
                    <div v-if="!punchSuccess && !faceAnalyzing" class="px-6 py-4 border-b border-surface-800/50 flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-surface-100">{{ isPunchedIn ? 'Punch Out' : 'Punch In' }}</h3>
                            <p class="text-xs text-surface-500 mt-0.5">
                                {{ punchStep === 'face' ? 'Verify your identity' : `Confirm your location to ${isPunchedIn ? 'end' : 'start'} your shift` }}
                            </p>
                        </div>
                        <!-- Step indicator when face is required -->
                        <div v-if="faceEnabled && faceEnrolled" class="flex items-center gap-1.5 mr-3">
                            <div class="w-2 h-2 rounded-full" :class="punchStep === 'location' ? 'bg-brand' : 'bg-brand/30'"></div>
                            <div class="w-2 h-2 rounded-full" :class="punchStep === 'face' ? 'bg-brand' : 'bg-brand/30'"></div>
                        </div>
                        <button @click="closePunchModal();" class="text-surface-500 hover:text-surface-300 transition-colors">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Body: Location Step -->
                    <div v-show="punchStep === 'location' && !punchSuccess" class="px-6 py-5">
                        <!-- Locating spinner -->
                        <div v-if="punchLocating" class="flex flex-col items-center gap-3 py-8">
                            <div class="w-10 h-10 border-3 border-surface-600 border-t-brand rounded-full animate-spin"></div>
                            <p class="text-sm text-surface-400">Detecting your location...</p>
                        </div>

                        <!-- Error state -->
                        <div v-else-if="punchLocationError" class="text-center py-6">
                            <div class="w-12 h-12 rounded-full bg-red-500/10 flex items-center justify-center mx-auto mb-3">
                                <svg class="w-6 h-6 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>
                                </svg>
                            </div>
                            <p class="text-sm text-red-400 mb-3">{{ punchLocationError }}</p>

                            <!-- Denied: show instructions -->
                            <div v-if="punchLocationDenied" class="text-left mx-auto max-w-xs mb-4">
                                <p class="text-xs text-surface-400 mb-2">To enable location access:</p>
                                <ol class="text-xs text-surface-500 space-y-1.5 list-decimal list-inside">
                                    <li>Click the <span class="text-surface-300 font-medium">lock/site settings icon</span> in the address bar</li>
                                    <li>Find <span class="text-surface-300 font-medium">Location</span> and set to <span class="text-emerald-400 font-medium">Allow</span></li>
                                    <li>Click <span class="text-surface-300 font-medium">Try Again</span> below</li>
                                </ol>
                            </div>

                            <button @click="requestLocation" class="btn-secondary text-xs px-4 py-2">
                                Try Again
                            </button>
                        </div>

                        <!-- Location found -->
                        <div v-else-if="punchLocation">
                            <!-- Map -->
                            <div ref="punchMapRef" class="w-full h-48 rounded-xl bg-surface-800 overflow-hidden mb-4"></div>

                            <!-- Address -->
                            <div class="flex items-start gap-3 p-3 rounded-xl bg-surface-800/50 border border-surface-700/30">
                                <svg class="w-5 h-5 text-brand flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>
                                </svg>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-medium text-surface-300">Your Location</p>
                                    <p class="text-[11px] text-surface-500 mt-0.5 leading-relaxed">{{ punchLocation.address || `${punchLocation.lat.toFixed(5)}, ${punchLocation.lng.toFixed(5)}` }}</p>
                                </div>
                            </div>

                            <!-- Current time -->
                            <div class="flex items-center gap-3 mt-3 p-3 rounded-xl bg-surface-800/50 border border-surface-700/30">
                                <svg class="w-5 h-5 text-surface-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                </svg>
                                <div>
                                    <p class="text-xs font-medium text-surface-300">{{ isPunchedIn ? 'Shift Duration' : 'Current Time' }}</p>
                                    <p class="text-[11px] text-surface-500 mt-0.5">{{ isPunchedIn ? formattedElapsed : new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Body: Face Verification Step -->
                    <div v-if="punchStep === 'face' && !punchSuccess" class="px-6 py-5">
                        <div v-if="punchCamError && !faceAnalyzing" class="text-center py-6">
                            <div class="w-12 h-12 rounded-full bg-red-500/10 flex items-center justify-center mx-auto mb-3">
                                <svg class="w-6 h-6 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                                </svg>
                            </div>
                            <p class="text-sm text-red-400 mb-3">{{ punchCamError }}</p>
                            <button @click="startPunchCam" class="btn-secondary text-xs px-4 py-2">Try Again</button>
                        </div>

                        <!-- Analyzing phase (10s fake processing after API verified) -->
                        <div v-else-if="faceAnalyzing">
                            <div class="relative rounded-xl overflow-hidden bg-black mb-4">
                                <video ref="punchVideoRef" class="w-full" autoplay playsinline muted></video>
                                <div class="absolute inset-0 pointer-events-none overflow-hidden">
                                    <!-- Scanning circle -->
                                    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-44 h-44 sm:w-52 sm:h-52">
                                        <div class="absolute inset-0 rounded-full border-2 border-cyan-400/40 animate-pulse"></div>
                                        <div class="absolute inset-[-8px] rounded-full border border-cyan-400/20 animate-spin" style="animation-duration:4s;border-top-color:rgb(34 211 238 / 0.6);"></div>
                                    </div>
                                    <!-- Scan line -->
                                    <div class="absolute left-0 right-0 h-0.5 bg-gradient-to-r from-transparent via-cyan-400/60 to-transparent animate-scan-line"></div>
                                    <!-- Corner markers -->
                                    <div class="absolute top-3 left-3 w-6 h-6 border-t-2 border-l-2 border-cyan-400/50"></div>
                                    <div class="absolute top-3 right-3 w-6 h-6 border-t-2 border-r-2 border-cyan-400/50"></div>
                                    <div class="absolute bottom-3 left-3 w-6 h-6 border-b-2 border-l-2 border-cyan-400/50"></div>
                                    <div class="absolute bottom-3 right-3 w-6 h-6 border-b-2 border-r-2 border-cyan-400/50"></div>
                                    <!-- Crosshair dots on face -->
                                    <div class="absolute top-[35%] left-[38%] w-1.5 h-1.5 rounded-full bg-cyan-400/70 animate-pulse"></div>
                                    <div class="absolute top-[35%] left-[58%] w-1.5 h-1.5 rounded-full bg-cyan-400/70 animate-pulse" style="animation-delay:0.3s;"></div>
                                    <div class="absolute top-[50%] left-[48%] w-1.5 h-1.5 rounded-full bg-cyan-400/70 animate-pulse" style="animation-delay:0.6s;"></div>
                                    <div class="absolute top-[60%] left-[48%] w-1 h-3 rounded-full bg-cyan-400/30 animate-pulse" style="animation-delay:0.9s;"></div>
                                </div>
                                <!-- Status overlay -->
                                <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-surface-950/95 to-transparent p-4 pointer-events-none">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-4 h-4 border-2 border-cyan-400/30 border-t-cyan-400 rounded-full animate-spin"></div>
                                        <p class="text-xs font-medium text-cyan-300 transition-all duration-500">{{ faceAnalyzeLabels[Math.min(faceAnalyzePhase, faceAnalyzeLabels.length - 1)] }}</p>
                                    </div>
                                    <!-- Progress bar -->
                                    <div class="mt-2 h-1 bg-surface-800 rounded-full overflow-hidden">
                                        <div class="h-full bg-gradient-to-r from-cyan-500 to-brand rounded-full transition-all duration-1000 ease-linear" :style="{ width: Math.min((faceAnalyzePhase + 1) / faceAnalyzeLabels.length * 100, 100) + '%' }"></div>
                                    </div>
                                </div>
                            </div>
                            <p class="text-[10px] text-surface-500 text-center">Please keep looking at the camera. Do not move.</p>
                        </div>

                        <!-- Normal face verify UI -->
                        <div v-else>
                            <div class="relative rounded-xl overflow-hidden bg-black mb-4">
                                <video ref="punchVideoRef" class="w-full" autoplay playsinline muted></video>
                                <div class="absolute inset-0 pointer-events-none">
                                    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-44 h-44 sm:w-52 sm:h-52 rounded-full border-2 border-brand/40"></div>
                                </div>
                                <div v-if="!punchCamStreaming" class="absolute inset-0 flex items-center justify-center bg-surface-900">
                                    <div class="w-8 h-8 border-2 border-brand/30 border-t-brand rounded-full animate-spin"></div>
                                </div>
                            </div>
                            <p class="text-xs text-surface-500 text-center mb-2">Look at the camera to verify your identity</p>
                            <div v-if="faceError" class="p-2.5 rounded-lg bg-red-500/10 border border-red-500/20 mb-3">
                                <p class="text-xs text-red-400 text-center">{{ faceError }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Body: Success State (camera still recording) -->
                    <div v-if="punchSuccess" class="px-6 py-5">
                        <div class="relative rounded-xl overflow-hidden bg-black mb-4">
                            <video ref="punchVideoRef" class="w-full" autoplay playsinline muted></video>
                            <div class="absolute inset-0 pointer-events-none">
                                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-44 h-44 sm:w-52 sm:h-52 rounded-full border-2 border-emerald-400/60"></div>
                            </div>
                            <div class="absolute inset-0 bg-emerald-500/10 flex items-center justify-center pointer-events-none">
                                <div class="bg-surface-900/80 backdrop-blur-sm rounded-2xl px-5 py-4 text-center">
                                    <div class="w-10 h-10 rounded-full bg-emerald-500/20 flex items-center justify-center mx-auto mb-2">
                                        <svg class="w-5 h-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                    </div>
                                    <p class="text-sm font-semibold text-emerald-400">Identity Verified</p>
                                    <p class="text-xs text-surface-300 mt-1">{{ punchSuccessType === 'punch_in' ? 'Punched in' : 'Punched out' }} successfully</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div v-if="!faceAnalyzing" class="px-6 py-4 border-t border-surface-800/50 flex items-center justify-end gap-3">
                        <template v-if="punchSuccess">
                            <button @click="closeSuccessModal" class="px-5 py-2.5 rounded-xl text-xs font-semibold transition-all duration-200 bg-surface-700 text-surface-200 hover:bg-surface-600 active:scale-95">
                                Close
                            </button>
                        </template>
                        <template v-else>
                            <button @click="closePunchModal();" class="btn-ghost text-xs px-4 py-2">Cancel</button>

                            <!-- Location step button -->
                            <button v-if="punchStep === 'location'"
                                @click="confirmPunch"
                                :disabled="!punchLocation || punchLoading"
                                class="px-5 py-2.5 rounded-xl text-xs font-semibold transition-all duration-200 disabled:opacity-30 disabled:cursor-not-allowed"
                                :class="isPunchedIn
                                    ? 'bg-red-500 text-white hover:bg-red-600 active:scale-95'
                                    : 'bg-brand text-surface-950 hover:bg-brand-light active:scale-95'"
                            >
                                <span v-if="punchLoading" class="flex items-center gap-2">
                                    <div class="w-3.5 h-3.5 border-2 border-current/30 border-t-current rounded-full animate-spin"></div>
                                    Processing...
                                </span>
                                <span v-else>{{ faceEnabled && faceEnrolled ? 'Next: Verify Face' : (isPunchedIn ? 'Confirm Punch Out' : 'Confirm Punch In') }}</span>
                            </button>

                            <!-- Face step button -->
                            <button v-if="punchStep === 'face'"
                                @click="verifyAndPunch"
                                :disabled="!punchCamStreaming || faceVerifying"
                                class="px-5 py-2.5 rounded-xl text-xs font-semibold transition-all duration-200 disabled:opacity-30 disabled:cursor-not-allowed"
                                :class="isPunchedIn
                                    ? 'bg-red-500 text-white hover:bg-red-600 active:scale-95'
                                    : 'bg-brand text-surface-950 hover:bg-brand-light active:scale-95'"
                            >
                                <span v-if="faceVerifying" class="flex items-center gap-2">
                                    <div class="w-3.5 h-3.5 border-2 border-current/30 border-t-current rounded-full animate-spin"></div>
                                    Verifying...
                                </span>
                                <span v-else>{{ isPunchedIn ? 'Verify & Punch Out' : 'Verify & Punch In' }}</span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
:deep(.profile-tel-input .vti__input) {
    background: rgba(30, 30, 40, 0.6) !important;
    border: 1px solid rgba(60, 60, 80, 0.3) !important;
    border-radius: 0.75rem !important;
    color: #e2e8f0 !important;
    font-size: 0.875rem !important;
    padding: 0.625rem 0.875rem !important;
}
:deep(.profile-tel-input .vti__dropdown) {
    background: rgba(30, 30, 40, 0.6) !important;
    border: 1px solid rgba(60, 60, 80, 0.3) !important;
    border-radius: 0.75rem 0 0 0.75rem !important;
}
:deep(.profile-tel-input .vti__dropdown-list) {
    background: #1a1a2e !important;
    border: 1px solid rgba(60, 60, 80, 0.5) !important;
    border-radius: 0.75rem !important;
    z-index: 100 !important;
}
:deep(.profile-tel-input .vti__dropdown-item) {
    color: #e2e8f0 !important;
}
:deep(.profile-tel-input .vti__dropdown-item:hover),
:deep(.profile-tel-input .vti__dropdown-item.highlighted) {
    background: rgba(60, 60, 80, 0.5) !important;
}
:deep(.profile-tel-input .vti__search_box) {
    background: rgba(30, 30, 40, 0.8) !important;
    border: 1px solid rgba(60, 60, 80, 0.3) !important;
    color: #e2e8f0 !important;
    border-radius: 0.5rem !important;
    margin: 0.5rem !important;
}
:deep(.vue-tel-input) {
    border: none !important;
    background: transparent !important;
    border-radius: 0.75rem !important;
}
:deep(.vue-tel-input:focus-within) {
    box-shadow: none !important;
}
:deep(.dp__theme_dark) {
    --dp-background-color: #1a1a2e;
    --dp-text-color: #e2e8f0;
    --dp-hover-color: rgba(60, 60, 80, 0.5);
    --dp-primary-color: var(--color-brand, #6366f1);
    --dp-border-color: rgba(60, 60, 80, 0.3);
    --dp-menu-border-color: rgba(60, 60, 80, 0.5);
    --dp-input-background-color: rgba(30, 30, 40, 0.6);
}
:deep(.dp__input) {
    border-radius: 0.75rem !important;
    font-size: 0.875rem !important;
    padding: 0.625rem 0.875rem !important;
}
</style>
