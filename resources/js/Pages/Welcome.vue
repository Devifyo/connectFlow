<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import gsap from 'gsap';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
    laravelVersion: { type: String, required: true },
    phpVersion: { type: String, required: true },
});

onMounted(() => {
    gsap.set('.anim', { opacity: 0, y: 20 });
    gsap.set('.hero-visual', { opacity: 0, x: 40 });

    const tl = gsap.timeline({ defaults: { ease: 'power2.out', duration: 0.4 } });

    tl.to('.nav-anim', { opacity: 1, y: 0, stagger: 0.05 })
      .to('.hero-badge', { opacity: 1, y: 0 }, '-=0.2')
      .to('.hero-line', { opacity: 1, y: 0, stagger: 0.08 }, '-=0.2')
      .to('.hero-body', { opacity: 1, y: 0 }, '-=0.15')
      .to('.hero-actions', { opacity: 1, y: 0 }, '-=0.15')
      .to('.hero-visual', { opacity: 1, x: 0, duration: 0.6 }, '-=0.3')
      .to('.stat-item', { opacity: 1, y: 0, stagger: 0.05 }, '-=0.3')
      .to('.section-heading', { opacity: 1, y: 0 }, '-=0.2')
      .to('.feature-card', { opacity: 1, y: 0, stagger: 0.06 }, '-=0.2')
      .to('.cta-section', { opacity: 1, y: 0 }, '-=0.15')
      .to('.footer-anim', { opacity: 1, y: 0 }, '-=0.1');

    gsap.to('.float-element', {
        y: -8, duration: 2.5, ease: 'sine.inOut', yoyo: true, repeat: -1,
    });
    gsap.to('.float-notification', {
        y: -5, duration: 3, ease: 'sine.inOut', yoyo: true, repeat: -1, delay: 0.5,
    });
    gsap.to('.float-alert', {
        y: 4, duration: 2.8, ease: 'sine.inOut', yoyo: true, repeat: -1, delay: 1,
    });
});
</script>

<template>
    <Head title="ConnectFlow — Freelance Bidding Intelligence" />

    <div class="min-h-screen bg-surface-950 text-surface-100 overflow-hidden">
        <!-- Atmospheric blobs -->
        <div class="fixed inset-0 pointer-events-none" aria-hidden="true">
            <div class="absolute -top-32 -right-32 w-[600px] h-[600px] rounded-full bg-brand/5 blur-[128px]"></div>
            <div class="absolute -bottom-32 -left-24 w-[400px] h-[400px] rounded-full bg-brand/3 blur-[100px]"></div>
        </div>

        <!-- Navigation -->
        <nav class="relative z-20 max-w-7xl mx-auto px-6 sm:px-8 py-5 flex items-center justify-between">
            <div class="nav-anim anim flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-brand flex items-center justify-center shadow-brand-glow">
                    <svg class="w-[18px] h-[18px] text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
                <span class="text-lg font-bold tracking-tight">ConnectFlow</span>
            </div>

            <div v-if="canLogin" class="flex items-center gap-3">
                <Link
                    v-if="$page.props.auth.user"
                    :href="route('dashboard')"
                    class="nav-anim anim btn-secondary text-sm"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link :href="route('login')" class="nav-anim anim btn-ghost text-sm">
                        Sign in
                    </Link>
                    <Link v-if="canRegister" :href="route('register')" class="nav-anim anim btn-primary text-sm">
                        Get Started
                    </Link>
                </template>
            </div>
        </nav>

        <main class="relative z-10">
            <!-- Hero -->
            <section class="max-w-7xl mx-auto px-6 sm:px-8 pt-12 sm:pt-20 pb-16">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-10 md:gap-12">
                    <!-- Left: content -->
                    <div class="flex-1 max-w-2xl">
                        <div class="hero-badge anim inline-flex items-center gap-2.5 px-4 py-2 rounded-full bg-surface-800/80 border border-surface-700/50 mb-6">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-brand opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-brand"></span>
                            </span>
                            <span class="text-xs font-medium text-surface-300 tracking-wide">Managing 2,400+ bids weekly</span>
                        </div>

                        <h1 class="mb-5">
                            <span class="hero-line anim block text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight leading-[1.08]">
                                Stop losing bids
                            </span>
                            <span class="hero-line anim block text-4xl sm:text-5xl lg:text-6xl font-display italic tracking-tight leading-[1.08] text-gradient mt-1">
                                to disorganization.
                            </span>
                        </h1>

                        <p class="hero-body anim text-lg text-surface-400 leading-relaxed max-w-lg">
                            ConnectFlow gives freelance agencies a shared command center to track bids,
                            prevent duplicates, and close more contracts — without the spreadsheet chaos.
                        </p>

                        <div class="hero-actions anim mt-8 flex items-center gap-4">
                            <Link v-if="canRegister" :href="route('register')" class="group btn-primary text-base px-7 py-3.5">
                                Start Free Trial
                                <svg class="w-4 h-4 ml-2 transition-transform duration-200 group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                </svg>
                            </Link>
                            <span class="text-sm text-surface-500">No credit card required</span>
                        </div>
                    </div>

                    <!-- Right: Dashboard Mockup -->
                    <div class="hero-visual hidden md:block flex-shrink-0">
                        <div class="float-element relative">
                            <div class="w-[360px] lg:w-[400px] bg-surface-800/80 backdrop-blur-xl border border-surface-700/50 rounded-2xl p-5 lg:p-6 shadow-elevation-4 transform rotate-1">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-2.5 h-2.5 rounded-full bg-brand"></div>
                                        <span class="text-sm font-semibold text-surface-200">Pipeline Overview</span>
                                    </div>
                                    <span class="text-xs text-surface-500 font-medium">Live</span>
                                </div>

                                <div class="grid grid-cols-3 gap-2.5 mb-4">
                                    <div class="bg-surface-900/60 rounded-xl p-2.5 border border-surface-700/30">
                                        <div class="text-lg font-bold text-surface-100">47</div>
                                        <div class="text-[10px] text-surface-500 mt-0.5">Active Bids</div>
                                    </div>
                                    <div class="bg-surface-900/60 rounded-xl p-2.5 border border-surface-700/30">
                                        <div class="text-lg font-bold text-brand">73%</div>
                                        <div class="text-[10px] text-surface-500 mt-0.5">Win Rate</div>
                                    </div>
                                    <div class="bg-surface-900/60 rounded-xl p-2.5 border border-surface-700/30">
                                        <div class="text-lg font-bold text-surface-100">$12k</div>
                                        <div class="text-[10px] text-surface-500 mt-0.5">Revenue</div>
                                    </div>
                                </div>

                                <div class="flex items-end gap-1.5 h-16 mb-3 px-1">
                                    <div class="flex-1 bg-brand/15 rounded-sm" style="height: 35%"></div>
                                    <div class="flex-1 bg-brand/25 rounded-sm" style="height: 55%"></div>
                                    <div class="flex-1 bg-brand/20 rounded-sm" style="height: 45%"></div>
                                    <div class="flex-1 bg-brand/40 rounded-sm" style="height: 70%"></div>
                                    <div class="flex-1 bg-brand/30 rounded-sm" style="height: 50%"></div>
                                    <div class="flex-1 bg-brand/50 rounded-sm" style="height: 85%"></div>
                                    <div class="flex-1 bg-brand rounded-sm" style="height: 95%"></div>
                                    <div class="flex-1 bg-brand/60 rounded-sm" style="height: 75%"></div>
                                    <div class="flex-1 bg-brand/35 rounded-sm" style="height: 60%"></div>
                                </div>

                                <div class="flex items-center gap-2">
                                    <div class="flex-1 h-1.5 rounded-full bg-brand"></div>
                                    <div class="flex-1 h-1.5 rounded-full bg-brand/50"></div>
                                    <div class="flex-1 h-1.5 rounded-full bg-brand/25"></div>
                                    <div class="flex-1 h-1.5 rounded-full bg-surface-700/50"></div>
                                </div>
                            </div>

                            <!-- Bid Won notification -->
                            <div class="float-notification absolute -left-10 lg:-left-14 bottom-8 w-56 lg:w-60 bg-surface-800/95 backdrop-blur-xl border border-surface-700/50 rounded-xl p-3 shadow-elevation-3 transform -rotate-2">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-7 h-7 rounded-lg bg-emerald-500/15 flex items-center justify-center flex-shrink-0">
                                        <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-xs font-semibold text-surface-100">Bid Won!</div>
                                        <div class="text-[10px] text-surface-500 truncate">React Dashboard — $4,200</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Collision alert -->
                            <div class="float-alert absolute -right-4 lg:-right-6 top-2 w-48 lg:w-52 bg-surface-800/95 backdrop-blur-xl border border-amber-500/20 rounded-xl p-2.5 shadow-elevation-3 transform rotate-2">
                                <div class="flex items-center gap-2">
                                    <div class="w-6 h-6 rounded-lg bg-amber-500/15 flex items-center justify-center flex-shrink-0">
                                        <svg class="w-3 h-3 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-[10px] font-semibold text-amber-300">Collision Alert</div>
                                        <div class="text-[9px] text-surface-500">2 bids on same job</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Stats -->
            <section class="border-y border-surface-800/50">
                <div class="max-w-7xl mx-auto px-6 sm:px-8">
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-px">
                        <div class="stat-item anim py-8 sm:py-10 pr-6 sm:pr-8">
                            <div class="text-2xl sm:text-3xl font-extrabold text-surface-100 tracking-tight">2,400+</div>
                            <div class="text-xs sm:text-sm text-surface-500 mt-1">Bids managed weekly</div>
                        </div>
                        <div class="stat-item anim py-8 sm:py-10 px-6 sm:px-8 border-l border-surface-800/50">
                            <div class="text-2xl sm:text-3xl font-extrabold text-brand tracking-tight">73%</div>
                            <div class="text-xs sm:text-sm text-surface-500 mt-1">Win rate improvement</div>
                        </div>
                        <div class="stat-item anim py-8 sm:py-10 px-6 sm:px-8 border-l border-surface-800/50 border-t lg:border-t-0">
                            <div class="text-2xl sm:text-3xl font-extrabold text-surface-100 tracking-tight">12 min</div>
                            <div class="text-xs sm:text-sm text-surface-500 mt-1">Saved per bid</div>
                        </div>
                        <div class="stat-item anim py-8 sm:py-10 pl-6 sm:pl-8 border-l border-surface-800/50 border-t lg:border-t-0">
                            <div class="text-2xl sm:text-3xl font-extrabold text-surface-100 tracking-tight">Zero</div>
                            <div class="text-xs sm:text-sm text-surface-500 mt-1">Duplicate bids</div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Features -->
            <section class="max-w-7xl mx-auto px-6 sm:px-8 py-16 sm:py-20">
                <div class="section-heading anim max-w-2xl mb-10">
                    <h2 class="text-2xl sm:text-3xl font-extrabold tracking-tight mb-3">
                        Everything your agency needs to
                        <span class="font-display italic text-gradient">win more.</span>
                    </h2>
                    <p class="text-surface-400 text-base sm:text-lg">Purpose-built tools for freelance bidding teams. No bloat, no learning curve.</p>
                </div>

                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div class="feature-card anim sm:col-span-2 card card-hover p-6 group">
                        <div class="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4 group-hover:bg-brand/15 transition-colors">
                            <svg class="w-5 h-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                        </div>
                        <h3 class="font-semibold text-surface-100 mb-1.5">Collision Detection</h3>
                        <p class="text-sm text-surface-400 leading-relaxed max-w-md">Instantly know if a teammate already bid on the same job. Real-time alerts prevent embarrassing duplicate proposals and wasted connects.</p>
                    </div>

                    <div class="feature-card anim card card-hover p-6 group">
                        <div class="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4 group-hover:bg-brand/15 transition-colors">
                            <svg class="w-5 h-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                            </svg>
                        </div>
                        <h3 class="font-semibold text-surface-100 mb-1.5">Kanban Pipeline</h3>
                        <p class="text-sm text-surface-400 leading-relaxed">Visual pipeline from submission to contract. Drag bids between stages and never lose track.</p>
                    </div>

                    <div class="feature-card anim card card-hover p-6 group">
                        <div class="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4 group-hover:bg-brand/15 transition-colors">
                            <svg class="w-5 h-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h3 class="font-semibold text-surface-100 mb-1.5">Time Tracking</h3>
                        <p class="text-sm text-surface-400 leading-relaxed">Built-in punch clock for bidders. Track who's working, when, and how productive each shift is.</p>
                    </div>

                    <div class="feature-card anim sm:col-span-2 card card-hover p-6 group">
                        <div class="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4 group-hover:bg-brand/15 transition-colors">
                            <svg class="w-5 h-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                            </svg>
                        </div>
                        <h3 class="font-semibold text-surface-100 mb-1.5">Multi-Tenant Workspaces</h3>
                        <p class="text-sm text-surface-400 leading-relaxed max-w-md">Each agency gets an isolated workspace with its own data, settings, and team. Manage multiple organizations from a single admin panel.</p>
                    </div>

                    <div class="feature-card anim card card-hover p-6 group">
                        <div class="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4 group-hover:bg-brand/15 transition-colors">
                            <svg class="w-5 h-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                            </svg>
                        </div>
                        <h3 class="font-semibold text-surface-100 mb-1.5">Analytics</h3>
                        <p class="text-sm text-surface-400 leading-relaxed">Conversion rates, connect spend, and win/loss ratios. Data-driven decisions, not gut feelings.</p>
                    </div>

                    <div class="feature-card anim card card-hover p-6 group">
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

            <!-- CTA -->
            <section class="cta-section anim max-w-7xl mx-auto px-6 sm:px-8 pb-16 sm:pb-20">
                <div class="card rounded-3xl overflow-hidden">
                    <div class="relative px-8 sm:px-12 py-12 sm:py-14 text-center">
                        <div class="absolute inset-0 bg-gradient-to-br from-brand/[0.06] via-transparent to-transparent pointer-events-none"></div>
                        <div class="relative">
                            <h2 class="text-2xl sm:text-3xl font-extrabold tracking-tight mb-3">
                                Ready to <span class="font-display italic text-gradient">stop the chaos?</span>
                            </h2>
                            <p class="text-surface-400 text-base sm:text-lg mb-7 max-w-lg mx-auto">Join agencies already using ConnectFlow to eliminate duplicate bids and win more contracts.</p>
                            <Link v-if="canRegister" :href="route('register')" class="group btn-primary text-base px-8 py-3.5">
                                Get Started Free
                                <svg class="w-4 h-4 ml-2 transition-transform duration-200 group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                </svg>
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Footer -->
            <footer class="footer-anim anim border-t border-surface-800/50">
                <div class="max-w-7xl mx-auto px-6 sm:px-8 py-6 flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-lg bg-brand/20 flex items-center justify-center">
                                <svg class="w-3 h-3 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                            </div>
                            <span class="text-sm font-semibold text-surface-400">ConnectFlow</span>
                        </div>
                        <p class="text-sm text-surface-600 hidden sm:block">Built for agencies that win.</p>
                    </div>
                    <p class="text-xs text-surface-600">Laravel v{{ laravelVersion }}</p>
                </div>
            </footer>
        </main>
    </div>
</template>
