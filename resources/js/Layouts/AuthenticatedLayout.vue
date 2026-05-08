<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import PitchFlowLogo from '@/Components/PitchFlowLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import MessagingPanel from '@/Components/Messaging/MessagingPanel.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useMessaging } from '@/composables/useMessaging';

const showingNavigationDropdown = ref(false);

const page = usePage();
const { totalUnread, fetchUnreadCount, handleIncomingMessage, togglePanel } = useMessaging();

let echoChannel = null;

onMounted(() => {
    fetchUnreadCount();
    const userId = page.props.auth.user?.id;
    if (userId && window.Echo) {
        echoChannel = window.Echo.private(`messages.${userId}`);
        echoChannel.listen('.message.sent', handleIncomingMessage);
    }
});

onUnmounted(() => {
    const userId = page.props.auth.user?.id;
    if (userId && window.Echo) {
        window.Echo.leaveChannel(`private-messages.${userId}`);
    }
});
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
                            <PitchFlowLogo size="w-7 h-7" />
                            <span class="text-sm font-bold text-surface-100 hidden sm:block">PitchFlow</span>
                        </Link>

                        <div class="hidden sm:flex items-center gap-1">
                            <NavLink :href="route('dashboard')" :active="route().current('dashboard')">
                                Dashboard
                            </NavLink>
                        </div>
                    </div>

                    <!-- Right: Chat + User menu -->
                    <div class="hidden sm:flex items-center gap-3">
                        <!-- Chat button -->
                        <button
                            @click="togglePanel"
                            class="relative p-2 rounded-lg text-surface-400 hover:text-surface-100 hover:bg-surface-800/50 transition-all duration-200"
                        >
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>
                            </svg>
                            <span v-if="totalUnread > 0" class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] rounded-full bg-brand flex items-center justify-center px-1">
                                <span class="text-[9px] font-bold text-surface-950 leading-none">{{ totalUnread > 99 ? '99+' : totalUnread }}</span>
                            </span>
                        </button>

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

                    <!-- Mobile: chat + hamburger -->
                    <div class="sm:hidden flex items-center gap-1">
                        <button
                            @click="togglePanel"
                            class="relative p-2 rounded-lg text-surface-400 hover:text-surface-200 hover:bg-surface-800/50 transition-colors"
                        >
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>
                            </svg>
                            <span v-if="totalUnread > 0" class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] rounded-full bg-brand flex items-center justify-center px-1">
                                <span class="text-[9px] font-bold text-surface-950 leading-none">{{ totalUnread > 99 ? '99+' : totalUnread }}</span>
                            </span>
                        </button>
                    <button
                        @click="showingNavigationDropdown = !showingNavigationDropdown"
                        class="p-2 rounded-lg text-surface-400 hover:text-surface-200 hover:bg-surface-800/50 transition-colors"
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

        <!-- Messaging panel -->
        <MessagingPanel />
    </div>
</template>
