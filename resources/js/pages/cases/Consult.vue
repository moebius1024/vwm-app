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
  go_uri?: string | null;
  linked_goic_count?: number;
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
  followTargetCaseId?: number | null;
  goUri?: string | null;
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
const goDisplayMap = ref<Record<string, string>>({});
const goicDisplayByTail = ref<Record<string, string>>({});
const remoteGoicDisplayMap = ref<Record<string, string>>({});
const identifierMap = ref<Record<string, { describedClass: string; properties: string[] }>>({});
const followBusyGoicId = ref<number | null>(null);
const followError = ref<string>('');
const followMessage = ref<string>('');

const hasLinkedGoics = (goic: GoicItem) =>
  !!goic.go_uri && (goic.linked_goic_count ?? 0) > 1;

const linkedOthersCount = (goic: GoicItem) =>
  Math.max((goic.linked_goic_count ?? 1) - 1, 1);

const goLinksHref = (goic: GoicItem) => {
  if (!goic.go_uri) {
    return '#';
  }

  const params = new URLSearchParams({ go: goic.go_uri });

  if (props.activeCase?.id) {
    params.set('case', String(props.activeCase.id));
  }

  return `/raadplegen/go?${params.toString()}`;
};

watch(selectedCaseId, (value) => {
  if (!value) {
return;
}

  router.get('/raadplegen', {
    case: value,
    go: props.goUri ?? undefined,
    follow_target_case: props.followTargetCaseId ?? undefined,
  }, { preserveState: true, replace: true });
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

const hasVisibleTbContent = (tb: ToestandItem, goic: GoicItem) => {
  return tbEntries(tb).some(([key, value]) => !shouldSkipFieldForGoic(String(key), value, goic));
};

const visibleToestanden = (goic: GoicItem) => {
  return goic.toestanden.filter((tb) => hasVisibleTbContent(tb, goic));
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
  const goMap: Record<string, string> = {};
  const tailMap: Record<string, string> = {};
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

      const display = identifierValue ? `${classLabel}: ${identifierValue}` : classLabel;
      map[goic.rdf_uri] = display;
      tailMap[shortId(goic.rdf_uri)] = display;
      if (goic.go_uri && !goMap[goic.go_uri]) {
        goMap[goic.go_uri] = display;
      }
    });
  });
  goicDisplayMap.value = map;
  goDisplayMap.value = goMap;
  goicDisplayByTail.value = tailMap;
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

const canFollowGoic = (goic: GoicItem) => {
  if (!props.followTargetCaseId || !props.activeCase?.id) {
    return false;
  }

  if (props.activeCase.id === props.followTargetCaseId) {
    return false;
  }

  if (goic.follow_info?.is_followed) {
    return false;
  }

  const hasVolgbareToestand = goic.toestanden.some((tb) => {
    const tbClass = tb.tb_class ?? tb.sjabloon_uri ?? null;
    return typeof tbClass === 'string' && tbClass.length > 0;
  });

  return hasVolgbareToestand;
};

const followGoic = async (goic: GoicItem) => {
  if (!props.followTargetCaseId || !canFollowGoic(goic)) {
    return;
  }

  const ok = window.confirm(
    `Volg GOIC #${goic.id}?\n\n` +
    '1. Er wordt in jouw case een GOIC aangemaakt met hetzelfde type.\n' +
    '2. Deze wordt gekoppeld aan dezelfde GO.\n' +
    '3. DataObjectAssociation wordt vastgelegd.\n' +
    '4. Stap 1 en 3 worden als ObjectMutatie in SQLite opgeslagen.'
  );
  if (!ok) {
    return;
  }

  followBusyGoicId.value = goic.id;
  followError.value = '';
  followMessage.value = '';
  try {
    const response = await axios.post(apiUrl('/api/goic/volg'), {
      case_id: props.followTargetCaseId,
      bron_goic_uri: goic.rdf_uri,
    });
    followMessage.value = response.data?.message ?? `GOIC wordt nu gevolgd (nieuw GOIC #${response.data?.goic_id ?? '?'})`;
  } catch (error) {
    console.error('Fout bij volgen GOIC:', error);
    if (axios.isAxiosError(error)) {
      const apiError = error.response?.data?.error;
      const apiMessage = error.response?.data?.message;
      const apiReason = error.response?.data?.reason;
      const reasonText = typeof apiReason === 'string' && apiReason !== '' ? ` (${apiReason})` : '';
      followError.value = (apiError || apiMessage || 'Volgen van GOIC is mislukt.') + reasonText;
    } else {
      followError.value = 'Volgen van GOIC is mislukt.';
    }
  } finally {
    followBusyGoicId.value = null;
  }
};

const formatValue = (value: unknown) => {
  if (value === null || value === undefined) {
return '';
}

  if (Array.isArray(value)) {
    return value
      .map((item) => (
        labelFor(item)
          ?? goicDisplayMap.value[item as string]
          ?? remoteGoicDisplayMap.value[item as string]
          ?? (isUri(item) ? shortId(item) : 'Onbekend')
      ))
      .join(', ');
  }

  if (isUri(value)) {
    const uri = value as string;

    if (goicDisplayMap.value[uri]) {
return goicDisplayMap.value[uri];
}

    if (remoteGoicDisplayMap.value[uri]) {
return remoteGoicDisplayMap.value[uri];
}

    if (goDisplayMap.value[uri]) {
return goDisplayMap.value[uri];
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

  if (typeof value === 'string') {
    const trimmed = value.trim();
    if (trimmed !== '' && goicDisplayByTail.value[trimmed]) {
      return goicDisplayByTail.value[trimmed];
    }
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

const collectUnknownGoicUris = (dossiers: DossierItem[]) => {
  const known = new Set<string>([
    ...Object.keys(goicDisplayMap.value),
    ...Object.keys(remoteGoicDisplayMap.value),
  ]);
  const unknown = new Set<string>();

  dossiers.forEach((dossier) => {
    dossier.goics.forEach((goic) => {
      const sourceUri = goic.follow_info?.source_goic_uri;
      if (typeof sourceUri === 'string' && sourceUri.includes('/data/goic/') && !known.has(sourceUri)) {
        unknown.add(sourceUri);
      }

      const followState = goic.follow_info?.source_state;
      if (followState?.tb_data && typeof followState.tb_data === 'object' && !Array.isArray(followState.tb_data)) {
        Object.values(followState.tb_data).forEach((value) => {
          if (typeof value === 'string' && value.includes('/data/goic/') && !known.has(value)) {
            unknown.add(value);
          }

          if (Array.isArray(value)) {
            value.forEach((item) => {
              if (typeof item === 'string' && item.includes('/data/goic/') && !known.has(item)) {
                unknown.add(item);
              }
            });
          }
        });
      }

      goic.toestanden.forEach((tb) => {
        if (!tb.tb_data || typeof tb.tb_data !== 'object' || Array.isArray(tb.tb_data)) {
          return;
        }

        Object.values(tb.tb_data).forEach((value) => {
          if (typeof value === 'string' && value.includes('/data/goic/') && !known.has(value)) {
            unknown.add(value);
          }

          if (Array.isArray(value)) {
            value.forEach((item) => {
              if (typeof item === 'string' && item.includes('/data/goic/') && !known.has(item)) {
                unknown.add(item);
              }
            });
          }
        });
      });
    });
  });

  return Array.from(unknown);
};

const apiUrl = (path: string) => {
  if (typeof window !== 'undefined') {
    return path;
  }

  return new URL(path, 'http://localhost').toString();
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

  buildGoicDisplayMap(props.dossiers);

  try {
    const unknownGoicUris = collectUnknownGoicUris(props.dossiers);
    if (unknownGoicUris.length > 0) {
      const response = await axios.post(apiUrl('/api/goic/displays'), { uris: unknownGoicUris });
      remoteGoicDisplayMap.value = {
        ...remoteGoicDisplayMap.value,
        ...(response.data.labels ?? {}),
      };
    }
  } catch (error) {
    console.error('Fout bij ophalen GOIC-weergaves:', error);
  }
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
          <div v-if="followMessage" class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm text-emerald-800 dark:border-emerald-500/40 dark:bg-emerald-900/30 dark:text-emerald-100">
            {{ followMessage }}
          </div>
          <div v-if="followError" class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-2 text-sm text-rose-800 dark:border-rose-500/40 dark:bg-rose-900/30 dark:text-rose-100">
            {{ followError }}
          </div>

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
                  <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="text-sm font-medium text-gray-800 dark:text-gray-200">
                      GOIC #{{ goic.id }}<span v-if="goicClassLabel(goic)"> · {{ goicClassLabel(goic) }}</span>
                    </div>
                    <Link
                      v-if="hasLinkedGoics(goic)"
                      :href="goLinksHref(goic)"
                      class="inline-flex items-center rounded-md border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-800 transition hover:bg-indigo-100 dark:border-indigo-300/30 dark:bg-indigo-900/30 dark:text-indigo-100 dark:hover:bg-indigo-900/50"
                    >
                      Andere GOIC's binnen GO ({{ linkedOthersCount(goic) }})
                    </Link>
                    <button
                      v-if="canFollowGoic(goic)"
                      type="button"
                      class="inline-flex items-center rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-amber-300/30 dark:bg-amber-900/30 dark:text-amber-100 dark:hover:bg-amber-900/50"
                      :disabled="followBusyGoicId === goic.id"
                      @click="followGoic(goic)"
                    >
                      {{ followBusyGoicId === goic.id ? 'Bezig...' : 'Volg deze GOIC' }}
                    </button>
                  </div>

                  <div v-if="visibleToestanden(goic).length || (goic.follow_info?.is_followed && goic.follow_info.source_state)" class="mt-3 space-y-2">
                    <div
                      v-if="goic.follow_info?.is_followed && goic.follow_info.source_state"
                      class="rounded-lg border border-indigo-200 bg-indigo-50/60 p-3 text-xs text-indigo-900 dark:border-indigo-400/30 dark:bg-indigo-900/20 dark:text-indigo-100"
                    >
                      <div class="font-semibold">
                        Gevolgde GOIC · Verwijst naar GOIC #{{ goic.follow_info.source_goic_id ?? '?' }}
                        <span v-if="goic.follow_info.source_case_id">in case #{{ goic.follow_info.source_case_id }}</span>
                      </div>
                      <div class="mt-2 text-[11px] opacity-90">Alleen raadplegen: deze toestandsbeschrijving komt uit de gekoppelde GOIC.</div>
                      <div class="mt-2 rounded border border-indigo-200 bg-white p-2 dark:border-indigo-400/30 dark:bg-gray-900">
                        <template
                          v-for="([key, value]) in tbEntries(goic.follow_info.source_state)"
                          :key="`follow-${goic.id}-${String(key)}`"
                        >
                          <div class="flex flex-wrap items-start gap-2">
                            <span class="min-w-[180px] font-medium">{{ fieldLabelFor(String(key)) || 'Onbekend veld' }}</span>
                            <span class="break-all">{{ formatValue(value) }}</span>
                          </div>
                        </template>
                      </div>
                    </div>

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
                            <span class="break-all text-gray-800 dark:text-gray-100">
                              {{ formatValue(value) }}
                            </span>
                          </div>
                        </template>
                      </div>
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
