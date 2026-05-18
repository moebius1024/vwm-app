<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
      go_uri?: string | null;
      linked_goic_count?: number;
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

type MutationTarget = {
  goic_id: number;
  mutatie_id: number;
  sjabloon_uri: string;
  tb_rdf_uri: string | null;
  tb_class: string | null;
  tb_data: Record<string, unknown> | string | null;
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
const isDossierCreationEnabled = false;
const selectedTransactieId = ref<number | null>(props.transactieSoorten?.[0]?.id ?? null);
const mutationTarget = ref<MutationTarget | null>(null);
const newDossierName = ref('');
const newDossierParentId = ref<string>('');
const isCreatingDossier = ref(false);
const caseTitle = computed(() => {
  if (props.activeCase?.id && props.activeCase.case_soort_naam) {
    return `Case ${props.activeCase.case_soort_naam} #${props.activeCase.id} bewerken`;
  }

  if (props.caseId) {
    return `Case #${props.caseId} bewerken`;
  }

  return 'Case bewerken';
});

const refreshDossiers = () => {
  router.reload({ only: ['dossiers'] });
  mutationTarget.value = null;
};

const onSelectMutate = (target: MutationTarget) => {
  mutationTarget.value = target;
};

const clearMutationTarget = () => {
  mutationTarget.value = null;
};

const createDossier = () => {
  if (!isDossierCreationEnabled || !props.caseId || isCreatingDossier.value) {
    return;
  }

  isCreatingDossier.value = true;
  router.post(`/cases/${props.caseId}/dossiers`, {
    naam: newDossierName.value.trim() || null,
    parent_id: newDossierParentId.value ? Number(newDossierParentId.value) : null,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      newDossierName.value = '';
      newDossierParentId.value = '';
      refreshDossiers();
    },
    onFinish: () => {
      isCreatingDossier.value = false;
    },
  });
};
</script>

<template>
  <Head title="Bewerken" />

  <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
    <div class="rounded-2xl border border-sidebar-border/70 bg-gradient-to-br from-white via-white to-amber-50 px-6 py-5 shadow-sm dark:border-sidebar-border dark:from-gray-900 dark:via-gray-900 dark:to-gray-800">
      <div class="flex items-start justify-between gap-4">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ caseTitle }}</h1>
          <p class="text-sm text-gray-600 dark:text-gray-300">
            Registreer nieuwe gegevens en raadpleeg bestaande inhoud in dezelfde case.
          </p>
        </div>
        <Link class="inline-flex items-center rounded-lg border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-800 shadow-sm transition hover:bg-emerald-50 dark:border-emerald-300/30 dark:bg-gray-900 dark:text-emerald-100 dark:hover:bg-emerald-900/30" href="/start">
          Terug naar start
        </Link>
      </div>
    </div>

    <div v-if="caseId" class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
      <div class="rounded-2xl border border-sidebar-border/70 bg-white p-6 shadow-sm dark:border-sidebar-border dark:bg-gray-900">
        <div class="mb-4">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Registreren</h2>
        </div>

        <div>
          <DynamicObjectForm
            v-if="selectedTransactieId"
            :transactie-soort-id="selectedTransactieId"
            :case-id="caseId"
            :dossiers="dossiers"
            :mutation-target="mutationTarget"
            @saved="refreshDossiers"
            @cancel-mutate="clearMutationTarget"
          />
        </div>
      </div>

      <div class="rounded-2xl border border-sidebar-border/70 bg-white p-6 shadow-sm dark:border-sidebar-border dark:bg-gray-900">
        <div class="mb-4 rounded-xl border border-amber-200/70 bg-amber-50/60 p-4 dark:border-amber-400/30 dark:bg-amber-900/20">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Nieuw Dossier</h2>
          <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
            Voeg een extra dossier toe binnen deze case.
          </p>
          <form class="mt-3 flex flex-col gap-2 sm:flex-row" @submit.prevent="createDossier">
            <input
              v-model="newDossierName"
              type="text"
              :disabled="!isDossierCreationEnabled"
              class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-200 dark:border-gray-700 dark:bg-gray-950 dark:text-white dark:focus:border-amber-400 dark:focus:ring-amber-500/30"
              placeholder="Dossiernaam (optioneel)"
            >
            <select
              v-model="newDossierParentId"
              :disabled="!isDossierCreationEnabled"
              class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-200 dark:border-gray-700 dark:bg-gray-950 dark:text-white dark:focus:border-amber-400 dark:focus:ring-amber-500/30 sm:max-w-[220px]"
            >
              <option value="">Geen parent</option>
              <option v-for="dossier in (dossiers ?? [])" :key="dossier.id" :value="String(dossier.id)">
                {{ dossier.naam }}
              </option>
            </select>
            <button
              type="submit"
              :disabled="isCreatingDossier || !isDossierCreationEnabled"
              class="inline-flex items-center justify-center rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              Dossier toevoegen
            </button>
          </form>
        </div>

        <div class="mb-4">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Inhoud van de Case</h2>
        </div>
        <CaseConsultPanel
          :dossiers="dossiers"
          :transactie-soort-id="selectedTransactieId"
          :case-id="caseId"
          :mutation-target="mutationTarget"
          @select-mutate="onSelectMutate"
          @mutation-changed="refreshDossiers"
        />
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
