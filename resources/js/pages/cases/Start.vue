<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type CaseSoort = {
    id: number;
    naam: string;
    code: string;
};

type CaseItem = {
    id: number;
    uuid: string;
    created_at: string;
    case_soort_naam: string;
    case_soort_code: string;
};

const props = defineProps<{
    caseSoorten: CaseSoort[];
    cases: CaseItem[];
    teamNaam?: string | null;
}>();

const form = useForm({
    case_soort_id: props.caseSoorten[0]?.id ?? null,
});
const page = usePage();
const flashError = computed(() => (page.props.flash as { error?: string } | undefined)?.error ?? '');

const submit = () => {
    if (!form.case_soort_id) {
return;
}

    form.post('/cases');
};
</script>

<template>
    <Head title="Cases" />

    <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
        <div
            v-if="flashError"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
            {{ flashError }}
        </div>

        <div class="rounded-2xl border border-sidebar-border/70 bg-gradient-to-br from-white via-white to-amber-50 px-6 py-5 shadow-sm dark:border-sidebar-border dark:from-gray-900 dark:via-gray-900 dark:to-gray-800">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Kies of maak een case</h1>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Open een bestaande case of start een nieuwe op basis van een case-soort.
            </p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-sidebar-border/70 bg-white p-6 shadow-sm dark:border-sidebar-border dark:bg-gray-900">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Bestaande case (Team: {{ teamNaam ?? 'Onbekend' }})</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Ga verder waar je gebleven was.
                </p>

                <div v-if="cases.length" class="mt-6 space-y-3">
                    <div
                        v-for="item in cases"
                        :key="item.id"
                        class="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-700"
                    >
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ item.case_soort_naam }} ({{ item.case_soort_code }})
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                Case #{{ item.id }}
                            </span>
                        </div>
                        <Link
                            class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-900 transition hover:bg-gray-50 dark:border-gray-700 dark:text-white dark:hover:bg-gray-800"
                            :href="`/bewerken?case=${item.id}`"
                        >
                            Open
                        </Link>
                    </div>
                </div>

                <div v-else class="mt-6 rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    Er zijn nog geen cases voor jouw account.
                </div>
            </div>

            <div class="rounded-2xl border border-sidebar-border/70 bg-white p-6 shadow-sm dark:border-sidebar-border dark:bg-gray-900">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Nieuwe case</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Kies een case-soort en start direct.
                </p>

                <form class="mt-6 space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Case-soort</label>
                        <select
                            v-model="form.case_soort_id"
                            class="h-11 rounded-lg border border-gray-300 bg-white px-3 text-gray-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            :disabled="caseSoorten.length === 0"
                            required
                        >
                            <option v-for="soort in caseSoorten" :key="soort.id" :value="soort.id">
                                {{ soort.naam }} ({{ soort.code }})
                            </option>
                        </select>
                    </div>

                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-amber-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500/50 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="form.processing || caseSoorten.length === 0"
                    >
                        Nieuwe case aanmaken
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
