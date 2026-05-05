<script setup>
import { Head, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const props = defineProps(['auth']);

const ready = ref(false);
onMounted(() => { ready.value = true; });
</script>

<template>
    <Head title="Bid Pipeline" />

    <div class="min-h-screen bg-surface-950 text-surface-100 flex">
        <!-- Sidebar -->
        <aside class="hidden lg:flex w-60 flex-col border-r border-surface-800/50 bg-surface-900/50">
            <div class="p-5 border-b border-surface-800/50">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-md bg-brand flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <span class="text-sm font-bold">ConnectFlow</span>
                </div>
            </div>

            <nav class="flex-1 px-3 py-4 space-y-1">
                <a href="#" class="sidebar-link-active">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15m-10.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125z" />
                    </svg>
                    Pipeline
                </a>
                <a href="#" class="sidebar-link">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                    Team
                </a>
                <a href="#" class="sidebar-link">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                    </svg>
                    Reports
                </a>
            </nav>

            <!-- User section at bottom -->
            <div class="p-4 border-t border-surface-800/50">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-surface-700 flex items-center justify-center text-xs font-semibold text-surface-300">
                        {{ auth.user.name.charAt(0).toUpperCase() }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-surface-200 truncate">{{ auth.user.name }}</p>
                        <p class="text-xs text-surface-500">Admin</p>
                    </div>
                </div>
                <button @click="router.post('/logout')" class="mt-3 w-full btn-ghost text-xs justify-center">
                    Sign out
                </button>
            </div>
        </aside>

        <!-- Main content -->
        <div class="flex-1 flex flex-col min-h-screen overflow-hidden">
            <!-- Top bar -->
            <header class="h-16 border-b border-surface-800/50 px-6 flex items-center justify-between flex-shrink-0">
                <div>
                    <h1 class="text-lg font-semibold text-surface-100">Bid Pipeline</h1>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-surface-800/50 border border-surface-700/30">
                        <span class="text-xs text-surface-400">Conversion</span>
                        <span class="text-sm font-semibold text-brand">18.4%</span>
                    </div>
                </div>
            </header>

            <!-- Kanban board -->
            <main class="flex-1 p-6 overflow-x-auto" :class="{ 'animate-fade-in': ready }">
                <div class="grid grid-cols-3 gap-5 h-full min-h-[500px]">
                    <!-- Column: Submitted -->
                    <div class="flex flex-col rounded-2xl bg-surface-900/50 border border-surface-800/40">
                        <div class="px-4 py-3 border-b border-surface-800/30 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-2 h-2 rounded-full bg-blue-400"></div>
                                <h2 class="text-sm font-semibold text-surface-200">Submitted</h2>
                            </div>
                            <span class="text-xs font-medium text-surface-500 bg-surface-800/50 px-2 py-0.5 rounded-md">12</span>
                        </div>

                        <div class="flex-1 p-3 space-y-2.5 overflow-y-auto scrollbar-thin">
                            <div class="card card-hover p-4 cursor-grab active:cursor-grabbing">
                                <p class="text-sm font-medium text-surface-200 mb-3 leading-snug">Senior Laravel Developer for SaaS Platform</p>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-surface-500">Upwork &middot; 16 connects</span>
                                    <div class="w-6 h-6 rounded-full bg-indigo-500/15 flex items-center justify-center text-[10px] font-bold text-indigo-400">A</div>
                                </div>
                            </div>

                            <div class="card card-hover p-4 cursor-grab active:cursor-grabbing">
                                <p class="text-sm font-medium text-surface-200 mb-3 leading-snug">React Native App — MVP Build</p>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-surface-500">Freelancer &middot; 8 connects</span>
                                    <div class="w-6 h-6 rounded-full bg-emerald-500/15 flex items-center justify-center text-[10px] font-bold text-emerald-400">M</div>
                                </div>
                            </div>

                            <div class="card card-hover p-4 cursor-grab active:cursor-grabbing">
                                <p class="text-sm font-medium text-surface-200 mb-3 leading-snug">WordPress to Headless CMS Migration</p>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-surface-500">Upwork &middot; 12 connects</span>
                                    <div class="w-6 h-6 rounded-full bg-amber-500/15 flex items-center justify-center text-[10px] font-bold text-amber-400">J</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Column: Interviewing -->
                    <div class="flex flex-col rounded-2xl bg-surface-900/50 border border-surface-800/40">
                        <div class="px-4 py-3 border-b border-surface-800/30 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-2 h-2 rounded-full bg-amber-400"></div>
                                <h2 class="text-sm font-semibold text-surface-200">Interviewing</h2>
                            </div>
                            <span class="text-xs font-medium text-surface-500 bg-surface-800/50 px-2 py-0.5 rounded-md">3</span>
                        </div>

                        <div class="flex-1 p-3 space-y-2.5 overflow-y-auto scrollbar-thin">
                            <div class="card card-hover p-4 cursor-grab active:cursor-grabbing border-l-2 border-l-amber-400/50">
                                <p class="text-sm font-medium text-surface-200 mb-3 leading-snug">Full-Stack Engineer — Fintech Startup</p>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-surface-500">Upwork &middot; Interview Thu</span>
                                    <div class="w-6 h-6 rounded-full bg-indigo-500/15 flex items-center justify-center text-[10px] font-bold text-indigo-400">A</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Column: Hired -->
                    <div class="flex flex-col rounded-2xl bg-surface-900/50 border border-surface-800/40">
                        <div class="px-4 py-3 border-b border-surface-800/30 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-2 h-2 rounded-full bg-brand"></div>
                                <h2 class="text-sm font-semibold text-surface-200">Hired</h2>
                            </div>
                            <span class="text-xs font-medium text-surface-500 bg-surface-800/50 px-2 py-0.5 rounded-md">1</span>
                        </div>

                        <div class="flex-1 p-3 space-y-2.5 overflow-y-auto scrollbar-thin">
                            <div class="card p-4 border-l-2 border-l-brand/50">
                                <p class="text-sm font-medium text-surface-200 mb-3 leading-snug">DevOps Lead — Long-term Contract</p>
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-1.5">
                                        <span class="badge-success">Hired</span>
                                    </div>
                                    <div class="w-6 h-6 rounded-full bg-emerald-500/15 flex items-center justify-center text-[10px] font-bold text-emerald-400">M</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</template>
