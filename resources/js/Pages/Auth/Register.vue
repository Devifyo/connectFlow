<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import { onMounted, ref } from 'vue';

const form = useForm({
    name: '',
    company_name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const ready = ref(false);
onMounted(() => { ready.value = true; });

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Create your agency" />

    <div class="min-h-screen bg-surface-950 flex">
        <!-- Left panel — branding -->
        <div class="hidden lg:flex lg:w-[45%] bg-surface-900 border-r border-surface-800/50 flex-col justify-between p-12 relative overflow-hidden">
            <div class="absolute inset-0 pointer-events-none" aria-hidden="true">
                <div class="absolute top-[-10%] right-[-10%] w-[400px] h-[400px] rounded-full bg-brand/4 blur-[100px]"></div>
            </div>

            <div class="relative z-10">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-lg bg-brand flex items-center justify-center">
                        <svg class="w-4.5 h-4.5 text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <span class="text-xl font-bold text-surface-100">ConnectFlow</span>
                </div>
            </div>

            <div class="relative z-10 max-w-sm">
                <h2 class="text-2xl font-bold text-surface-100 mb-4">Set up in under 2 minutes</h2>
                <ul class="space-y-4">
                    <li class="flex items-start gap-3">
                        <div class="mt-0.5 w-5 h-5 rounded-full bg-brand/20 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <span class="text-sm text-surface-300">Create your agency workspace</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <div class="mt-0.5 w-5 h-5 rounded-full bg-brand/20 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <span class="text-sm text-surface-300">Invite your bidding team</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <div class="mt-0.5 w-5 h-5 rounded-full bg-brand/20 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <span class="text-sm text-surface-300">Start tracking bids immediately</span>
                    </li>
                </ul>
            </div>

            <div class="relative z-10 text-xs text-surface-600">
                Free 14-day trial. No card required.
            </div>
        </div>

        <!-- Right panel — form -->
        <div class="flex-1 flex flex-col justify-center px-6 sm:px-12 lg:px-20 py-12">
            <div class="w-full max-w-sm mx-auto" :class="{ 'animate-fade-in': ready }">
                <!-- Mobile logo -->
                <div class="flex items-center gap-2 mb-10 lg:hidden">
                    <div class="w-8 h-8 rounded-lg bg-brand flex items-center justify-center">
                        <svg class="w-4 h-4 text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <span class="text-lg font-bold text-surface-100">ConnectFlow</span>
                </div>

                <div class="mb-8">
                    <h1 class="text-2xl font-bold text-surface-100">Create your agency</h1>
                    <p class="mt-2 text-sm text-surface-400">Get your team set up and start winning more bids</p>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label for="name" class="block text-sm font-medium text-surface-300 mb-1.5">Your name</label>
                        <input
                            id="name"
                            type="text"
                            v-model="form.name"
                            required
                            autofocus
                            class="input-field"
                            placeholder="Jane Smith"
                        />
                        <InputError class="mt-1.5" :message="form.errors.name" />
                    </div>

                    <div>
                        <label for="company_name" class="block text-sm font-medium text-surface-300 mb-1.5">Agency name</label>
                        <input
                            id="company_name"
                            type="text"
                            v-model="form.company_name"
                            required
                            class="input-field"
                            placeholder="Acme Agency"
                        />
                        <InputError class="mt-1.5" :message="form.errors.company_name" />
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-surface-300 mb-1.5">Work email</label>
                        <input
                            id="email"
                            type="email"
                            v-model="form.email"
                            required
                            autocomplete="username"
                            class="input-field"
                            placeholder="jane@acme.com"
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
                            autocomplete="new-password"
                            class="input-field"
                            placeholder="Min. 8 characters"
                        />
                        <InputError class="mt-1.5" :message="form.errors.password" />
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-surface-300 mb-1.5">Confirm password</label>
                        <input
                            id="password_confirmation"
                            type="password"
                            v-model="form.password_confirmation"
                            required
                            autocomplete="new-password"
                            class="input-field"
                            placeholder="Re-enter password"
                        />
                        <InputError class="mt-1.5" :message="form.errors.password_confirmation" />
                    </div>

                    <button
                        type="submit"
                        class="btn-primary w-full py-3 text-sm mt-2"
                        :class="{ 'opacity-50 pointer-events-none': form.processing }"
                        :disabled="form.processing"
                    >
                        <span v-if="!form.processing">Create agency account</span>
                        <span v-else class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Creating...
                        </span>
                    </button>
                </form>

                <p class="mt-8 text-sm text-surface-500 text-center">
                    Already have an account?
                    <Link :href="route('login')" class="text-brand hover:text-brand-light transition-colors font-medium">
                        Sign in
                    </Link>
                </p>
            </div>
        </div>
    </div>
</template>
