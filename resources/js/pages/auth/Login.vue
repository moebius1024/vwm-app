<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { home, register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: null,
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();
</script>

<template>
    <Head title="Inloggen">
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
                        Welkom terug in het verwerkingsmodel.
                    </h1>
                    <p class="max-w-xl text-base text-white/80">
                        Log in om cases te beheren, relaties te raadplegen en
                        samen te werken binnen het VWM-domein.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <span
                            class="rounded-full bg-white/10 px-4 py-2 text-xs font-medium text-white/90"
                        >
                            Beveiligde toegang
                        </span>
                        <span
                            class="rounded-full bg-white/10 px-4 py-2 text-xs font-medium text-white/90"
                        >
                            GraphDB gekoppeld
                        </span>
                        <span
                            class="rounded-full bg-white/10 px-4 py-2 text-xs font-medium text-white/90"
                        >
                            Werkstromen actief
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
                            <h2 class="text-2xl font-semibold">Inloggen</h2>
                            <p class="text-sm text-[#0b1f1b]/70">
                                Gebruik je e-mail en wachtwoord om verder te
                                gaan.
                            </p>
                        </div>

                        <div
                            v-if="status"
                            class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700"
                        >
                            {{ status }}
                        </div>

                        <Form
                            v-bind="store.form()"
                            :reset-on-success="['password']"
                            v-slot="{ errors, processing }"
                            class="flex flex-col gap-6"
                        >
                            <div class="grid gap-6">
                                <div class="grid gap-2">
                                    <Label for="email">Email address</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        autofocus
                                        :tabindex="1"
                                        autocomplete="email"
                                        placeholder="email@example.com"
                                    />
                                    <InputError :message="errors.email" />
                                </div>

                                <div class="grid gap-2">
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <Label for="password">Password</Label>
                                        <TextLink
                                            v-if="canResetPassword"
                                            :href="request()"
                                            class="text-sm"
                                            :tabindex="5"
                                        >
                                            Forgot password?
                                        </TextLink>
                                    </div>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        :tabindex="2"
                                        autocomplete="current-password"
                                        placeholder="Password"
                                    />
                                    <InputError :message="errors.password" />
                                </div>

                                <div class="flex items-center justify-between">
                                    <Label
                                        for="remember"
                                        class="flex items-center space-x-3"
                                    >
                                        <Checkbox
                                            id="remember"
                                            name="remember"
                                            :tabindex="3"
                                        />
                                        <span>Remember me</span>
                                    </Label>
                                </div>

                                <Button
                                    type="submit"
                                    class="mt-4 w-full"
                                    :tabindex="4"
                                    :disabled="processing"
                                    data-test="login-button"
                                >
                                    <Spinner v-if="processing" />
                                    Log in
                                </Button>
                            </div>

                            <div
                                class="text-center text-sm text-[#0b1f1b]/60"
                                v-if="canRegister"
                            >
                                Don't have an account?
                                <TextLink :href="register()" :tabindex="5">
                                    Sign up
                                </TextLink>
                            </div>
                        </Form>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
