<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: { type: Boolean },
    status: { type: String },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
});
</script>

<template>
    <section>
        <header>
            <h2 class="text-base font-semibold text-surface-100">Profile Information</h2>
            <p class="mt-1 text-sm text-surface-400">Update your name and email address.</p>
        </header>

        <form @submit.prevent="form.patch(route('profile.update'))" class="mt-6 space-y-5">
            <div>
                <InputLabel for="name" value="Name" />
                <TextInput id="name" type="text" class="mt-1 block w-full" v-model="form.name" required autofocus autocomplete="name" />
                <InputError class="mt-1.5" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" required autocomplete="username" />
                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="text-sm text-surface-300">
                    Your email address is unverified.
                    <Link :href="route('verification.send')" method="post" as="button" class="text-brand hover:text-brand-light transition-colors">
                        Resend verification email.
                    </Link>
                </p>
                <div v-show="status === 'verification-link-sent'" class="mt-2 text-sm font-medium text-emerald-400">
                    A new verification link has been sent.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Save changes</PrimaryButton>
                <Transition
                    enter-active-class="transition ease-in-out duration-200"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out duration-200"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-emerald-400">Saved.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
