<script setup>
import { watch } from 'vue';
import { useMessaging } from '@/composables/useMessaging';
import ConversationList from './ConversationList.vue';
import ChatWindow from './ChatWindow.vue';

const {
    isPanelOpen, activeConversation, closePanel,
    fetchConversations, setActiveConversation,
} = useMessaging();

watch(isPanelOpen, (open) => {
    if (open) fetchConversations();
});

function handleBack() {
    setActiveConversation(null);
}
</script>

<template>
    <Teleport to="body">
        <Transition name="panel">
            <div v-if="isPanelOpen" class="fixed inset-0 z-50 flex justify-end">
                <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="closePanel"></div>

                <div class="relative w-full sm:w-[560px] lg:w-[780px] h-full bg-surface-900 border-l border-surface-800/50 flex shadow-2xl">
                    <!-- Conversation list -->
                    <div
                        class="w-full sm:w-[260px] lg:w-[300px] border-r border-surface-800/50 flex flex-col flex-shrink-0"
                        :class="{ 'hidden sm:flex': activeConversation }"
                    >
                        <div class="h-14 px-4 flex items-center justify-between border-b border-surface-800/50 flex-shrink-0">
                            <h2 class="text-sm font-bold text-surface-100">Messages</h2>
                            <button @click="closePanel" class="text-surface-400 hover:text-surface-100 transition-colors sm:hidden">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <ConversationList />
                    </div>

                    <!-- Chat window -->
                    <div
                        class="flex-1 flex flex-col min-w-0"
                        :class="{ 'hidden sm:flex': !activeConversation }"
                    >
                        <ChatWindow @back="handleBack" />
                    </div>

                    <!-- Close button (desktop) -->
                    <button
                        @click="closePanel"
                        class="hidden sm:flex absolute top-3 right-3 w-7 h-7 rounded-lg bg-surface-800/80 hover:bg-surface-700 items-center justify-center text-surface-400 hover:text-surface-100 transition-colors z-10"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.panel-enter-active, .panel-leave-active {
    transition: all 0.25s ease;
}
.panel-enter-active > div:last-child, .panel-leave-active > div:last-child {
    transition: transform 0.25s ease;
}
.panel-enter-from, .panel-leave-to {
    opacity: 0;
}
.panel-enter-from > div:last-child, .panel-leave-to > div:last-child {
    transform: translateX(100%);
}
</style>
