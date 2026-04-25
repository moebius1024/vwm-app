<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { start } from '@/routes/cases';

type CaseItem = {
  id: number;
  uuid: string;
  case_soort_naam: string;
  case_soort_code: string;
  created_at: string;
};

type ToestandItem = {
  mutatie_id: number;
  sjabloon_uri: string;
  tb_id: number | null;
  tb_rdf_uri: string | null;
  tb_class: string | null;
  tb_data: Record<string, unknown> | string | null;
  created_at: string;
};

type GoicItem = {
  id: number;
  rdf_uri: string;
  dossier_id: number;
  created_at: string;
  toestanden: ToestandItem[];
};

type DossierItem = {
  id: number;
  naam: string;
  rdf_uri: string;
  parent_id: number | null;
  created_at: string;
  goics: GoicItem[];
};

type Props = {
  cases: CaseItem[];
  activeCase?: CaseItem | null;
  dossiers?: DossierItem[];
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Start',
                href: start(),
            },
            {
                title: 'Raadplegen',
                href: '/raadplegen',
            },
        ],
    },
});

const props = defineProps<Props>();
const selectedCaseId = ref<number | null>(props.activeCase?.id ?? null);
const hasSelection = computed(() => !!selectedCaseId.value);
const labelMap = ref<Record<string, string>>({});
const goicDisplayMap = ref<Record<string, string>>({});
const identifierMap = ref<Record<string, { describedClass: string; properties: string[] }>>({});

watch(selectedCaseId, (value) => {
  if (!value) {
return;
}

  router.get('/raadplegen', { case: value }, { preserveState: true, replace: true });
});

const isUri = (value: unknown): value is string =>
  typeof value === 'string' && (value.startsWith('http://') || value.startsWith('https://'));

const labelFor = (value: unknown) => {
  if (!isUri(value)) {
return null;
}

  return labelMap.value[value] ?? null;
};

const keyLabelFor = (key: string) => {
  const map: Record<string, string> = {
    rolType: 'Rol',
    van: 'Van',
    naar: 'Naar',
  };

  return map[key] ?? null;
};

const fieldLabelFor = (key: string) => {
  return labelFor(key) || keyLabelFor(key) || (isUri(key) ? shortId(key) : key);
};

const normalizeUri = (uri: string) => (uri.endsWith('/') ? uri.slice(0, -1) : uri);

const extractUrisFromValue = (value: unknown): string[] => {
  const uris: string[] = [];

  if (isUri(value)) {
    uris.push(normalizeUri(value as string));

    return uris;
  }

  if (Array.isArray(value)) {
    value.forEach((item) => {
      uris.push(...extractUrisFromValue(item));
    });

    return uris;
  }

  if (value && typeof value === 'object') {
    const record = value as Record<string, unknown>;
    const directKeys = ['@id', 'id', 'rdf_uri', 'uri'];
    directKeys.forEach((key) => {
      const raw = record[key];

      if (isUri(raw)) {
uris.push(normalizeUri(raw as string));
}
    });
    Object.values(record).forEach((item) => {
      uris.push(...extractUrisFromValue(item));
    });
  }

  return uris;
};

const isSelfReferenceValue = (value: unknown, goic: GoicItem, key: string) => {
  const fieldLabel = fieldLabelFor(key);
  const formatted = formatValue(value);
  const subjectLabel = goicClassLabel(goic);
  const subjectClassUri = goicClassUri(goic);
  const subjectShort = subjectClassUri ? shortId(subjectClassUri) : '';

  const uris = extractUrisFromValue(value);
  const goicUri = normalizeUri(goic.rdf_uri);

  if (uris.length > 0 && uris.every((uri) => uri === goicUri)) {
    return true;
  }

  if (fieldLabel === 'Van' && subjectLabel && formatted) {
    const normalized = formatted.toLowerCase();
    const labelMatch = subjectLabel.toLowerCase();
    const shortMatch = subjectShort ? subjectShort.toLowerCase() : '';

    if (
      normalized === labelMatch ||
      normalized.startsWith(`${labelMatch}:`) ||
      (shortMatch && (normalized === shortMatch || normalized.startsWith(`${shortMatch}:`)))
    ) {
      return true;
    }
  }

  const currentDisplay = goicDisplayMap.value[goic.rdf_uri];

  if (!currentDisplay) {
return false;
}

  if (isUri(value)) {
    const display = goicDisplayMap.value[value as string];

    if (display && display === currentDisplay) {
return true;
}
  }

  if (Array.isArray(value)) {
    const displays = value
      .map((item) => (isUri(item) ? goicDisplayMap.value[item as string] : null))
      .filter((item): item is string => !!item);

    if (displays.length > 0 && displays.every((item) => item === currentDisplay)) {
      return true;
    }
  }

  return false;
};

const shouldSkipFieldForGoic = (key: string, value: unknown, goic: GoicItem) => {
  return isSelfReferenceValue(value, goic, key);
};

const tbEntries = (tb: ToestandItem): [string, unknown][] => {
  if (!tb.tb_data || typeof tb.tb_data !== 'object' || Array.isArray(tb.tb_data)) {
    return [];
  }

  return Object.entries(tb.tb_data as Record<string, unknown>);
};

const shortId = (uri: string) => {
  const trimmed = uri.endsWith('/') ? uri.slice(0, -1) : uri;

  if (trimmed.includes('#')) {
    const parts = trimmed.split('#');

    return parts[parts.length - 1] ?? uri;
  }

  const parts = trimmed.split('/');

  return parts[parts.length - 1] ?? uri;
};

const buildGoicDisplayMap = (dossiers: DossierItem[]) => {
  const map: Record<string, string> = {};
  dossiers.forEach((dossier) => {
    dossier.goics.forEach((goic) => {
      const reversed = [...goic.toestanden].reverse();
      const preferredTb = reversed.find((tb) => !!tb.tb_class && !!identifierMap.value[tb.tb_class]);
      const fallbackTb = reversed.find((tb) => !!tb.tb_class);
      const lastTb = preferredTb ?? fallbackTb ?? null;

      if (!lastTb || !lastTb.tb_class) {
        map[goic.rdf_uri] = 'GOIC';

        return;
      }

      const idConfig = identifierMap.value[lastTb.tb_class];
      const describedClass = idConfig?.describedClass ?? null;
      const classLabel = describedClass
        ? (labelMap.value[describedClass] ?? shortId(describedClass))
        : (labelMap.value[lastTb.tb_class] ?? shortId(lastTb.tb_class));

      let identifierValue = '';

      if (idConfig && lastTb.tb_data && typeof lastTb.tb_data === 'object' && !Array.isArray(lastTb.tb_data)) {
        const values: string[] = [];
        idConfig.properties.forEach((prop) => {
          const raw = (lastTb.tb_data as Record<string, unknown>)[prop];

          if (raw === null || raw === undefined || raw === '') {
return;
}

          values.push(formatValue(raw));
        });

        if (values.length) {
          identifierValue = values.join(', ');
        }
      }

      map[goic.rdf_uri] = identifierValue ? `${classLabel}: ${identifierValue}` : classLabel;
    });
  });
  goicDisplayMap.value = map;
};

const goicClassLabel = (goic: GoicItem) => {
  const reversed = [...goic.toestanden].reverse();
  const preferredTb = reversed.find((item) => !!item.tb_class && !!identifierMap.value[item.tb_class]);
  const fallbackTb = reversed.find((item) => !!item.tb_class);
  const tb = preferredTb ?? fallbackTb ?? null;

  if (!tb?.tb_class) {
return '';
}

  const idConfig = identifierMap.value[tb.tb_class];
  const describedClass = idConfig?.describedClass ?? null;

  if (!describedClass) {
return '';
}

  return labelMap.value[describedClass] ?? shortId(describedClass);
};

const goicClassUri = (goic: GoicItem) => {
  const reversed = [...goic.toestanden].reverse();
  const preferredTb = reversed.find((item) => !!item.tb_class && !!identifierMap.value[item.tb_class]);
  const fallbackTb = reversed.find((item) => !!item.tb_class);
  const tb = preferredTb ?? fallbackTb ?? null;

  if (!tb?.tb_class) {
return null;
}

  const idConfig = identifierMap.value[tb.tb_class];

  return idConfig?.describedClass ?? null;
};

const formatValue = (value: unknown) => {
  if (value === null || value === undefined) {
return '';
}

  if (Array.isArray(value)) {
    return value
      .map((item) => labelFor(item) ?? goicDisplayMap.value[item as string] ?? (isUri(item) ? shortId(item) : 'Onbekend'))
      .join(', ');
  }

  if (isUri(value)) {
    const uri = value as string;

    if (goicDisplayMap.value[uri]) {
return goicDisplayMap.value[uri];
}

    const label = labelFor(uri);

    if (label) {
return label;
}

    return shortId(uri);
  }

  if (typeof value === 'object') {
return JSON.stringify(value, null, 2);
}

  return String(value);
};

const collectUris = (dossiers: DossierItem[]) => {
  const set = new Set<string>();
  dossiers.forEach((dossier) => {
    dossier.goics.forEach((goic) => {
      goic.toestanden.forEach((tb) => {
        if (tb.tb_class) {
set.add(tb.tb_class);
}

        if (tb.tb_rdf_uri) {
set.add(tb.tb_rdf_uri);
}

        if (tb.sjabloon_uri) {
set.add(tb.sjabloon_uri);
}

        if (tb.tb_data && typeof tb.tb_data === 'object' && !Array.isArray(tb.tb_data)) {
          Object.entries(tb.tb_data).forEach(([key, value]) => {
            if (isUri(key)) {
set.add(key);
}

            if (isUri(value)) {
set.add(value);
}

            if (Array.isArray(value)) {
              value.forEach((item) => {
                if (isUri(item)) {
set.add(item);
}
              });
            }
          });
        }
      });
    });
  });

  return Array.from(set);
};

const loadLabels = async () => {
  if (typeof window === 'undefined') {
    return;
  }

  if (!props.dossiers || props.dossiers.length === 0) {
    labelMap.value = {};
    identifierMap.value = {};

    return;
  }

  const uris = collectUris(props.dossiers);

  if (!uris.length) {
    labelMap.value = {};
    identifierMap.value = {};

    return;
  }

  try {
    let labelsResponse = await axios.post('/api/labels', { uris });
    let labels = labelsResponse.data.labels ?? {};

    if (!Object.keys(labels).length) {
      labelsResponse = await axios.get('/api/labels');
      labels = labelsResponse.data.labels ?? {};
    }

    labelMap.value = labels;
  } catch (error) {
    console.error('Fout bij ophalen labels:', error);
    labelMap.value = {};
  }

  try {
    const identifiersResponse = await axios.get('/api/identifiers');
    const list = identifiersResponse.data.identifiers ?? [];
    const map: Record<string, { describedClass: string; properties: string[] }> = {};
    list.forEach((row: { tb_class: string; described_class: string; properties: string[] }) => {
      if (row.tb_class) {
        map[row.tb_class] = {
          describedClass: row.described_class,
          properties: row.properties ?? [],
        };
      }
    });
    identifierMap.value = map;
  } catch (error) {
    console.error('Fout bij ophalen identifiers:', error);
    identifierMap.value = {};
  }

  buildGoicDisplayMap(props.dossiers);
};

watch(() => props.dossiers, () => {
  loadLabels();
}, { immediate: true });
</script>

<template>
  <Head title="Raadplegen" />

  <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
    <div class="rounded-2xl border border-sidebar-border/70 bg-gradient-to-br from-white via-white to-emerald-50 px-6 py-5 shadow-sm dark:border-sidebar-border dark:from-gray-900 dark:via-gray-900 dark:to-gray-800">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Raadplegen</h1>
        <p class="text-sm text-gray-600 dark:text-gray-300">
          Bekijk dossiers en inhoud van bestaande cases.
        </p>
      </div>
    </div>

    <div class="flex justify-end">
      <Link
        class="inline-flex items-center rounded-lg border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-800 shadow-sm transition hover:bg-emerald-50 dark:border-emerald-300/30 dark:bg-gray-900 dark:text-emerald-100 dark:hover:bg-emerald-900/30"
        :href="start()"
      >
        Terug naar start
      </Link>
    </div>

    <div class="relative flex-1 rounded-2xl border border-sidebar-border/70 bg-white p-8 shadow-sm dark:border-sidebar-border dark:bg-gray-900">
      <div class="mx-auto max-w-5xl space-y-6">
        <div class="rounded-xl border border-emerald-200/70 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-300/20 dark:bg-emerald-900/20 dark:text-emerald-100">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
              <span class="font-medium">Case kiezen</span>
              <select
                v-model="selectedCaseId"
                class="h-10 rounded-lg border border-emerald-200 bg-white px-3 text-sm text-emerald-900 shadow-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/40 dark:border-emerald-300/30 dark:bg-gray-900 dark:text-emerald-100"
              >
                <option disabled value="">Kies een case</option>
                <option v-for="item in cases" :key="item.id" :value="item.id">
                  {{ item.case_soort_naam }} ({{ item.case_soort_code }}) · #{{ item.id }}
                </option>
              </select>
            </div>
            <Link class="text-sm font-medium underline decoration-emerald-300 underline-offset-4 hover:decoration-current" href="/start">
              Nieuwe case aanmaken
            </Link>
          </div>
        </div>

        <div v-if="!hasSelection" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-gray-600 dark:border-gray-700 dark:text-gray-300">
          Kies een case om dossiers en inhoud te bekijken.
        </div>

        <div v-else class="space-y-6">
          <div v-if="activeCase" class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
            Actieve case: {{ activeCase.case_soort_naam }} ({{ activeCase.case_soort_code }}) · #{{ activeCase.id }}
          </div>

          <div v-if="dossiers && dossiers.length" class="space-y-4">
            <div v-for="dossier in dossiers" :key="dossier.id" class="rounded-xl border border-gray-200 p-5 dark:border-gray-700">
              <div class="flex flex-col gap-1">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ dossier.naam }}</h3>
              </div>

              <div v-if="dossier.goics.length" class="mt-4 space-y-3">
                <div v-for="goic in dossier.goics" :key="goic.id" class="rounded-lg border border-gray-100 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div class="text-sm font-medium text-gray-800 dark:text-gray-200">
                      GOIC #{{ goic.id }}<span v-if="goicClassLabel(goic)"> · {{ goicClassLabel(goic) }}</span>
                    </div>

                  <div v-if="goic.toestanden.length" class="mt-3 space-y-2">
                    <div v-for="tb in goic.toestanden" :key="tb.mutatie_id" class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                      <div v-if="tbEntries(tb).length" class="mt-2 space-y-1 text-xs text-gray-700 dark:text-gray-200">
                        <template v-for="([key, value]) in tbEntries(tb)" :key="key">
                          <div
                            v-if="!shouldSkipFieldForGoic(String(key), value, goic)"
                            class="flex flex-wrap items-start gap-2"
                          >
                            <span class="min-w-[180px] font-medium text-gray-600 dark:text-gray-300">
                              {{ fieldLabelFor(String(key)) || 'Onbekend veld' }}
                            </span>
                            <span class="break-all text-gray-800 dark:text-gray-100">
                              {{ formatValue(value) }}
                            </span>
                          </div>
                        </template>
                      </div>
                      <pre v-else class="mt-2 overflow-x-auto rounded bg-gray-50 p-2 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ formatValue(tb.tb_data) }}</pre>
                    </div>
                  </div>
                  <div v-else class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Geen toestandsbeschrijvingen gevonden.
                  </div>
                </div>
              </div>
              <div v-else class="mt-3 text-xs text-gray-500 dark:text-gray-400">Geen GOICs gevonden.</div>
            </div>
          </div>
          <div v-else class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
            Geen dossiers gevonden voor deze case.
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
