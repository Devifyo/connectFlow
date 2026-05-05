<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ password: '' });

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Confirm Password" />

        <h2 class="text-base font-semibold text-surface-100 mb-2">Confirm your password</h2>
        <p class="text-sm text-surface-400 mb-6">
            This is a secure area. Please confirm your password before continuing.
        </p>

        <form @submit.prevent="submit" class="space-y-5">
            <div>
                <InputLabel for="password" value="Password" />
                <TextInput id="password" type="password" class="mt-1 block w-full" v-model="form.password" required autocomplete="current-password" autofocus />
                <InputError class="mt-1.5" :message="form.errors.password" />
            </div>

            <PrimaryButton class="w-full justify-center" :class="{ 'opacity-50': form.processing }" :disabled="form.processing">
                Confirm
            </PrimaryButton>
        </form>
    </GuestLayout>
</template>
