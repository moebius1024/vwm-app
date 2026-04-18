<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { home, login } from '@/routes';
import { store } from '@/routes/register';

defineOptions({
    layout: null,
});
</script>

<template>
    <Head title="Registreren">
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link
            href="https://fonts.bunny.net/css?family=space-grotesk:400,500,600,700|source-serif-4:400,600"
            rel="stylesheet"
        />
    </Head>

    <div class="relative min-h-svh overflow-hidden bg-[#0b1f1b] text-white">
        <div
            class="absolute inset-0 bg-[url('/images/vwm-verwerkingsmodel-uitgebreid.jpg')] bg-cover bg-center opacity-90"
            aria-hidden="true"
        ></div>
        <div
            class="absolute inset-0 bg-gradient-to-tr from-[#071514]/90 via-[#0b1f1b]/70 to-[#0b1f1b]/30"
            aria-hidden="true"
        ></div>
        <div
            class="absolute -left-24 top-12 h-72 w-72 rounded-full bg-[#b6f5d4]/20 blur-3xl"
            aria-hidden="true"
        ></div>
        <div
            class="absolute bottom-10 right-10 h-64 w-64 rounded-full bg-[#f7d38a]/20 blur-3xl"
            aria-hidden="true"
        ></div>

        <div class="relative z-10 flex min-h-svh items-center">
            <div
                class="mx-auto grid w-full max-w-6xl gap-10 px-6 py-12 lg:grid-cols-[1.1fr_0.9fr]"
            >
                <section class="hidden space-y-6 lg:block">
                    <Link
                        :href="home()"
                        class="inline-flex items-center gap-3 rounded-full border border-white/20 bg-white/10 px-4 py-2 text-xs uppercase tracking-[0.3em]"
                    >
                        <span class="h-2 w-2 rounded-full bg-[#7ae7c7]"></span>
                        VWM Platform
                    </Link>
                    <h1
                        class="font-['Source_Serif_4'] text-4xl font-semibold leading-tight text-white"
                    >
                        Start met een nieuw VWM-account.
                    </h1>
                    <p class="max-w-xl text-base text-white/80">
                        Maak een account aan om toegang te krijgen tot cases,
                        rollen en raadpleging in het verwerkingsmodel.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <span
                            class="rounded-full bg-white/10 px-4 py-2 text-xs font-medium text-white/90"
                        >
                            Snelle onboarding
                        </span>
                        <span
                            class="rounded-full bg-white/10 px-4 py-2 text-xs font-medium text-white/90"
                        >
                            Rollen en rechten
                        </span>
                        <span
                            class="rounded-full bg-white/10 px-4 py-2 text-xs font-medium text-white/90"
                        >
                            Betrouwbare data
                        </span>
                    </div>
                </section>

                <section class="flex items-center">
                    <div
                        class="w-full rounded-3xl border border-white/20 bg-white p-8 text-[#0b1f1b] shadow-2xl"
                    >
                        <div class="mb-6 space-y-2">
                            <p
                                class="text-xs uppercase tracking-[0.3em] text-[#0b1f1b]/60"
                            >
                                VWM toegang
                            </p>
                            <h2 class="text-2xl font-semibold">Registreren</h2>
                            <p class="text-sm text-[#0b1f1b]/70">
                                Vul je gegevens in om een account aan te maken.
                            </p>
                        </div>

                        <Form
                            v-bind="store.form()"
                            :reset-on-success="['password', 'password_confirmation']"
                            v-slot="{ errors, processing }"
                            class="flex flex-col gap-6"
                        >
                            <div class="grid gap-6">
                                <div class="grid gap-2">
                                    <Label for="name">Name</Label>
                                    <Input
                                        id="name"
                                        type="text"
                                        required
                                        autofocus
                                        :tabindex="1"
                                        autocomplete="name"
                                        name="name"
                                        placeholder="Full name"
                                    />
                                    <InputError :message="errors.name" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="email">Email address</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        required
                                        :tabindex="2"
                                        autocomplete="email"
                                        name="email"
                                        placeholder="email@example.com"
                                    />
                                    <InputError :message="errors.email" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="password">Password</Label>
                                    <PasswordInput
                                        id="password"
                                        required
                                        :tabindex="3"
                                        autocomplete="new-password"
                                        name="password"
                                        placeholder="Password"
                                    />
                                    <InputError :message="errors.password" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="password_confirmation">
                                        Confirm password
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        required
                                        :tabindex="4"
                                        autocomplete="new-password"
                                        name="password_confirmation"
                                        placeholder="Confirm password"
                                    />
                                    <InputError
                                        :message="errors.password_confirmation"
                                    />
                                </div>

                                <Button
                                    type="submit"
                                    class="mt-4 w-full"
                                    :disabled="processing"
                                    :tabindex="5"
                                >
                                    <Spinner v-if="processing" />
                                    Create account
                                </Button>
                            </div>

                            <div class="text-center text-sm text-[#0b1f1b]/60">
                                Already have an account?
                                <TextLink :href="login()" :tabindex="6">
                                    Log in
                                </TextLink>
                            </div>
                        </Form>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
