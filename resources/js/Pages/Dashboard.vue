<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-surface-100">Dashboard</h1>
            <p class="text-sm text-surface-400 mt-0.5">Welcome back. Here's your overview.</p>
        </template>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Stats -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Bids Today</span>
                    <span class="text-2xl font-semibold text-surface-100 mt-1">12</span>
                </div>
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Win Rate</span>
                    <span class="text-2xl font-semibold text-surface-100 mt-1">23%</span>
                </div>
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Active Contracts</span>
                    <span class="text-2xl font-semibold text-surface-100 mt-1">4</span>
                </div>
                <div class="stat-card">
                    <span class="text-xs font-medium text-surface-400 uppercase tracking-wider">Connects Left</span>
                    <span class="text-2xl font-semibold text-surface-100 mt-1">102</span>
                </div>
            </div>

            <!-- Content area -->
            <div class="card p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-8 h-8 rounded-lg bg-brand/10 flex items-center justify-center">
                        <svg class="w-4 h-4 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="font-medium text-surface-100">You're all set</h2>
                        <p class="text-sm text-surface-400">Your account is active and ready to use.</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
