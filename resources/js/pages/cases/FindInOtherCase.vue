<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { edit } from '@/routes/cases';
import { store as linkExistingIdentity } from '@/routes/api/identity/link-existing';

type Toestand = {
  tb_rdf_uri: string;
  tb_class: string | null;
  tb_data: Record<string, unknown>;
  created_at: string | null;
  presentation_sort_rank?: number;
  dependent_info?: {
    is_dependent?: boolean;
    source_goic_id?: number | null;
    source_case_id?: number | null;
    source_target_class?: string | null;
    source_state?: Toestand | null;
  } | null;
};

type Candidate = {
  id: number;
  rdf_uri: string;
  case_id: number;
  case_soort_naam: string;
  dossier_naam: string;
  same_go: boolean;
  go_uri: string | null;
  toestanden: Toestand[];
};

type Props = {
  caseSoortId?: number | null;
  source: {
    caseId: number;
    goicId: number;
    goUri: string | null;
    resultLabel: string;
    sameIdentityActionLabel: string;
    alreadyLinkedLabel: string;
  };
  query: string;
  searched: boolean;
  candidates: Candidate[];
};

const props = defineProps<Props>();
const labelMap = ref<Record<string, string>>({});
const goicDisplayMap = ref<Record<string, string>>({});
const fieldOrderByTbClass = ref<Record<string, Record<string, number>>>({});
const linkingCandidateId = ref<number | null>(null);
const linkError = ref('');
const linkMessage = ref('');

const shortId = (value: string) => value.split('#').pop()?.split('/').pop() ?? value;
const isUri = (value: unknown): value is string => typeof value === 'string' && (value.startsWith('http://') || value.startsWith('https://'));
const labelFor = (value: unknown) => isUri(value) ? labelMap.value[value] ?? null : null;
const fieldLabelFor = (key: string) => labelFor(key) ?? ({ rolType: 'Rol', van: 'Van', naar: 'Naar' }[key] ?? shortId(key));
const apiUrl = (path: string) => typeof window === 'undefined' ? new URL(path, 'http://localhost').toString() : path;

const isAssociationLikeField = (key: string) => {
  const normalized = key.toLowerCase();

  return ['producedattime', 'targetobject', 'ownedobject', 'invalidatedattime'].some((field) => normalized === field || normalized.endsWith(`#${field}`) || normalized.endsWith(`/${field}`));
};

const formatFieldValue = (key: string, value: unknown): string => {
  if (Array.isArray(value)) {
    return Array.from(new Set(value.map((item) => formatFieldValue(key, item)))).join(', ');
  }
  if (isUri(value)) {
    return goicDisplayMap.value[value] ?? labelFor(value) ?? value;
  }

  return String(value ?? '');
};

const isDependentToestand = (toestand: Toestand) => toestand.dependent_info?.is_dependent === true;
const dependentSourceState = (toestand: Toestand) => toestand.dependent_info?.source_state ?? null;
const displayedToestand = (toestand: Toestand) => dependentSourceState(toestand) ?? toestand;

const dependentSourceSummary = (toestand: Toestand) => {
  const targetClass = toestand.dependent_info?.source_target_class;
  const sourceGoicId = toestand.dependent_info?.source_goic_id;
  const sourceCaseId = toestand.dependent_info?.source_case_id;
  if (!targetClass || !sourceGoicId || !sourceCaseId) {
    return 'Van bronbeschrijving';
  }

  return `Van ${labelFor(targetClass) ?? shortId(targetClass)} #${sourceGoicId}, Case #${sourceCaseId}`;
};

const visibleEntries = (candidate: Candidate, toestand: Toestand) => {
  const displayed = displayedToestand(toestand);
  const entries = Object.entries(displayed.tb_data).filter(([key, value]) => !isAssociationLikeField(key) && value !== candidate.rdf_uri);
  const orderMap = displayed.tb_class ? fieldOrderByTbClass.value[displayed.tb_class] : null;

  return entries.sort(([aKey], [bKey]) => (orderMap?.[aKey] ?? Number.MAX_SAFE_INTEGER) - (orderMap?.[bKey] ?? Number.MAX_SAFE_INTEGER) || aKey.localeCompare(bKey));
};

const orderedToestanden = (candidate: Candidate) => {
  return candidate.toestanden
    .map((toestand, index) => ({ toestand, index }))
    .sort((a, b) => (a.toestand.presentation_sort_rank ?? 2) - (b.toestand.presentation_sort_rank ?? 2) || a.index - b.index)
    .map((item) => item.toestand);
};

const collectUris = () => {
  const uris = new Set<string>();
  props.candidates.forEach((candidate) => candidate.toestanden.forEach((toestand) => {
    if (toestand.dependent_info?.source_target_class) {
      uris.add(toestand.dependent_info.source_target_class);
    }

    [toestand, dependentSourceState(toestand)].filter((state): state is Toestand => state !== null).forEach((state) => {
      if (state.tb_class) {
        uris.add(state.tb_class);
      }
      Object.entries(state.tb_data).forEach(([key, value]) => {
        if (isUri(key)) {
          uris.add(key);
        }
        if (isUri(value)) {
          uris.add(value);
        }
        if (Array.isArray(value)) {
          value.filter(isUri).forEach((item) => uris.add(item));
        }
      });
    });
  }));

  return Array.from(uris);
};

const loadPresentationMetadata = async () => {
  const uris = collectUris();
  if (uris.length === 0) {
    return;
  }

  try {
    const labels = await axios.post(apiUrl('/api/labels'), { uris });
    labelMap.value = labels.data.labels ?? {};

    const displays = await axios.post(apiUrl('/api/goic/displays'), { uris });
    goicDisplayMap.value = displays.data.labels ?? {};

    const templates = await axios.get(apiUrl('/api/sjablonen'));
    const details = await Promise.all((templates.data.sjablonen ?? []).map((template: { sjabloon_uri?: string }) => template.sjabloon_uri ? axios.get(apiUrl('/api/sjabloon/uri'), { params: { uri: template.sjabloon_uri } }) : null));
    const orderMap: Record<string, Record<string, number>> = {};
    details.filter(Boolean).forEach((response) => {
      const uri = response.data?.sjabloon_uri as string | undefined;
      const fields = response.data?.velden as Array<{ property?: string; volgorde?: number }> | undefined;
      if (uri && fields) {
        orderMap[uri] = Object.fromEntries(fields.filter((field) => field.property).map((field, index) => [field.property!, field.volgorde ?? index + 1]));
      }
    });
    fieldOrderByTbClass.value = orderMap;
  } catch (error) {
    console.error('Fout bij ophalen raadpleegmetadata:', error);
  }
};

const backHref = computed(() => edit({
  query: {
    case: props.source.caseId,
    case_soort: props.caseSoortId ?? undefined,
  },
}));

const linkSameIdentity = async (candidate: Candidate) => {
  if (candidate.same_go || linkingCandidateId.value !== null) {
    return;
  }

  const confirmed = window.confirm(
    `${props.source.sameIdentityActionLabel}?\n\nAlle registraties die nu nog bij de oorspronkelijke identiteit horen, worden gekoppeld aan de gevonden identiteit.`,
  );
  if (!confirmed) {
    return;
  }

  linkingCandidateId.value = candidate.id;
  linkError.value = '';
  linkMessage.value = '';

  try {
    const response = await axios.post(linkExistingIdentity.url(), {
      source_case_id: props.source.caseId,
      source_goic_id: props.source.goicId,
      candidate_goic_id: candidate.id,
      confirmed: true,
    });
    linkMessage.value = response.data?.message ?? 'De registraties zijn gekoppeld.';
    router.reload({ only: ['source', 'candidates'] });
  } catch (error) {
    console.error('Fout bij koppelen van dezelfde identiteit:', error);
    linkError.value = axios.isAxiosError(error)
      ? error.response?.data?.error ?? 'Koppelen is mislukt.'
      : 'Koppelen is mislukt.';
  } finally {
    linkingCandidateId.value = null;
  }
};

watch(() => props.candidates, loadPresentationMetadata, { immediate: true });
</script>

<template>
  <div class="mx-auto w-full max-w-6xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <Head title="Vind in andere case" />

    <div class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
      <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Vind in andere case</h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Vergelijk alle actuele gegevens voordat je een koppeling legt.</p>
      </div>
      <Link :href="backHref" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
        Terug naar case
      </Link>
    </div>

    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950 dark:border-sky-400/30 dark:bg-sky-900/20 dark:text-sky-100">
      Zoekresultaten voor: <span class="font-semibold">{{ query }}</span>
    </div>

    <p v-if="linkMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-400/30 dark:bg-emerald-900/20 dark:text-emerald-100">{{ linkMessage }}</p>
    <p v-if="linkError" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-400/30 dark:bg-red-900/20 dark:text-red-100">{{ linkError }}</p>

    <p v-if="!searched" class="text-sm text-gray-600 dark:text-gray-300">De bronregistratie bevat geen bruikbare zoekwaarde.</p>
    <p v-else-if="candidates.length === 0" class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">Geen registraties gevonden in andere toegankelijke cases.</p>

    <div v-else class="space-y-4">
      <article v-for="candidate in candidates" :key="candidate.id" class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ source.resultLabel }} (#{{ candidate.id }})</h2>
            <p class="text-sm text-gray-600 dark:text-gray-300">Case #{{ candidate.case_id }} · {{ candidate.case_soort_naam }} · {{ candidate.dossier_naam }}</p>
          </div>
          <span v-if="candidate.same_go" class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-100">{{ source.alreadyLinkedLabel }}</span>
          <button
            v-else
            type="button"
            class="rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-amber-500 dark:hover:bg-amber-400"
            :disabled="linkingCandidateId !== null"
            @click="linkSameIdentity(candidate)"
          >
            {{ linkingCandidateId === candidate.id ? 'Koppelen…' : source.sameIdentityActionLabel }}
          </button>
        </div>

        <div class="mt-4 space-y-3">
          <section v-for="toestand in orderedToestanden(candidate)" :key="toestand.tb_rdf_uri" class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900">
            <div
              v-if="isDependentToestand(toestand)"
              class="mt-3 rounded-md border border-indigo-200 bg-indigo-50/60 px-3 py-2 text-xs text-indigo-900 dark:border-indigo-400/30 dark:bg-indigo-900/20 dark:text-indigo-100"
            >
              {{ dependentSourceSummary(toestand) }}
            </div>
            <dl class="mt-2 grid gap-x-5 gap-y-1 text-sm sm:grid-cols-[minmax(10rem,auto)_1fr]">
              <template v-for="([key, value]) in visibleEntries(candidate, toestand)" :key="key">
                <dt class="font-medium text-gray-600 dark:text-gray-300">{{ fieldLabelFor(key) }}</dt>
                <dd class="break-words text-gray-900 dark:text-white">{{ formatFieldValue(key, value) }}</dd>
              </template>
            </dl>
          </section>
        </div>
      </article>
    </div>
  </div>
</template>
