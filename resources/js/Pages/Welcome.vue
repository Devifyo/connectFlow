<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
    laravelVersion: { type: String, required: true },
    phpVersion: { type: String, required: true },
});

const isVisible = ref(false);
onMounted(() => {
    setTimeout(() => { isVisible.value = true; }, 100);
});
</script>

<template>
    <Head title="ConnectFlow — Freelance Bidding Intelligence" />

    <div class="min-h-screen bg-surface-950 text-surface-100 overflow-hidden">
        <!-- Ambient background -->
        <div class="fixed inset-0 pointer-events-none" aria-hidden="true">
            <div class="absolute top-[-20%] right-[-10%] w-[600px] h-[600px] rounded-full bg-brand/5 blur-[128px]"></div>
            <div class="absolute bottom-[-10%] left-[-5%] w-[400px] h-[400px] rounded-full bg-brand/3 blur-[100px]"></div>
        </div>

        <!-- Nav -->
        <nav class="relative z-10 max-w-6xl mx-auto px-6 py-6 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-brand flex items-center justify-center">
                    <svg class="w-4 h-4 text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
                <span class="text-lg font-bold text-surface-100">ConnectFlow</span>
            </div>

            <div v-if="canLogin" class="flex items-center gap-3">
                <Link
                    v-if="$page.props.auth.user"
                    :href="route('dashboard')"
                    class="btn-secondary text-sm"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link :href="route('login')" class="btn-ghost text-sm">
                        Sign in
                    </Link>
                    <Link v-if="canRegister" :href="route('register')" class="btn-primary text-sm">
                        Get Started
                    </Link>
                </template>
            </div>
        </nav>

        <!-- Hero -->
        <main class="relative z-10">
            <section class="max-w-6xl mx-auto px-6 pt-20 pb-32">
                <div class="max-w-3xl" :class="{ 'animate-fade-in': isVisible }">
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-800/80 border border-surface-700/50 text-xs font-medium text-surface-300 mb-8">
                        <span class="w-1.5 h-1.5 rounded-full bg-brand animate-pulse-soft"></span>
                        Now managing 2,400+ freelance bids weekly
                    </div>

                    <h1 class="text-5xl sm:text-6xl lg:text-7xl font-extrabold leading-[1.05] tracking-tight">
                        Stop losing bids<br>
                        <span class="text-gradient">to disorganization.</span>
                    </h1>

                    <p class="mt-6 text-lg text-surface-400 leading-relaxed max-w-xl">
                        ConnectFlow gives freelance agencies a shared command center to track bids,
                        prevent duplicates, and close more contracts — without the spreadsheet chaos.
                    </p>

                    <div class="mt-10 flex flex-wrap items-center gap-4">
                        <Link v-if="canRegister" :href="route('register')" class="btn-primary text-base px-7 py-3.5">
                            Start Free Trial
                            <svg class="w-4 h-4 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </Link>
                        <span class="text-sm text-surface-500">No credit card required</span>
                    </div>
                </div>

                <!-- Feature grid -->
                <div class="mt-24 grid sm:grid-cols-2 lg:grid-cols-3 gap-5" :class="{ 'animate-slide-up': isVisible }">
                    <div class="card card-hover p-6 group">
                        <div class="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4 group-hover:bg-brand/15 transition-colors">
                            <svg class="w-5 h-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                        </div>
                        <h3 class="font-semibold text-surface-100 mb-1.5">Collision Detection</h3>
                        <p class="text-sm text-surface-400 leading-relaxed">Instantly know if a teammate already bid on the same job. No more embarrassing duplicate proposals.</p>
                    </div>

                    <div class="card card-hover p-6 group">
                        <div class="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4 group-hover:bg-brand/15 transition-colors">
                            <svg class="w-5 h-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                            </svg>
                        </div>
                        <h3 class="font-semibold text-surface-100 mb-1.5">Kanban Pipeline</h3>
                        <p class="text-sm text-surface-400 leading-relaxed">Visual pipeline from submission to contract. Drag bids between stages and never lose track.</p>
                    </div>

                    <div class="card card-hover p-6 group">
                        <div class="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4 group-hover:bg-brand/15 transition-colors">
                            <svg class="w-5 h-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h3 class="font-semibold text-surface-100 mb-1.5">Time Tracking</h3>
                        <p class="text-sm text-surface-400 leading-relaxed">Built-in punch clock for bidders. Track who's working, when, and how productive each shift is.</p>
                    </div>

                    <div class="card card-hover p-6 group">
                        <div class="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4 group-hover:bg-brand/15 transition-colors">
                            <svg class="w-5 h-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                            </svg>
                        </div>
                        <h3 class="font-semibold text-surface-100 mb-1.5">Multi-Tenant</h3>
                        <p class="text-sm text-surface-400 leading-relaxed">Each agency gets an isolated workspace. Manage multiple teams from a single admin panel.</p>
                    </div>

                    <div class="card card-hover p-6 group">
                        <div class="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4 group-hover:bg-brand/15 transition-colors">
                            <svg class="w-5 h-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                            </svg>
                        </div>
                        <h3 class="font-semibold text-surface-100 mb-1.5">Analytics</h3>
                        <p class="text-sm text-surface-400 leading-relaxed">Conversion rates, connect spend, and win/loss ratios. Data-driven decisions, not gut feelings.</p>
                    </div>

                    <div class="card card-hover p-6 group">
                        <div class="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4 group-hover:bg-brand/15 transition-colors">
                            <svg class="w-5 h-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                            </svg>
                        </div>
                        <h3 class="font-semibold text-surface-100 mb-1.5">Role-Based Access</h3>
                        <p class="text-sm text-surface-400 leading-relaxed">Admins see everything. Bidders see their lane. Clean separation keeps everyone focused.</p>
                    </div>
                </div>
            </section>

            <!-- Footer -->
            <footer class="relative z-10 border-t border-surface-800/50">
                <div class="max-w-6xl mx-auto px-6 py-8 flex items-center justify-between">
                    <p class="text-sm text-surface-500">&copy; 2024 ConnectFlow. Built for agencies that win.</p>
                    <p class="text-xs text-surface-600">Laravel v{{ laravelVersion }}</p>
                </div>
            </footer>
        </main>
    </div>
</template>
