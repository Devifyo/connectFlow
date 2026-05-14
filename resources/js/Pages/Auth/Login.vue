<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import PitchFlowLogo from '@/Components/PitchFlowLogo.vue';
import FaceScanCapture from '@/Components/FaceScanCapture.vue';
import { onMounted, ref } from 'vue';
import axios from 'axios';

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const ready = ref(false);
onMounted(() => { ready.value = true; });

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

const faceMode = ref(false);
const faceScanning = ref(false);
const faceProcessing = ref(false);
const faceError = ref('');

function startFaceLogin() {
    faceError.value = '';
    faceMode.value = true;
    faceScanning.value = true;
}

async function onFaceScanComplete(result) {
    faceScanning.value = false;
    faceProcessing.value = true;
    faceError.value = '';

    const frames = (result.frames || []).map(f => ({
        image: f.image,
        key: f.key,
    }));

    if (frames.length < 3) {
        faceError.value = 'Insufficient scan data. Please try again.';
        faceProcessing.value = false;
        faceMode.value = false;
        return;
    }

    try {
        const { data } = await axios.post('/api/face/login', { frames });
        if (data.success && data.redirect) {
            window.location.href = data.redirect;
        }
    } catch (e) {
        const msg = e.response?.data?.error;
        const status = e.response?.status;
        if (status === 429) {
            faceError.value = 'Too many failed attempts. Please wait 15 minutes or use email and password.';
        } else {
            faceError.value = msg || 'Face login failed. Please try again or use email and password.';
        }
        faceProcessing.value = false;
        faceMode.value = false;
    }
}

function onFaceScanCancel() {
    faceScanning.value = false;
    faceMode.value = false;
}
</script>

<template>
    <Head title="Sign in" />

    <div class="min-h-screen bg-surface-950 flex">
        <!-- Left panel — branding -->
        <div class="hidden lg:flex lg:w-[45%] bg-surface-900 border-r border-surface-800/50 flex-col justify-between p-12 relative overflow-hidden">
            <div class="absolute inset-0 pointer-events-none" aria-hidden="true">
                <div class="absolute bottom-[-20%] left-[-10%] w-[500px] h-[500px] rounded-full bg-brand/4 blur-[100px]"></div>
            </div>

            <div class="relative z-10">
                <div class="flex items-center gap-2.5">
                    <PitchFlowLogo size="w-9 h-9" />
                    <span class="text-xl font-bold text-surface-100">PitchFlow</span>
                </div>
            </div>

            <div class="relative z-10 max-w-sm">
                <blockquote class="text-lg text-surface-300 leading-relaxed font-light">
                    "We went from losing 30% of bids to duplicates to zero collisions in the first week. The team actually trusts the system now."
                </blockquote>
                <div class="mt-6 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-surface-700 flex items-center justify-center text-sm font-semibold text-surface-300">JR</div>
                    <div>
                        <p class="text-sm font-medium text-surface-200">James Rodriguez</p>
                        <p class="text-xs text-surface-500">Operations Lead, PixelForge</p>
                    </div>
                </div>
            </div>

            <div class="relative z-10 text-xs text-surface-600">
                Trusted by 120+ freelance agencies
            </div>
        </div>

        <!-- Right panel — form -->
        <div class="flex-1 flex flex-col justify-center px-6 sm:px-12 lg:px-20">
            <div class="w-full max-w-sm mx-auto" :class="{ 'animate-fade-in': ready }">
                <!-- Mobile logo -->
                <div class="flex items-center gap-2 mb-10 lg:hidden">
                    <PitchFlowLogo size="w-8 h-8" />
                    <span class="text-lg font-bold text-surface-100">PitchFlow</span>
                </div>

                <div class="mb-8">
                    <h1 class="text-2xl font-bold text-surface-100">Welcome back</h1>
                    <p class="mt-2 text-sm text-surface-400">Sign in to your account to continue</p>
                </div>

                <div v-if="status" class="mb-6 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-sm text-emerald-400">
                    {{ status }}
                </div>

                <!-- Face login error -->
                <div v-if="faceError" class="mb-6 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/20 text-sm text-red-400 flex items-start gap-2.5">
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                    <span>{{ faceError }}</span>
                </div>

                <!-- Face processing overlay -->
                <div v-if="faceProcessing" class="mb-6 px-4 py-5 rounded-xl bg-surface-800/60 border border-surface-700/30 text-center">
                    <div class="w-10 h-10 rounded-full bg-brand/10 flex items-center justify-center mx-auto mb-3">
                        <div class="w-5 h-5 border-2 border-brand/30 border-t-brand rounded-full animate-spin"></div>
                    </div>
                    <p class="text-sm font-medium text-surface-200">Verifying your identity...</p>
                    <p class="text-xs text-surface-500 mt-1">Checking multiple angles for security</p>
                </div>

                <form @submit.prevent="submit" class="space-y-5">
                    <div>
                        <label for="email" class="block text-sm font-medium text-surface-300 mb-1.5">Email</label>
                        <input
                            id="email"
                            type="email"
                            v-model="form.email"
                            required
                            autofocus
                            autocomplete="username"
                            class="input-field"
                            placeholder="you@company.com"
                        />
                        <InputError class="mt-1.5" :message="form.errors.email" />
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-surface-300 mb-1.5">Password</label>
                        <input
                            id="password"
                            type="password"
                            v-model="form.password"
                            required
                            autocomplete="current-password"
                            class="input-field"
                            placeholder="Enter your password"
                        />
                        <InputError class="mt-1.5" :message="form.errors.password" />
                    </div>

                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="checkbox"
                                v-model="form.remember"
                                class="w-4 h-4 rounded bg-surface-800 border-surface-600 text-brand focus:ring-brand/30 focus:ring-offset-0"
                            />
                            <span class="text-sm text-surface-400">Remember me</span>
                        </label>

                        <Link
                            v-if="canResetPassword"
                            :href="route('password.request')"
                            class="text-sm text-surface-400 hover:text-brand transition-colors"
                        >
                            Forgot password?
                        </Link>
                    </div>

                    <button
                        type="submit"
                        class="btn-primary w-full py-3 text-sm"
                        :class="{ 'opacity-50 pointer-events-none': form.processing }"
                        :disabled="form.processing"
                    >
                        <span v-if="!form.processing">Sign in</span>
                        <span v-else class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Signing in...
                        </span>
                    </button>
                </form>

                <!-- Divider -->
                <div class="my-6 flex items-center gap-3">
                    <div class="flex-1 h-px bg-surface-800/50"></div>
                    <span class="text-xs text-surface-600 uppercase tracking-wider font-medium">or</span>
                    <div class="flex-1 h-px bg-surface-800/50"></div>
                </div>

                <!-- Face ID Login Button -->
                <button
                    @click="startFaceLogin"
                    :disabled="faceProcessing"
                    class="w-full py-3 text-sm font-medium rounded-xl transition-all flex items-center justify-center gap-2.5 border border-surface-700/40 bg-surface-800/40 hover:bg-surface-800/70 hover:border-surface-600/50 text-surface-300 hover:text-surface-100 disabled:opacity-40 disabled:pointer-events-none"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75H6A2.25 2.25 0 0 0 3.75 6v1.5M16.5 3.75H18A2.25 2.25 0 0 1 20.25 6v1.5m0 9V18A2.25 2.25 0 0 1 18 20.25h-1.5m-9 0H6A2.25 2.25 0 0 1 3.75 18v-1.5"/>
                    </svg>
                    Sign in with Face ID
                </button>

                <div class="mt-8 pt-6 border-t border-surface-800/50 text-center">
                    <p class="text-sm text-surface-500">
                        Looking to streamline your agency's proposals and manage your bidders or BDEs from one place?
                    </p>
                    <Link :href="route('welcome')" class="inline-flex items-center gap-1.5 mt-2 text-sm text-brand hover:text-brand-light transition-colors font-medium">
                        See how PitchFlow works
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </Link>
                </div>
            </div>
        </div>
    </div>

    <!-- Face Scan (fullscreen via Teleport inside FaceScanCapture) -->
    <FaceScanCapture v-if="faceScanning" autostart @complete="onFaceScanComplete" @cancel="onFaceScanCancel" />
</template>
