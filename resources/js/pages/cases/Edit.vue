<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import CaseConsultPanel from '@/components/CaseConsultPanel.vue';
import DynamicObjectForm from '@/components/DynamicObjectForm.vue';
import { start } from '@/routes/cases';

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
  dossiers?: {
    id: number;
    naam: string;
    rdf_uri: string;
    parent_id: number | null;
    created_at: string;
    goics: {
      id: number;
      rdf_uri: string;
      dossier_id: number;
      created_at: string;
      toestanden: {
        mutatie_id: number;
        sjabloon_uri: string;
        tb_id: number | null;
        tb_rdf_uri: string | null;
        tb_class: string | null;
        tb_data: Record<string, unknown> | string | null;
        created_at: string;
      }[];
    }[];
  }[];
};

defineOptions({
  layout: {
    breadcrumbs: [
      {
        title: 'Start',
        href: start(),
      },
      {
        title: 'Bewerken',
        href: '/bewerken',
      },
    ],
  },
});

const props = defineProps<Props>();
const selectedTransactieId = ref<number | null>(props.transactieSoorten?.[0]?.id ?? null);

const refreshDossiers = () => {
  router.reload({ only: ['dossiers'] });
};
</script>

<template>
  <Head title="Bewerken" />

  <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
    <div class="rounded-2xl border border-sidebar-border/70 bg-gradient-to-br from-white via-white to-amber-50 px-6 py-5 shadow-sm dark:border-sidebar-border dark:from-gray-900 dark:via-gray-900 dark:to-gray-800">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Case bewerken</h1>
        <p class="text-sm text-gray-600 dark:text-gray-300">
          Registreer nieuwe gegevens en raadpleeg bestaande inhoud in dezelfde case.
        </p>
      </div>
    </div>

    <div v-if="caseId" class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
      <div class="rounded-2xl border border-sidebar-border/70 bg-white p-6 shadow-sm dark:border-sidebar-border dark:bg-gray-900">
        <div class="flex items-center justify-between gap-3">
          <span class="text-sm font-medium text-gray-600 dark:text-gray-300">Registreren</span>
          <Link class="text-sm font-semibold text-amber-700 underline decoration-amber-300 underline-offset-4 hover:decoration-current dark:text-amber-200" href="/start">
            Terug naar start
          </Link>
        </div>

        <div class="mt-4">
          <DynamicObjectForm
            v-if="selectedTransactieId"
            :transactie-soort-id="selectedTransactieId"
            :case-id="caseId"
            :dossiers="dossiers"
            @saved="refreshDossiers"
          />
        </div>
      </div>

      <div class="rounded-2xl border border-sidebar-border/70 bg-white p-6 shadow-sm dark:border-sidebar-border dark:bg-gray-900">
        <div class="mb-4">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Raadplegen</h2>
          <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Bekijk de bestaande inhoud van deze case.</p>
        </div>
        <CaseConsultPanel :dossiers="dossiers" :transactie-soort-id="selectedTransactieId" />
      </div>
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
</template>
