<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    terms: false,
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Registrace" />

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="name" value="Jméno" />

                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div class="mt-4">
                <InputLabel for="email" value="E-mail" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Heslo" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4">
                <InputLabel
                    for="password_confirmation"
                    value="Heslo pro kontrolu"
                />

                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />

                <InputError
                    class="mt-2"
                    :message="form.errors.password_confirmation"
                />
            </div>

            <!--
                The consent is validated on the server as well (RegisterRequest
                rule `accepted`): the timestamp stored on the user is meant to
                be evidence, and evidence a client can skip is not evidence.
            -->
            <div class="mt-6">
                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input
                        id="terms"
                        type="checkbox"
                        v-model="form.terms"
                        required
                        class="mt-1 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                    />
                    <span>
                        Souhlasím s
                        <a
                            href="/pravni/obchodni-podminky"
                            target="_blank"
                            rel="noopener"
                            class="underline hover:text-gray-900"
                            >obchodními podmínkami</a
                        >
                        a beru na vědomí
                        <a
                            href="/pravni/ochrana-osobnich-udaju"
                            target="_blank"
                            rel="noopener"
                            class="underline hover:text-gray-900"
                            >zásady zpracování osobních údajů</a
                        >.
                    </span>
                </label>

                <InputError class="mt-2" :message="form.errors.terms" />
            </div>

            <div class="mt-4 flex items-center justify-end">
                <Link
                    :href="route('login')"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Už mám účet
                </Link>

                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Zaregistrovat se
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
