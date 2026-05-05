<template>
    <button
        class="inline-flex items-center justify-center px-5 py-2.5 bg-red-500/10 text-red-400 font-medium rounded-xl
               border border-red-500/20 transition-all duration-200 hover:bg-red-500/20 hover:border-red-500/30
               active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-red-500/30 focus:ring-offset-2 focus:ring-offset-surface-900 text-sm"
    >
        <slot />
    </button>
</template>
