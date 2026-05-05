<script setup>
import { Head, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const props = defineProps(['auth']);

const ready = ref(false);
onMounted(() => { ready.value = true; });
</script>

<template>
    <Head title="Command Center" />

    <div class="min-h-screen bg-surface-950 text-surface-100">
        <!-- Top bar -->
        <nav class="sticky top-0 z-50 bg-surface-950/80 backdrop-blur-xl border-b border-surface-800/50">
            <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-7 h-7 rounded-md bg-brand flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <span class="text-sm font-bold">ConnectFlow</span>
                    <span class="badge-neutral text-xs ml-2">Super Admin</span>
                </div>

                <div class="flex items-center gap-4">
                    <span class="text-sm text-surface-400">{{ auth.user.name }}</span>
                    <button @click="router.post('/logout')" class="btn-ghost text-xs">Sign out</button>
                </div>
            </div>
        </nav>

        <main class="max-w-7xl mx-auto px-6 py-8" :class="{ 'animate-fade-in': ready }">
            <!-- Page header -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-surface-100">Command Center</h1>
                <p class="text-sm text-surface-400 mt-1">Platform-wide tenant management and oversight</p>
            </div>

            <!-- Stats row -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Total Tenants</span>
                    <span class="text-2xl font-semibold text-surface-100 mt-1">42</span>
                    <span class="text-xs text-emerald-400 mt-1">+3 this month</span>
                </div>
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Monthly Revenue</span>
                    <span class="text-2xl font-semibold text-surface-100 mt-1">$12,450</span>
                    <span class="text-xs text-emerald-400 mt-1">+8.2% MoM</span>
                </div>
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Active Bidders</span>
                    <span class="text-2xl font-semibold text-surface-100 mt-1">187</span>
                    <span class="text-xs text-surface-500 mt-1">across all tenants</span>
                </div>
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Bids Today</span>
                    <span class="text-2xl font-semibold text-surface-100 mt-1">324</span>
                    <span class="text-xs text-amber-400 mt-1">12 collisions prevented</span>
                </div>
            </div>

            <!-- Tenants table -->
            <div class="card overflow-hidden">
                <div class="px-6 py-4 border-b border-surface-700/30 flex items-center justify-between">
                    <h2 class="font-semibold text-surface-100">Registered Agencies</h2>
                    <button class="btn-primary text-xs px-4 py-2">
                        <svg class="w-3.5 h-3.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Add Tenant
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-surface-700/30">
                                <th class="px-6 py-3 text-left table-header">ID</th>
                                <th class="px-6 py-3 text-left table-header">Company</th>
                                <th class="px-6 py-3 text-left table-header">Plan</th>
                                <th class="px-6 py-3 text-left table-header">Status</th>
                                <th class="px-6 py-3 text-left table-header">Bidders</th>
                                <th class="px-6 py-3 text-right table-header">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm">
                            <tr class="border-b border-surface-800/30 hover:bg-surface-800/30 transition-colors">
                                <td class="px-6 py-4 font-mono text-xs text-surface-500">#001</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-indigo-500/10 flex items-center justify-center text-xs font-bold text-indigo-400">A</div>
                                        <div>
                                            <p class="font-medium text-surface-200">Alpha Agency</p>
                                            <p class="text-xs text-surface-500">alpha@agency.co</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4"><span class="badge-neutral">Pro</span></td>
                                <td class="px-6 py-4"><span class="badge-success">Active</span></td>
                                <td class="px-6 py-4 text-surface-300">8</td>
                                <td class="px-6 py-4 text-right">
                                    <button class="btn-ghost text-xs">Edit</button>
                                    <button class="btn-ghost text-xs text-red-400 hover:text-red-300">Suspend</button>
                                </td>
                            </tr>
                            <tr class="border-b border-surface-800/30 hover:bg-surface-800/30 transition-colors">
                                <td class="px-6 py-4 font-mono text-xs text-surface-500">#002</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-amber-500/10 flex items-center justify-center text-xs font-bold text-amber-400">B</div>
                                        <div>
                                            <p class="font-medium text-surface-200">Beta Freelance Co.</p>
                                            <p class="text-xs text-surface-500">admin@betafreelance.io</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4"><span class="badge-neutral">Starter</span></td>
                                <td class="px-6 py-4"><span class="badge-warning">Past Due</span></td>
                                <td class="px-6 py-4 text-surface-300">3</td>
                                <td class="px-6 py-4 text-right">
                                    <button class="btn-ghost text-xs">Edit</button>
                                    <button class="btn-ghost text-xs text-red-400 hover:text-red-300">Suspend</button>
                                </td>
                            </tr>
                            <tr class="hover:bg-surface-800/30 transition-colors">
                                <td class="px-6 py-4 font-mono text-xs text-surface-500">#003</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-emerald-500/10 flex items-center justify-center text-xs font-bold text-emerald-400">G</div>
                                        <div>
                                            <p class="font-medium text-surface-200">Gamma Digital</p>
                                            <p class="text-xs text-surface-500">ops@gammadigital.dev</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4"><span class="badge-neutral">Enterprise</span></td>
                                <td class="px-6 py-4"><span class="badge-success">Active</span></td>
                                <td class="px-6 py-4 text-surface-300">14</td>
                                <td class="px-6 py-4 text-right">
                                    <button class="btn-ghost text-xs">Edit</button>
                                    <button class="btn-ghost text-xs text-red-400 hover:text-red-300">Suspend</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</template>
