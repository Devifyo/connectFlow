<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ status: { type: String } });

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <GuestLayout>
        <Head title="Verify Email" />

        <h2 class="text-base font-semibold text-surface-100 mb-2">Check your email</h2>
        <p class="text-sm text-surface-400 mb-6">
            We sent a verification link to your email. Click it to activate your account.
        </p>

        <div v-if="verificationLinkSent" class="mb-4 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-sm text-emerald-400">
            A new verification link has been sent.
        </div>

        <form @submit.prevent="submit">
            <div class="flex items-center justify-between">
                <PrimaryButton :class="{ 'opacity-50': form.processing }" :disabled="form.processing">
                    Resend email
                </PrimaryButton>

                <Link :href="route('logout')" method="post" as="button" class="text-sm text-surface-400 hover:text-surface-200 transition-colors">
                    Sign out
                </Link>
            </div>
        </form>
    </GuestLayout>
</template>
