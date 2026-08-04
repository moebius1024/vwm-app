<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { consult, edit, store } from '@/routes/cases';

type CaseSoort = {
    id: number;
    naam: string;
    code: string;
};

type CaseItem = {
    id: number;
    uuid: string;
    case_soort_id: number;
    created_at: string;
    case_soort_naam: string;
    case_soort_code: string;
};

type TeamCaseGroup = {
    id: number;
    naam: string;
    cases: CaseItem[];
};

const props = defineProps<{
    caseSoorten: CaseSoort[];
    cases: CaseItem[];
    teamNaam?: string | null;
    mode?: 'start' | 'consult';
    selectedCaseSoortId?: number | null;
    otherTeamCases?: TeamCaseGroup[];
}>();

const form = useForm({
    case_soort_id: props.caseSoorten[0]?.id ?? null,
});
const page = usePage();
const flashError = computed(
    () => (page.props.flash as { error?: string } | undefined)?.error ?? '',
);
const isConsultMode = computed(() => props.mode === 'consult');
const selectedOwnTeamCaseSoortId = ref<number | null>(props.selectedCaseSoortId ?? null);
const selectedOtherTeamsCaseSoortId = ref<number | null>(null);
const ownTeamCaseSoortFilterStorageKey = computed(() => (
    `case-selection-filter:${isConsultMode.value ? 'consult' : 'start'}:own-team`
));
const otherTeamsCaseSoortFilterStorageKey = computed(() => (
    'case-selection-filter:consult:other-teams'
));
const filteredCases = computed(() =>
    props.cases.filter(
        (item) =>
            selectedOwnTeamCaseSoortId.value === null ||
            item.case_soort_id === selectedOwnTeamCaseSoortId.value,
    ),
);
const filteredOtherTeamCases = computed(() =>
    (props.otherTeamCases ?? [])
        .map((team) => ({
            ...team,
            cases: team.cases.filter(
                (item) =>
                    selectedOtherTeamsCaseSoortId.value === null ||
                    item.case_soort_id === selectedOtherTeamsCaseSoortId.value,
            ),
        }))
        .filter((team) => team.cases.length > 0),
);

onMounted(() => {
    if (selectedOwnTeamCaseSoortId.value !== null) {
        window.sessionStorage.setItem(
            ownTeamCaseSoortFilterStorageKey.value,
            String(selectedOwnTeamCaseSoortId.value),
        );
    } else {
        const storedCaseSoortId = Number(
            window.sessionStorage.getItem(ownTeamCaseSoortFilterStorageKey.value),
        );

        if (props.caseSoorten.some((caseSoort) => caseSoort.id === storedCaseSoortId)) {
            selectedOwnTeamCaseSoortId.value = storedCaseSoortId;
        }
    }

    const storedOtherTeamsCaseSoortId = Number(
        window.sessionStorage.getItem(otherTeamsCaseSoortFilterStorageKey.value),
    );
    if (props.caseSoorten.some((caseSoort) => caseSoort.id === storedOtherTeamsCaseSoortId)) {
        selectedOtherTeamsCaseSoortId.value = storedOtherTeamsCaseSoortId;
    }
});

watch(selectedOwnTeamCaseSoortId, (caseSoortId) => {
    if (caseSoortId === null) {
        window.sessionStorage.removeItem(ownTeamCaseSoortFilterStorageKey.value);

        return;
    }

    window.sessionStorage.setItem(
        ownTeamCaseSoortFilterStorageKey.value,
        String(caseSoortId),
    );
});

watch(selectedOtherTeamsCaseSoortId, (caseSoortId) => {
    if (caseSoortId === null) {
        window.sessionStorage.removeItem(otherTeamsCaseSoortFilterStorageKey.value);

        return;
    }

    window.sessionStorage.setItem(
        otherTeamsCaseSoortFilterStorageKey.value,
        String(caseSoortId),
    );
});

const formatCaseRegistrationDate = (value: string) => {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('nl-NL', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
};

const submit = () => {
    if (!form.case_soort_id) {
        return;
    }

    form.post(store.url());
};
</script>

<template>
    <Head :title="isConsultMode ? 'Case raadplegen' : 'Cases'" />

    <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
        <div
            v-if="flashError"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
            {{ flashError }}
        </div>

        <div
            class="rounded-2xl border border-sidebar-border/70 bg-gradient-to-br from-white via-white to-amber-50 px-6 py-5 shadow-sm dark:border-sidebar-border dark:from-gray-900 dark:via-gray-900 dark:to-gray-800"
        >
            <h1
                class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white"
            >
                {{
                    isConsultMode
                        ? 'Kies een Case om te Raadplegen'
                        : 'Kies of maak een case'
                }}
            </h1>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{
                    isConsultMode
                        ? 'Kies een bestaande case om de dossiers en inhoud te bekijken.'
                        : 'Open een bestaande case of start een nieuwe op basis van een case-soort.'
                }}
            </p>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div
                class="min-w-0 rounded-2xl border border-sidebar-border/70 bg-white p-6 shadow-sm dark:border-sidebar-border dark:bg-gray-900"
            >
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <h2
                        class="text-lg font-semibold text-gray-900 dark:text-white"
                    >
                        Cases van Team {{ teamNaam ?? 'Onbekend' }}
                    </h2>
                    <label
                        class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300"
                    >
                        Case-soort
                        <select
                            v-model="selectedOwnTeamCaseSoortId"
                            class="h-10 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 shadow-sm transition outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        >
                            <option :value="null">Alle soorten</option>
                            <option
                                v-for="soort in caseSoorten"
                                :key="soort.id"
                                :value="soort.id"
                            >
                                {{ soort.naam }} ({{ soort.code }})
                            </option>
                        </select>
                    </label>
                </div>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{
                        isConsultMode
                            ? 'Kies een case om te raadplegen.'
                            : 'Ga verder waar je gebleven was.'
                    }}
                </p>

                <div v-if="filteredCases.length" class="mt-6 space-y-3">
                    <div
                        v-for="item in filteredCases"
                        :key="item.id"
                        class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700"
                    >
                        <div class="min-w-0">
                            <span
                                class="block truncate text-sm font-medium text-gray-900 dark:text-white"
                            >
                                {{ item.case_soort_naam }} #{{ item.id }} ·
                                {{ formatCaseRegistrationDate(item.created_at) }}
                            </span>
                        </div>
                        <Link
                            class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-900 transition hover:bg-gray-50 dark:border-gray-700 dark:text-white dark:hover:bg-gray-800"
                            :href="
                                isConsultMode
                                    ? consult({ query: { case: item.id, case_soort: selectedOwnTeamCaseSoortId } })
                                    : edit({ query: { case: item.id, case_soort: selectedOwnTeamCaseSoortId } })
                            "
                        >
                            Open
                        </Link>
                    </div>
                </div>

                <div
                    v-else
                    class="mt-6 rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400"
                >
                    {{
                        selectedOwnTeamCaseSoortId === null
                            ? 'Er zijn nog geen cases voor jouw account.'
                            : 'Er zijn geen cases van deze case-soort.'
                    }}
                </div>
            </div>

            <div
                v-if="isConsultMode"
                class="min-w-0 rounded-2xl border border-sidebar-border/70 bg-white p-6 shadow-sm dark:border-sidebar-border dark:bg-gray-900"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        Cases van andere teams
                    </h2>
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                        Case-soort
                        <select
                            v-model="selectedOtherTeamsCaseSoortId"
                            class="h-10 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 shadow-sm transition outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        >
                            <option :value="null">Alle soorten</option>
                            <option
                                v-for="soort in caseSoorten"
                                :key="soort.id"
                                :value="soort.id"
                            >
                                {{ soort.naam }} ({{ soort.code }})
                            </option>
                        </select>
                    </label>
                </div>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Cases waarvoor je bevoegd bent.
                </p>

                <div v-if="filteredOtherTeamCases.length" class="mt-6 space-y-5">
                    <section
                        v-for="team in filteredOtherTeamCases"
                        :key="team.id"
                        class="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
                    >
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                            Team: {{ team.naam }}
                        </h3>
                        <div class="mt-3 space-y-3">
                            <div
                                v-for="item in team.cases"
                                :key="item.id"
                                class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700"
                            >
                                <span class="block min-w-0 truncate text-sm font-medium text-gray-900 dark:text-white">
                                    {{ item.case_soort_naam }} #{{ item.id }} ·
                                    {{ formatCaseRegistrationDate(item.created_at) }}
                                </span>
                                <Link
                                    class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-900 transition hover:bg-gray-50 dark:border-gray-700 dark:text-white dark:hover:bg-gray-800"
                                    :href="consult({ query: { case: item.id } })"
                                >
                                    Open
                                </Link>
                            </div>
                        </div>
                    </section>
                </div>

                <div
                    v-else
                    class="mt-6 rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400"
                >
                    {{
                        selectedOtherTeamsCaseSoortId === null
                            ? 'Er zijn geen cases van andere bevoegde teams.'
                            : 'Er zijn geen cases van deze case-soort bij andere bevoegde teams.'
                    }}
                </div>
            </div>

            <div
                v-if="!isConsultMode"
                class="rounded-2xl border border-sidebar-border/70 bg-white p-6 shadow-sm dark:border-sidebar-border dark:bg-gray-900"
            >
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                    Nieuwe case
                </h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Kies een case-soort en start direct.
                </p>

                <form class="mt-6 space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <label
                            class="text-sm font-medium text-gray-700 dark:text-gray-300"
                            >Case-soort</label
                        >
                        <select
                            v-model="form.case_soort_id"
                            class="h-11 rounded-lg border border-gray-300 bg-white px-3 text-gray-900 shadow-sm transition outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            :disabled="caseSoorten.length === 0"
                            required
                        >
                            <option
                                v-for="soort in caseSoorten"
                                :key="soort.id"
                                :value="soort.id"
                            >
                                {{ soort.naam }} ({{ soort.code }})
                            </option>
                        </select>
                    </div>

                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-amber-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-700 focus:ring-2 focus:ring-amber-500/50 focus:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="form.processing || caseSoorten.length === 0"
                    >
                        Nieuwe case aanmaken
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
