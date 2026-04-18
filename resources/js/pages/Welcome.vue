<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { login, register } from '@/routes';
import { start } from '@/routes/cases';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);
</script>

<template>
    <Head title="VWM">
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link
            href="https://fonts.bunny.net/css?family=space-grotesk:400,500,600,700|source-serif-4:400,600"
            rel="stylesheet"
        />
    </Head>

    <div class="relative min-h-screen overflow-hidden bg-[#0b1f1b] text-white">
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

        <div class="relative z-10 flex min-h-screen items-center">
            <div
                class="mx-auto grid w-full max-w-6xl gap-10 px-6 py-12 lg:grid-cols-[1.1fr_0.9fr]"
            >
                <section class="space-y-6">
                    <div class="inline-flex items-center gap-3 rounded-full border border-white/20 bg-white/10 px-4 py-2 text-xs uppercase tracking-[0.3em]">
                        <span class="h-2 w-2 rounded-full bg-[#7ae7c7]"></span>
                        VWM Platform
                    </div>
                    <h1
                        class="font-['Source_Serif_4'] text-4xl font-semibold leading-tight text-white sm:text-5xl"
                    >
                        Het verwerkingsmodel in beeld, veilig en direct toegankelijk.
                    </h1>
                    <p class="max-w-xl text-base text-white/80 sm:text-lg">
                        Log in om zaken te starten, raadplegen en samen te werken binnen het VWM-domein.
                        Nieuwe gebruiker? Registreer in een paar klikken.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <span class="rounded-full bg-white/10 px-4 py-2 text-xs font-medium text-white/90">
                            GraphDB gekoppeld
                        </span>
                        <span class="rounded-full bg-white/10 px-4 py-2 text-xs font-medium text-white/90">
                            Rol- en relatiebeheer
                        </span>
                        <span class="rounded-full bg-white/10 px-4 py-2 text-xs font-medium text-white/90">
                            Case-start workflow
                        </span>
                    </div>
                </section>

                <section class="flex items-center">
                    <div
                        class="w-full rounded-3xl border border-white/20 bg-white/10 p-8 shadow-2xl backdrop-blur"
                    >
                        <div class="mb-6 flex items-center justify-between">
                            <div>
                                <p class="text-xs uppercase tracking-[0.3em] text-white/60">Welkom</p>
                                <h2 class="text-2xl font-semibold">Kies je toegang</h2>
                            </div>
                            <div class="h-10 w-10 rounded-full border border-white/30 bg-white/10"></div>
                        </div>

                        <div class="space-y-3">
                            <Link
                                v-if="$page.props.auth.user"
                                :href="start()"
                                class="flex w-full items-center justify-between rounded-2xl bg-white px-5 py-4 text-sm font-semibold text-[#0b1f1b] transition hover:-translate-y-0.5"
                            >
                                Ga naar start
                                <span class="text-xs opacity-70">Ingelogd</span>
                            </Link>
                            <template v-else>
                                <Link
                                    :href="login()"
                                    class="flex w-full items-center justify-between rounded-2xl bg-white px-5 py-4 text-sm font-semibold text-[#0b1f1b] transition hover:-translate-y-0.5"
                                >
                                    Inloggen
                                    <span class="text-xs opacity-70">Bestaand account</span>
                                </Link>
                                <Link
                                    v-if="canRegister"
                                    :href="register()"
                                    class="flex w-full items-center justify-between rounded-2xl border border-white/30 bg-white/5 px-5 py-4 text-sm font-semibold text-white transition hover:-translate-y-0.5"
                                >
                                    Registreren
                                    <span class="text-xs opacity-70">Nieuwe gebruiker</span>
                                </Link>
                            </template>
                        </div>

                        <div class="mt-6 rounded-2xl border border-white/15 bg-white/5 p-4 text-xs text-white/70">
                            Door in te loggen ga je akkoord met het gebruik van VWM voor beheer en raadpleging.
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
