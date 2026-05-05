<script setup>
import { ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);
</script>

<template>
    <div class="min-h-screen bg-surface-950">
        <!-- Top navigation -->
        <nav class="sticky top-0 z-50 bg-surface-950/80 backdrop-blur-xl border-b border-surface-800/50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <!-- Left: Logo + nav links -->
                    <div class="flex items-center gap-8">
                        <Link :href="route('dashboard')" class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded-md bg-brand flex items-center justify-center">
                                <svg class="w-3.5 h-3.5 text-surface-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                            </div>
                            <span class="text-sm font-bold text-surface-100 hidden sm:block">ConnectFlow</span>
                        </Link>

                        <div class="hidden sm:flex items-center gap-1">
                            <NavLink :href="route('dashboard')" :active="route().current('dashboard')">
                                Dashboard
                            </NavLink>
                        </div>
                    </div>

                    <!-- Right: User menu -->
                    <div class="hidden sm:flex items-center gap-3">
                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="flex items-center gap-2.5 px-3 py-1.5 rounded-lg text-sm text-surface-300 hover:text-surface-100 hover:bg-surface-800/50 transition-all duration-200"
                                >
                                    <div class="w-7 h-7 rounded-full bg-surface-700 flex items-center justify-center text-xs font-semibold text-surface-300">
                                        {{ $page.props.auth.user.name.charAt(0).toUpperCase() }}
                                    </div>
                                    <span class="font-medium">{{ $page.props.auth.user.name }}</span>
                                    <svg class="w-4 h-4 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                            </template>

                            <template #content>
                                <DropdownLink :href="route('profile.edit')">
                                    Profile
                                </DropdownLink>
                                <DropdownLink :href="route('logout')" method="post" as="button">
                                    Sign out
                                </DropdownLink>
                            </template>
                        </Dropdown>
                    </div>

                    <!-- Mobile hamburger -->
                    <button
                        @click="showingNavigationDropdown = !showingNavigationDropdown"
                        class="sm:hidden p-2 rounded-lg text-surface-400 hover:text-surface-200 hover:bg-surface-800/50 transition-colors"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path
                                v-if="!showingNavigationDropdown"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M4 6h16M4 12h16M4 18h16"
                            />
                            <path
                                v-else
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Mobile nav -->
            <div v-show="showingNavigationDropdown" class="sm:hidden border-t border-surface-800/50 animate-slide-down">
                <div class="px-4 py-3 space-y-1">
                    <ResponsiveNavLink :href="route('dashboard')" :active="route().current('dashboard')">
                        Dashboard
                    </ResponsiveNavLink>
                </div>
                <div class="px-4 py-3 border-t border-surface-800/50">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-8 h-8 rounded-full bg-surface-700 flex items-center justify-center text-xs font-semibold text-surface-300">
                            {{ $page.props.auth.user.name.charAt(0).toUpperCase() }}
                        </div>
                        <div>
                            <p class="text-sm font-medium text-surface-200">{{ $page.props.auth.user.name }}</p>
                            <p class="text-xs text-surface-500">{{ $page.props.auth.user.email }}</p>
                        </div>
                    </div>
                    <div class="space-y-1">
                        <ResponsiveNavLink :href="route('profile.edit')">Profile</ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('logout')" method="post" as="button">Sign out</ResponsiveNavLink>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Page heading -->
        <header v-if="$slots.header" class="border-b border-surface-800/30">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <slot name="header" />
            </div>
        </header>

        <!-- Page content -->
        <main>
            <slot />
        </main>
    </div>
</template>
