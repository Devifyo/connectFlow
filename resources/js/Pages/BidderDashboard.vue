<script setup>
import { ref, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import gsap from 'gsap';

const props = defineProps(['auth']);

const url = ref('');
const checkStatus = ref(null);
const checkMessage = ref('');
const cleanJobId = ref(null);
const isPunchedIn = ref(false);
const isChecking = ref(false);

const checkUrl = async () => {
    if (!url.value.trim()) return;
    isChecking.value = true;
    try {
        const response = await axios.post('/api/bids/check', { url: url.value });
        checkStatus.value = response.data.status;
        checkMessage.value = response.data.message;
        cleanJobId.value = response.data.clean_job_id;
        gsap.fromTo('.status-result', { opacity: 0, y: 8 }, { opacity: 1, y: 0, duration: 0.4, ease: 'power2.out' });
    } catch (error) {
        checkStatus.value = 'error';
        checkMessage.value = error.response?.data?.message || 'Could not validate this URL';
    } finally {
        isChecking.value = false;
    }
};

const togglePunch = async () => {
    try {
        if (isPunchedIn.value) {
            await axios.post('/api/time/punch-out');
            isPunchedIn.value = false;
        } else {
            await axios.post('/api/time/punch-in');
            isPunchedIn.value = true;
        }
    } catch (error) {
        console.error(error);
    }
};

onMounted(() => {
    gsap.from('.animate-entry', { opacity: 0, y: 12, duration: 0.6, stagger: 0.1, ease: 'power2.out' });
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="min-h-screen bg-surface-950 text-surface-100 flex flex-col">
        <!-- Top nav -->
        <nav class="sticky top-0 z-50 bg-surface-950/80 backdrop-blur-xl border-b border-surface-800/50">
            <div class="max-w-6xl mx-auto px-6 h-16 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-7 h-7 rounded-md bg-brand flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <span class="text-sm font-bold">ConnectFlow</span>
                </div>

                <div class="flex items-center gap-3">
                    <span class="text-sm text-surface-400 hidden sm:block">{{ auth.user.name }}</span>

                    <button
                        @click="togglePunch"
                        class="relative px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200"
                        :class="isPunchedIn
                            ? 'bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/15'
                            : 'btn-primary py-2'"
                    >
                        <span class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full" :class="isPunchedIn ? 'bg-red-400 animate-pulse-soft' : 'bg-surface-950'"></span>
                            {{ isPunchedIn ? 'Punch Out' : 'Punch In' }}
                        </span>
                    </button>

                    <button @click="router.post('/logout')" class="btn-ghost text-xs">Sign out</button>
                </div>
            </div>
        </nav>

        <!-- Main content -->
        <main class="flex-1 flex flex-col items-center justify-center px-6 py-16">
            <div class="w-full max-w-2xl">
                <!-- Checker card -->
                <div class="animate-entry">
                    <div class="card p-8 sm:p-10">
                        <div class="text-center mb-8">
                            <h1 class="text-2xl font-bold text-surface-100">Check Job Availability</h1>
                            <p class="text-sm text-surface-400 mt-2">Paste a job URL to verify no one on your team has already bid</p>
                        </div>

                        <div class="space-y-4">
                            <div class="relative">
                                <input
                                    type="url"
                                    v-model="url"
                                    @keyup.enter="checkUrl"
                                    placeholder="https://www.upwork.com/jobs/..."
                                    class="input-field py-4 pr-12 text-base"
                                />
                                <div class="absolute right-4 top-1/2 -translate-y-1/2">
                                    <svg class="w-4 h-4 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                                    </svg>
                                </div>
                            </div>

                            <button
                                @click="checkUrl"
                                :disabled="isChecking || !url.trim()"
                                class="btn-primary w-full py-3.5 text-sm"
                                :class="{ 'opacity-50 pointer-events-none': isChecking || !url.trim() }"
                            >
                                <span v-if="!isChecking" class="flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                    </svg>
                                    Check Availability
                                </span>
                                <span v-else class="flex items-center gap-2">
                                    <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Checking...
                                </span>
                            </button>
                        </div>

                        <!-- Result -->
                        <div v-if="checkStatus" class="mt-6 status-result">
                            <div
                                class="flex items-start gap-3 p-4 rounded-xl border"
                                :class="{
                                    'bg-emerald-500/5 border-emerald-500/20': checkStatus === 'clear',
                                    'bg-red-500/5 border-red-500/20': checkStatus === 'collision',
                                    'bg-amber-500/5 border-amber-500/20': checkStatus === 'error',
                                }"
                            >
                                <div class="mt-0.5 flex-shrink-0">
                                    <svg v-if="checkStatus === 'clear'" class="w-5 h-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <svg v-else-if="checkStatus === 'collision'" class="w-5 h-5 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                    </svg>
                                    <svg v-else class="w-5 h-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm font-medium" :class="{
                                        'text-emerald-300': checkStatus === 'clear',
                                        'text-red-300': checkStatus === 'collision',
                                        'text-amber-300': checkStatus === 'error',
                                    }">{{ checkMessage }}</p>
                                    <p v-if="checkStatus === 'clear'" class="text-xs text-surface-500 mt-1">You're good to submit your proposal.</p>
                                    <p v-if="checkStatus === 'collision'" class="text-xs text-surface-500 mt-1">A team member has already submitted a bid for this job.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bottom stats -->
                <div class="mt-6 grid grid-cols-2 gap-4 animate-entry">
                    <div class="stat-card">
                        <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Shift Status</span>
                        <div class="flex items-center gap-2 mt-2">
                            <span class="w-2 h-2 rounded-full" :class="isPunchedIn ? 'bg-emerald-400 animate-pulse-soft' : 'bg-surface-600'"></span>
                            <span class="text-lg font-mono font-medium" :class="isPunchedIn ? 'text-surface-100' : 'text-surface-500'">
                                {{ isPunchedIn ? '02:14:33' : 'Off shift' }}
                            </span>
                        </div>
                    </div>

                    <div class="stat-card">
                        <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Connects Used</span>
                        <div class="mt-2 flex items-baseline gap-1">
                            <span class="text-lg font-mono font-medium text-surface-100">48</span>
                            <span class="text-xs text-surface-500">/ 150 today</span>
                        </div>
                        <div class="mt-2 w-full h-1.5 rounded-full bg-surface-700/50 overflow-hidden">
                            <div class="h-full rounded-full bg-brand/70 transition-all duration-500" style="width: 32%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
