<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DynamicObjectForm from '@/components/DynamicObjectForm.vue'; // Importeer je formulier
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
type Props = {
    caseId?: number | null;
    activeCase?: {
        id: number;
        uuid: string;
        case_soort_naam: string;
        case_soort_code: string;
    } | null;
    transactieSoorten?: {
        id: number;
        naam: string;
    }[];
};

const props = defineProps<Props>();
const selectedTransactieId = ref<number | null>(props.transactieSoorten?.[0]?.id ?? null);
const hasTransactie = computed(() => (props.transactieSoorten?.length ?? 0) > 0);
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
        <div class="rounded-2xl border border-sidebar-border/70 bg-gradient-to-br from-white via-white to-amber-50 px-6 py-5 shadow-sm dark:border-sidebar-border dark:from-gray-900 dark:via-gray-900 dark:to-gray-800">
            <div class="flex flex-col gap-1">
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Persoon registreren</h1>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Leg basisgegevens vast en synchroniseer direct met GraphDB.
                </p>
            </div>
        </div>

        <div class="relative flex-1 rounded-2xl border border-sidebar-border/70 bg-white p-8 shadow-sm dark:border-sidebar-border dark:bg-gray-900">
            <div class="max-w-3xl mx-auto">
                <div v-if="caseId">
                    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200/70 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-300/20 dark:bg-amber-900/20 dark:text-amber-100">
                        <span v-if="activeCase">
                            Actieve case: {{ activeCase.case_soort_naam }} ({{ activeCase.case_soort_code }}) · #{{ activeCase.id }}
                        </span>
                        <Link class="font-medium underline decoration-amber-300 underline-offset-4 hover:decoration-current" href="/start">
                            Andere case kiezen
                        </Link>
                    </div>
                    <div v-if="hasTransactie" class="mb-6">
                        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Transactie</label>
                        <select
                            v-model="selectedTransactieId"
                            class="h-11 w-full max-w-sm rounded-lg border border-gray-300 bg-white px-3 text-gray-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        >
                            <option v-for="item in transactieSoorten" :key="item.id" :value="item.id">
                                {{ item.naam }}
                            </option>
                        </select>
                    </div>
                    <DynamicObjectForm v-if="selectedTransactieId" :transactie-soort-id="selectedTransactieId" :case-id="caseId" />
                </div>

                <div v-else class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-gray-600 dark:border-gray-700 dark:text-gray-300">
                    Er is nog geen case geselecteerd. Ga naar de startpagina om een case te openen of aan te maken.
                    <div class="mt-4">
                        <Link class="inline-flex items-center rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700" href="/start">
                            Naar case-keuze
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
