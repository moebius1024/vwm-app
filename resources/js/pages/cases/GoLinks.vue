<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { start } from '@/routes/cases';

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
  dossier_naam: string;
  case_id: number;
  case_soort_naam: string;
  case_soort_code: string;
  created_at: string;
  toestanden: ToestandItem[];
  follow_info?: {
    is_followed?: boolean;
    source_goic_uri?: string | null;
    source_goic_id?: number | null;
    source_case_id?: number | null;
    source_state?: ToestandItem | null;
  } | null;
};

type Props = {
  goUri: string;
  selectedCaseId?: number | null;
  goics?: GoicItem[];
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
      {
        title: 'GO-links',
        href: '/raadplegen/go',
      },
    ],
  },
});

const props = defineProps<Props>();
const labelMap = ref<Record<string, string>>({});
const goicDisplayMap = ref<Record<string, string>>({});
const identifierMap = ref<Record<string, { describedClass: string; properties: string[] }>>({});
const describedClassByTbClass = ref<Record<string, string>>({});

const goics = computed(() => props.goics ?? []);
const isOriginGoic = (goic: GoicItem) => (
  !!props.selectedCaseId && goic.case_id === props.selectedCaseId
);
const originGoics = computed(() => goics.value.filter((goic) => isOriginGoic(goic)));
const otherGoics = computed(() => goics.value.filter((goic) => !isOriginGoic(goic)));
const totalGoicCount = computed(() => goics.value.length);
const otherGoicCount = computed(() => otherGoics.value.length);
const consultBackHref = computed(() => (
  props.selectedCaseId
    ? `/raadplegen?case=${props.selectedCaseId}`
    : '/raadplegen'
));
const editBackHref = computed(() => (
  props.selectedCaseId
    ? `/bewerken?case=${props.selectedCaseId}`
    : '/start'
));
const caseConsultHref = (caseId: number) => {
  const params = new URLSearchParams({ case: String(caseId), go: props.goUri });

  if (props.selectedCaseId) {
    params.set('follow_target_case', String(props.selectedCaseId));
  }

  return `/raadplegen?${params.toString()}`;
};

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

const isAssociationLikeField = (key: string) => {
  const normalized = key.trim().toLowerCase();

  return normalized === 'producedattime'
    || normalized === 'targetobject'
    || normalized === 'ownedobject'
    || normalized === 'invalidatedattime'
    || normalized.endsWith('#producedattime')
    || normalized.endsWith('/producedattime')
    || normalized.endsWith('#targetobject')
    || normalized.endsWith('/targetobject')
    || normalized.endsWith('#ownedobject')
    || normalized.endsWith('/ownedobject')
    || normalized.endsWith('#invalidatedattime')
    || normalized.endsWith('/invalidatedattime');
};

const isBestandField = (key: string) => {
  if (key === 'heeftBestand') {
    return true;
  }

  return key.endsWith('#heeftBestand') || key.endsWith('/heeftBestand');
};

const firstUriFromValue = (value: unknown): string | null => {
  if (isUri(value)) {
    return value;
  }

  if (Array.isArray(value)) {
    for (const item of value) {
      if (isUri(item)) {
        return item;
      }
    }
  }

  return null;
};

const bestandViewUrl = (value: unknown) => {
  const uri = firstUriFromValue(value);

  if (!uri) {
    return null;
  }

  return `/api/bestand/view?uri=${encodeURIComponent(uri)}`;
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

const buildGoicDisplayMap = (items: GoicItem[]) => {
  const map: Record<string, string> = {};
  items.forEach((goic) => {
    const reversed = [...goic.toestanden].reverse();
    const preferredTb = reversed.find((tb) => !!tb.tb_class && !!identifierMap.value[tb.tb_class]);
    const fallbackTb = reversed.find((tb) => !!tb.tb_class);
    const lastTb = preferredTb ?? fallbackTb ?? null;

    if (!lastTb || !lastTb.tb_class) {
      map[goic.rdf_uri] = 'GOIC';

      return;
    }

    const idConfig = identifierMap.value[lastTb.tb_class];
    const describedClass = idConfig?.describedClass ?? describedClassByTbClass.value[lastTb.tb_class] ?? null;
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

    const pickReadableIdentifier = (value: string) => {
      const parts = value.split(',').map((item) => item.trim()).filter((item) => item !== '');

      if (parts.length === 0) {
        return '';
      }

      const nonNumeric = parts.find((item) => /[A-Za-zÀ-ÖØ-öø-ÿ]/.test(item));

      return nonNumeric ?? parts[0];
    };

    const readableIdentifier = pickReadableIdentifier(identifierValue);
    map[goic.rdf_uri] = readableIdentifier
      ? `${classLabel} #${goic.id}, ${readableIdentifier}`
      : `${classLabel} #${goic.id}`;
  });
  goicDisplayMap.value = map;
};

const goicClassLabel = (goic: GoicItem) => {
  const classLabelForTb = (tb: ToestandItem | null | undefined) => {
    if (!tb?.tb_class) {
      return '';
    }

    const idConfig = identifierMap.value[tb.tb_class];
    const describedClass = idConfig?.describedClass ?? describedClassByTbClass.value[tb.tb_class] ?? null;

    if (!describedClass) {
      return '';
    }

    return labelMap.value[describedClass] ?? shortId(describedClass);
  };

  const reversed = [...goic.toestanden].reverse();
  const preferredTb = reversed.find((item) => !!item.tb_class && !!identifierMap.value[item.tb_class]);
  const fallbackTb = reversed.find((item) => !!item.tb_class);
  const tb = preferredTb ?? fallbackTb ?? null;

  return classLabelForTb(tb) || classLabelForTb(goic.follow_info?.source_state ?? null);
};

const followedRegistrationTitle = (goic: GoicItem) => {
  const classLabel = goicClassLabel(goic);

  return classLabel ? `Gevolgde ${classLabel} Registratie` : 'Gevolgde Registratie';
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

  return idConfig?.describedClass ?? describedClassByTbClass.value[tb.tb_class] ?? null;
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

    if (uri.includes('/data/goic/')) {
      return `GOIC ${shortId(uri)}`;
    }

    return shortId(uri);
  }

  if (typeof value === 'object') {
    return JSON.stringify(value, null, 2);
  }

  return String(value);
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
  if (isAssociationLikeField(key)) {
    return true;
  }

  const keyLabel = fieldLabelFor(key).toLowerCase();

  if (keyLabel === 'beschrijving' && typeof value === 'string' && value.toLowerCase().startsWith('verwijst naar goic ')) {
    return true;
  }

  return isSelfReferenceValue(value, goic, key);
};

const tbEntries = (tb: ToestandItem): [string, unknown][] => {
  if (!tb.tb_data || typeof tb.tb_data !== 'object' || Array.isArray(tb.tb_data)) {
    return [];
  }

  return Object.entries(tb.tb_data as Record<string, unknown>);
};

const isAssociationToestand = (tb: ToestandItem) => {
  const value = `${tb.tb_class ?? ''} ${tb.sjabloon_uri ?? ''}`.toLowerCase();

  return value.includes('dataobjectassociation');
};

const visibleToestanden = (goic: GoicItem) => {
  return goic.toestanden.filter((tb) => !isAssociationToestand(tb));
};

const visibleFollowSourceEntries = (goic: GoicItem): [string, unknown][] => {
  const state = goic.follow_info?.source_state;

  if (!state || isAssociationToestand(state)) {
    return [];
  }

  return tbEntries(state).filter(([key, value]) => !shouldSkipFieldForGoic(String(key), value, goic));
};

const collectUris = (items: GoicItem[]) => {
  const set = new Set<string>();
  items.forEach((goic) => {
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

  return Array.from(set);
};

const apiUrl = (path: string) => {
  if (typeof window !== 'undefined') {
    return path;
  }

  return new URL(path, 'http://localhost').toString();
};

const ensureClassLabels = async () => {
  const classUris = new Set<string>();

  Object.values(describedClassByTbClass.value).forEach((uri) => {
    if (isUri(uri)) {
      classUris.add(uri);
    }
  });

  Object.values(identifierMap.value).forEach((row) => {
    if (isUri(row.describedClass)) {
      classUris.add(row.describedClass);
    }
  });

  const missing = Array.from(classUris).filter((uri) => !labelMap.value[uri]);

  if (!missing.length) {
    return;
  }

  try {
    const response = await axios.post(apiUrl('/api/labels'), { uris: missing });
    labelMap.value = {
      ...labelMap.value,
      ...(response.data?.labels ?? {}),
    };
  } catch (error) {
    console.error('Fout bij ophalen ontbrekende class-labels:', error);
  }
};

const loadLabels = async () => {
  if (typeof window === 'undefined') {
    return;
  }

  if (!goics.value.length) {
    labelMap.value = {};
    identifierMap.value = {};

    return;
  }

  const uris = collectUris(goics.value);

  if (!uris.length) {
    labelMap.value = {};
    identifierMap.value = {};

    return;
  }

  try {
    let labelsResponse = await axios.post(apiUrl('/api/labels'), { uris });
    let labels = labelsResponse.data.labels ?? {};

    if (!Object.keys(labels).length) {
      labelsResponse = await axios.get(apiUrl('/api/labels'));
      labels = labelsResponse.data.labels ?? {};
    }

    labelMap.value = labels;
  } catch (error) {
    console.error('Fout bij ophalen labels:', error);
    labelMap.value = {};
  }

  try {
    const sjablonenResponse = await axios.get(apiUrl('/api/sjablonen'));
    const sjablonen = sjablonenResponse.data.sjablonen ?? [];
    const classMap: Record<string, string> = {};
    sjablonen.forEach((row: { sjabloon_uri?: string | null; target_class?: string | null }) => {
      if (row?.sjabloon_uri && row?.target_class) {
        classMap[row.sjabloon_uri] = row.target_class;
      }
    });
    describedClassByTbClass.value = classMap;
  } catch (error) {
    console.error('Fout bij ophalen sjabloon target classes:', error);
    describedClassByTbClass.value = {};
  }

  try {
    const identifiersResponse = await axios.get(apiUrl('/api/identifiers'));
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

  await ensureClassLabels();
  buildGoicDisplayMap(goics.value);
};

watch(() => props.goics, () => {
  loadLabels();
}, { immediate: true });
</script>

<template>
  <Head title="Raadplegen gekoppelde Registraties" />

  <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
    <div class="rounded-2xl border border-sidebar-border/70 bg-gradient-to-br from-white via-white to-indigo-50 px-6 py-5 shadow-sm dark:border-sidebar-border dark:from-gray-900 dark:via-gray-900 dark:to-gray-800">
      <div class="flex items-start justify-between gap-4">
        <div class="flex flex-col gap-2">
          <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Raadplegen gekoppelde Registraties.</h1>
          <p class="text-sm text-gray-600 dark:text-gray-300">
            Overzicht van alle Registraties die via de Sleutelhanger gekoppeld zijn.
          </p>
          <p class="text-xs text-gray-600 dark:text-gray-300">
            (ID Sleutelhanger: <span class="break-all font-mono text-[11px]">{{ goUri }}</span>)
          </p>
        </div>
        <div class="flex flex-wrap justify-end gap-2">
          <Link
            class="inline-flex items-center rounded-lg border border-amber-200 bg-white px-4 py-2 text-sm font-semibold text-amber-800 shadow-sm transition hover:bg-amber-50 dark:border-amber-300/30 dark:bg-gray-900 dark:text-amber-100 dark:hover:bg-amber-900/30"
            :href="editBackHref"
          >
            Terug naar jouw case
          </Link>
          <Link
            class="inline-flex items-center rounded-lg border border-indigo-200 bg-white px-4 py-2 text-sm font-semibold text-indigo-800 shadow-sm transition hover:bg-indigo-50 dark:border-indigo-300/30 dark:bg-gray-900 dark:text-indigo-100 dark:hover:bg-indigo-900/30"
            :href="consultBackHref"
          >
            Terug naar raadplegen
          </Link>
          <Link
            class="inline-flex items-center rounded-lg border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-800 shadow-sm transition hover:bg-emerald-50 dark:border-emerald-300/30 dark:bg-gray-900 dark:text-emerald-100 dark:hover:bg-emerald-900/30"
            :href="start()"
          >
            Terug naar start
          </Link>
        </div>
      </div>
    </div>

    <div class="relative flex-1 rounded-2xl border border-sidebar-border/70 bg-white p-8 shadow-sm dark:border-sidebar-border dark:bg-gray-900">
      <div class="mx-auto max-w-5xl space-y-4">
        <div v-if="!goics.length" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-gray-600 dark:border-gray-700 dark:text-gray-300">
          Geen gekoppelde GOIC's gevonden voor deze GO.
        </div>

        <div v-else class="space-y-4">
          <div class="rounded-xl border border-indigo-200 bg-indigo-50/50 px-4 py-3 text-sm text-indigo-900 dark:border-indigo-300/30 dark:bg-indigo-900/20 dark:text-indigo-100">
            Totaal {{ totalGoicCount }} Registraties, waarvan {{ otherGoicCount }} in andere cases.
          </div>

          <div v-if="originGoics.length" class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">
              Jouw Case (Bron)
            </h2>
            <div v-for="goic in originGoics" :key="`origin-${goic.id}`" class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-5 dark:border-emerald-300/30 dark:bg-emerald-900/20">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-2">
                  <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                    <span v-if="goicClassLabel(goic)">{{ goicClassLabel(goic) }} (#{{ goic.id }})</span>
                    <span v-else>GOIC #{{ goic.id }}</span>
                  </h3>
                  <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                    <span>Case: {{ goic.case_soort_naam }} ({{ goic.case_soort_code }}) · #{{ goic.case_id }}</span>
                    <span>Dossier: {{ goic.dossier_naam }} · #{{ goic.dossier_id }}</span>
                  </div>
                </div>
              </div>

              <div
                v-if="goic.follow_info?.is_followed && visibleFollowSourceEntries(goic).length"
                class="mt-3 rounded-lg border border-indigo-200 bg-indigo-50/60 p-3 text-xs text-indigo-900 dark:border-indigo-400/30 dark:bg-indigo-900/20 dark:text-indigo-100"
              >
              <div class="font-semibold">
                  {{ followedRegistrationTitle(goic) }} #{{ goic.follow_info.source_goic_id ?? '?' }}
                  <span v-if="goic.follow_info.source_case_id">in case #{{ goic.follow_info.source_case_id }}</span>
                </div>
                <div class="mt-2 text-[11px] opacity-90">Alleen raadplegen: deze beschrijving komt uit de gevolgde Registratie.</div>
                <div class="mt-2 rounded border border-indigo-200 bg-white p-2 dark:border-indigo-400/30 dark:bg-gray-900">
                  <template
                    v-for="([key, value]) in visibleFollowSourceEntries(goic)"
                    :key="`follow-origin-${goic.id}-${String(key)}`"
                  >
                    <div class="flex flex-wrap items-start gap-2">
                      <span class="min-w-[180px] font-medium">{{ fieldLabelFor(String(key)) || 'Onbekend veld' }}</span>
                      <span v-if="isBestandField(String(key))" class="break-all">
                        Bestand gekoppeld
                        <a
                          v-if="bestandViewUrl(value)"
                          :href="bestandViewUrl(value)!"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="ml-2 inline-flex items-center rounded-md border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-800 transition hover:bg-sky-100 dark:border-sky-300/30 dark:bg-sky-900/30 dark:text-sky-100 dark:hover:bg-sky-900/50"
                        >
                          Bekijk bestand
                        </a>
                      </span>
                      <span v-else class="break-all">{{ formatValue(value) }}</span>
                    </div>
                  </template>
                </div>
              </div>

              <div v-if="visibleToestanden(goic).length" class="mt-3 space-y-2">
                <div v-for="tb in visibleToestanden(goic)" :key="tb.mutatie_id" class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                  <div v-if="tbEntries(tb).length" class="mt-2 space-y-1 text-xs text-gray-700 dark:text-gray-200">
                    <template v-for="([key, value]) in tbEntries(tb)" :key="key">
                      <div
                        v-if="!shouldSkipFieldForGoic(String(key), value, goic)"
                        class="flex flex-wrap items-start gap-2"
                      >
                        <span class="min-w-[180px] font-medium text-gray-600 dark:text-gray-300">
                          {{ fieldLabelFor(String(key)) || 'Onbekend veld' }}
                        </span>
                        <span
                          v-if="isBestandField(String(key))"
                          class="break-all text-gray-800 dark:text-gray-100"
                        >
                          Bestand gekoppeld
                          <a
                            v-if="bestandViewUrl(value)"
                            :href="bestandViewUrl(value)!"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="ml-2 inline-flex items-center rounded-md border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-800 transition hover:bg-sky-100 dark:border-sky-300/30 dark:bg-sky-900/30 dark:text-sky-100 dark:hover:bg-sky-900/50"
                          >
                            Bekijk bestand
                          </a>
                        </span>
                        <span v-else class="break-all text-gray-800 dark:text-gray-100">
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

          <div v-if="otherGoics.length" class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">
              Andere Cases
            </h2>
            <div v-for="goic in otherGoics" :key="`other-${goic.id}`" class="rounded-xl border border-gray-200 p-5 dark:border-gray-700">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-2">
                  <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                  <span v-if="goicClassLabel(goic)">{{ goicClassLabel(goic) }} (#{{ goic.id }})</span>
                  <span v-else>GOIC #{{ goic.id }}</span>
                </h3>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                  <span>Case: {{ goic.case_soort_naam }} ({{ goic.case_soort_code }}) · #{{ goic.case_id }}</span>
                  <span>Dossier: {{ goic.dossier_naam }} · #{{ goic.dossier_id }}</span>
                </div>
              </div>
                <Link
                  :href="caseConsultHref(goic.case_id)"
                  class="inline-flex items-center rounded-md border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-800 transition hover:bg-sky-100 dark:border-sky-300/30 dark:bg-sky-900/30 dark:text-sky-100 dark:hover:bg-sky-900/50"
                >
                  Naar case #{{ goic.case_id }} in raadplegen
                </Link>
              </div>

            <div
              v-if="goic.follow_info?.is_followed && visibleFollowSourceEntries(goic).length"
              class="mt-3 rounded-lg border border-indigo-200 bg-indigo-50/60 p-3 text-xs text-indigo-900 dark:border-indigo-400/30 dark:bg-indigo-900/20 dark:text-indigo-100"
            >
              <div class="font-semibold">
                {{ followedRegistrationTitle(goic) }} #{{ goic.follow_info.source_goic_id ?? '?' }}
                <span v-if="goic.follow_info.source_case_id">in case #{{ goic.follow_info.source_case_id }}</span>
              </div>
              <div class="mt-2 text-[11px] opacity-90">Alleen raadplegen: deze beschrijving komt uit de gevolgde Registratie.</div>
              <div class="mt-2 rounded border border-indigo-200 bg-white p-2 dark:border-indigo-400/30 dark:bg-gray-900">
                <template
                  v-for="([key, value]) in visibleFollowSourceEntries(goic)"
                  :key="`follow-${goic.id}-${String(key)}`"
                >
                  <div class="flex flex-wrap items-start gap-2">
                    <span class="min-w-[180px] font-medium">{{ fieldLabelFor(String(key)) || 'Onbekend veld' }}</span>
                    <span v-if="isBestandField(String(key))" class="break-all">
                      Bestand gekoppeld
                      <a
                        v-if="bestandViewUrl(value)"
                        :href="bestandViewUrl(value)!"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="ml-2 inline-flex items-center rounded-md border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-800 transition hover:bg-sky-100 dark:border-sky-300/30 dark:bg-sky-900/30 dark:text-sky-100 dark:hover:bg-sky-900/50"
                      >
                        Bekijk bestand
                      </a>
                    </span>
                    <span v-else class="break-all">{{ formatValue(value) }}</span>
                  </div>
                </template>
              </div>
            </div>

            <div v-if="visibleToestanden(goic).length" class="mt-3 space-y-2">
              <div v-for="tb in visibleToestanden(goic)" :key="tb.mutatie_id" class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                <div v-if="tbEntries(tb).length" class="mt-2 space-y-1 text-xs text-gray-700 dark:text-gray-200">
                  <template v-for="([key, value]) in tbEntries(tb)" :key="key">
                    <div
                      v-if="!shouldSkipFieldForGoic(String(key), value, goic)"
                      class="flex flex-wrap items-start gap-2"
                    >
                      <span class="min-w-[180px] font-medium text-gray-600 dark:text-gray-300">
                        {{ fieldLabelFor(String(key)) || 'Onbekend veld' }}
                      </span>
                      <span
                        v-if="isBestandField(String(key))"
                        class="break-all text-gray-800 dark:text-gray-100"
                      >
                        Bestand gekoppeld
                        <a
                          v-if="bestandViewUrl(value)"
                          :href="bestandViewUrl(value)!"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="ml-2 inline-flex items-center rounded-md border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-800 transition hover:bg-sky-100 dark:border-sky-300/30 dark:bg-sky-900/30 dark:text-sky-100 dark:hover:bg-sky-900/50"
                        >
                          Bekijk bestand
                        </a>
                      </span>
                      <span v-else class="break-all text-gray-800 dark:text-gray-100">
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
        </div>
      </div>
    </div>
  </div>
</template>
