<template>
  <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="mb-6 rounded-xl border border-amber-200/70 bg-amber-50 px-4 py-4 dark:border-amber-300/20 dark:bg-amber-900/20">
      <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-200">Wat wil je registreren</p>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          v-for="sjabloon in sjablonen"
          :key="sjabloon.sjabloon_uri"
          type="button"
          class="rounded-full border px-4 py-2 text-xs font-semibold transition"
          :class="selectedSjabloonUri === sjabloon.sjabloon_uri
            ? 'border-amber-600 bg-amber-600 text-white shadow-sm'
            : 'border-amber-200 bg-white text-amber-800 hover:bg-amber-100 dark:border-amber-300/30 dark:bg-gray-900 dark:text-amber-100 dark:hover:bg-amber-900/40'
          "
          @click="setPrimaryObjectByUri(sjabloon.sjabloon_uri)"
        >
          {{ sjabloon.label || shortId(sjabloon.sjabloon_uri) }}
        </button>
      </div>
    </div>

    <form @submit.prevent="submitForm" class="space-y-6">
      <div v-for="(object, index) in objects" :key="object.id" class="rounded-xl border border-gray-200 p-5 dark:border-gray-700">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Object {{ index + 1 }}</p>
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
              {{ object.sjabloonLabel || 'Onbekend sjabloon' }}
            </h3>
          </div>

          <div class="flex items-center gap-2">
            <button
              v-if="objects.length > 1"
              type="button"
              class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-2 text-xs font-medium text-gray-600 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
              @click="removeObject(index)"
            >
              Verwijderen
            </button>
          </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          <div v-for="veld in object.velden" :key="veld.property" class="flex flex-col gap-1">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
              {{ veld.label }}
            </label>
            <select
              v-if="veld.type === 'make'"
              v-model="object.formData[veld.property] as string"
              class="h-11 rounded-lg border border-gray-300 bg-white px-3 text-gray-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              required
            >
              <option disabled value="">Kies een merk</option>
              <option v-for="make in makes" :key="make.id" :value="make.uri">
                {{ make.name }}
              </option>
            </select>
            <div v-else-if="veld.type === 'multi-uri'" class="space-y-2">
              <div
                v-for="(value, idx) in (object.formData[veld.property] as string[])"
                :key="idx"
                class="flex items-center gap-2"
              >
                <input
                  v-model="(object.formData[veld.property] as string[])[idx]"
                  type="url"
                  placeholder="Plak de URI van het object"
                  class="h-11 flex-1 rounded-lg border border-gray-300 bg-white px-3 text-gray-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                  required
                >
                <button
                  v-if="(object.formData[veld.property] as string[]).length > 1"
                  type="button"
                  class="inline-flex items-center rounded-lg border border-gray-200 px-2 py-2 text-xs font-medium text-gray-600 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                  @click="removeMultiUriValue(object, veld.property, idx)"
                >
                  Verwijder
                </button>
              </div>
              <button
                type="button"
                class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-2 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                @click="addMultiUriValue(object, veld.property)"
              >
                Nog een toevoegen
              </button>
            </div>
            <div v-else-if="veld.type === 'file'" class="space-y-2">
              <input
                type="file"
                class="h-11 rounded-lg border border-gray-300 bg-white px-3 text-gray-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                @change="onFileSelected($event, object, veld)"
                required
              >
              <p v-if="object.fileUploads[veld.property]?.uploading" class="text-xs text-amber-700 dark:text-amber-200">
                Uploaden...
              </p>
              <p v-else-if="object.fileUploads[veld.property]?.uploadedUri" class="text-xs text-emerald-700 dark:text-emerald-300">
                Geüpload: {{ object.fileUploads[veld.property]?.fileName || 'bestand' }}
              </p>
              <p v-else-if="object.fileUploads[veld.property]?.error" class="text-xs text-red-600 dark:text-red-300">
                Upload mislukt: {{ object.fileUploads[veld.property]?.error }}
              </p>
            </div>
            <textarea
              v-else-if="veld.type === 'textarea'"
              v-model="object.formData[veld.property] as string"
              rows="4"
              class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              required
            ></textarea>
            <input
              v-else
              v-model="object.formData[veld.property] as string"
              :type="veld.type"
              class="h-11 rounded-lg border border-gray-300 bg-white px-3 text-gray-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              required
            >
          </div>
        </div>

      </div>

      <div v-if="roleGroups.length" class="rounded-xl border border-amber-200/70 bg-amber-50 px-4 py-4 text-sm text-amber-900 dark:border-amber-300/20 dark:bg-amber-900/20 dark:text-amber-100">
        <div class="mb-3">
          <p class="text-sm font-semibold">Rollen</p>
          <p class="text-xs text-amber-700/80 dark:text-amber-100/80">Kies bestaande objecten uit het dossier.</p>
        </div>

        <div v-for="role in roleGroups" :key="role.tb_class" class="space-y-2">
          <p class="text-sm font-semibold">{{ role.label || shortId(role.tb_class) }}</p>
          <p v-if="!canAddRole(role)" class="text-xs text-amber-700/80 dark:text-amber-100/80">
            {{ roleHint(role) }}
          </p>

          <div v-for="(selection, rIndex) in getRoleSelections(role.tb_class)" :key="`${role.tb_class}-${rIndex}`" class="flex flex-wrap items-center gap-2">
            <select
              v-model="selection.fromGoicId"
              :disabled="getGoicsForClass(role.van_class ?? null).length === 0"
              class="h-10 rounded-lg border border-amber-200 bg-white px-3 text-sm text-amber-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-amber-300/30 dark:bg-gray-900 dark:text-amber-100"
            >
              <option disabled value="">Kies {{ shortId(role.van_class ?? 'object') }}</option>
              <option v-for="goic in getGoicsForClass(role.van_class ?? null)" :key="goic.id" :value="String(goic.id)">
                {{ getGoicDisplayName(goic) }}
              </option>
            </select>
            <select
              v-model="selection.toGoicId"
              :disabled="getGoicsForClass(role.naar_class ?? null).length === 0"
              class="h-10 rounded-lg border border-amber-200 bg-white px-3 text-sm text-amber-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-amber-300/30 dark:bg-gray-900 dark:text-amber-100"
            >
              <option disabled value="">Kies {{ shortId(role.naar_class ?? 'object') }}</option>
              <option v-for="goic in getGoicsForClass(role.naar_class ?? null)" :key="goic.id" :value="String(goic.id)">
                {{ getGoicDisplayName(goic) }}
              </option>
            </select>
            <button
              type="button"
              class="rounded-lg border border-amber-200 px-2 py-2 text-xs font-medium text-amber-800 transition hover:bg-amber-100 dark:border-amber-300/30 dark:text-amber-100 dark:hover:bg-amber-900/40"
              @click="removeRoleSelection(role.tb_class, rIndex)"
            >
              Verwijder
            </button>
          </div>

          <button
            type="button"
            class="rounded-lg border border-amber-200 px-3 py-2 text-xs font-medium text-amber-800 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-amber-300/30 dark:text-amber-100 dark:hover:bg-amber-900/40"
            :disabled="!canAddRole(role)"
            @click="addRoleSelection(role)"
          >
            Rol toevoegen
          </button>
        </div>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3">
        <button
          type="button"
          class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
          @click="addObject"
        >
          Extra object toevoegen
        </button>

        <div class="flex flex-wrap items-center gap-3">
          <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
            <input
              v-model="addToDossier"
              type="checkbox"
              class="h-4 w-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500"
            />
            Toevoegen aan dossier
          </label>
          <button
            type="submit"
            class="inline-flex items-center justify-center rounded-lg bg-amber-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500/50 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="!addToDossier"
          >
            Opslaan naar Dossier
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue';
import axios from 'axios';

interface Veld {
  label: string;
  property: string;
  type: string;
  volgorde: number;
}

interface SjabloonSummary {
  sjabloon_uri: string;
  label: string | null;
  target_class: string | null;
  volgorde?: number;
}

interface SjabloonResponse {
  sjabloon_uri: string;
  sjabloon_label: string | null;
  target_class: string | null;
  velden: Veld[];
}

interface IdentifierItem {
  tb_class: string;
  described_class: string;
  properties: string[];
}

interface ToestandItem {
  mutatie_id: number;
  sjabloon_uri: string;
  tb_id: number | null;
  tb_rdf_uri: string | null;
  tb_class: string | null;
  tb_data: Record<string, unknown> | string | null;
  created_at: string;
}

interface GoicItem {
  id: number;
  rdf_uri: string;
  dossier_id: number;
  created_at: string;
  toestanden: ToestandItem[];
}

interface DossierItem {
  id: number;
  naam: string;
  rdf_uri: string;
  parent_id: number | null;
  created_at: string;
  goics: GoicItem[];
}

interface AllowedRole {
  tb_class: string;
  label: string | null;
  van_class: string | null;
  naar_class: string | null;
  volgorde?: number;
}

interface FileUploadState {
  fileName: string | null;
  uploading: boolean;
  error: string | null;
  uploadedUri: string | null;
}

interface ObjectBlock {
  id: number;
  clientId: string;
  sjabloonUri: string;
  sjabloonLabel: string | null;
  targetClass: string | null;
  velden: Veld[];
  formData: Record<string, string | string[]>;
  dataTypes: Record<string, 'literal' | 'uri'>;
  fileUploads: Record<string, FileUploadState>;
}

const props = defineProps<{
  transactieSoortId: number;
  caseId: number;
  dossiers?: DossierItem[];
}>();
const emit = defineEmits<{
  (e: 'saved'): void;
}>();

const transactieNaam = ref('Laden...');
const sjablonen = ref<SjabloonSummary[]>([]);
const allowedRoles = ref<AllowedRole[]>([]);
const objects = ref<ObjectBlock[]>([]);
const selectedSjabloonUri = ref<string | null>(null);
const addToDossier = ref(true);
const makes = ref<{ id: number; name: string; uri: string }[]>([]);
const identifierMap = ref<Record<string, { describedClass: string; properties: string[] }>>({});
let objectCounter = 1;
let clientCounter = 1;
type RoleSelection = { fromGoicId: string; toGoicId: string };
const roleSelections = ref<Record<string, RoleSelection[]>>({});

const loadIdentifiers = async () => {
  try {
    const response = await axios.get('/api/identifiers');
    const rows = (response.data.identifiers ?? []) as IdentifierItem[];
    const map: Record<string, { describedClass: string; properties: string[] }> = {};
    rows.forEach((row) => {
      if (!row.tb_class || !row.described_class) return;
      map[row.tb_class] = {
        describedClass: row.described_class,
        properties: row.properties ?? [],
      };
    });
    identifierMap.value = map;
  } catch (error) {
    console.error('Fout bij ophalen identifiers:', error);
    identifierMap.value = {};
  }
};

const initObject = (sjabloon: SjabloonResponse): ObjectBlock => {
  const formData: Record<string, string | string[]> = {};
  const dataTypes: Record<string, 'literal' | 'uri'> = {};
  const fileUploads: Record<string, FileUploadState> = {};
  sjabloon.velden.forEach(veld => {
    if (veld.type === 'multi-uri') {
      formData[veld.property] = [''];
      dataTypes[veld.property] = 'uri';
    } else if (veld.type === 'file') {
      formData[veld.property] = '';
      dataTypes[veld.property] = 'uri';
      fileUploads[veld.property] = {
        fileName: null,
        uploading: false,
        error: null,
        uploadedUri: null,
      };
    } else if (veld.type === 'url') {
      formData[veld.property] = '';
      dataTypes[veld.property] = 'uri';
    } else {
      formData[veld.property] = '';
      dataTypes[veld.property] = veld.type === 'make' ? 'uri' : 'literal';
    }
  });

  return {
    id: objectCounter++,
    clientId: `obj_${clientCounter++}`,
    sjabloonUri: sjabloon.sjabloon_uri,
    sjabloonLabel: sjabloon.sjabloon_label,
    targetClass: sjabloon.target_class,
    velden: sjabloon.velden,
    formData,
    dataTypes,
    fileUploads,
  };
};

const loadSjabloonByUri = async (uri: string): Promise<SjabloonResponse | null> => {
  try {
    const response = await axios.get('/api/sjabloon/uri', { params: { uri } });
    return response.data as SjabloonResponse;
  } catch (error) {
    console.error('Fout bij ophalen sjabloon via uri:', error);
    return null;
  }
};

const addObject = async (preferredUri?: string) => {
  const fallback = objects.value[0];
  const targetUri = preferredUri ?? selectedSjabloonUri.value ?? sjablonen.value[0]?.sjabloon_uri ?? fallback?.sjabloonUri;

  if (!targetUri) return;

  const sjabloon = await loadSjabloonByUri(targetUri);
  if (!sjabloon) return;

  objects.value.push(initObject(sjabloon));
};

const setPrimaryObjectByUri = async (uri: string | null) => {
  if (!uri) return;
  const sjabloon = await loadSjabloonByUri(uri);
  if (!sjabloon) return;
  selectedSjabloonUri.value = uri;
  objects.value = [initObject(sjabloon)];
  roleSelections.value = {};
};

const removeObject = (index: number) => {
  objects.value.splice(index, 1);
};

const addMultiUriValue = (object: ObjectBlock, property: string) => {
  const current = object.formData[property];
  if (Array.isArray(current)) {
    current.push('');
  } else {
    object.formData[property] = [''];
  }
};

const removeMultiUriValue = (object: ObjectBlock, property: string, index: number) => {
  const current = object.formData[property];
  if (!Array.isArray(current)) return;
  current.splice(index, 1);
  if (current.length === 0) {
    current.push('');
  }
};

const uploadFile = async (object: ObjectBlock, veld: Veld, file: File) => {
  const state = object.fileUploads[veld.property] ?? {
    fileName: null,
    uploading: false,
    error: null,
    uploadedUri: null,
  };
  state.fileName = file.name || 'bestand';
  state.uploading = true;
  state.error = null;
  state.uploadedUri = null;
  object.formData[veld.property] = '';
  object.fileUploads[veld.property] = state;

  const formData = new FormData();
  formData.append('file', file);

  try {
    const response = await axios.post('/api/bestand/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    const bestandUri = response.data?.bestand_uri as string | undefined;
    if (!bestandUri) {
      throw new Error('Geen bestand URI ontvangen');
    }

    object.formData[veld.property] = bestandUri;
    object.dataTypes[veld.property] = 'uri';
    state.uploadedUri = bestandUri;
    state.uploading = false;
  } catch (error) {
    state.uploading = false;
    state.error = 'Kon bestand niet uploaden';
    object.formData[veld.property] = '';
    console.error('Upload fout:', error);
  }
};

const onFileSelected = (event: Event, object: ObjectBlock, veld: Veld) => {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  uploadFile(object, veld, file);
};

const savedGoics = computed<GoicItem[]>(() => {
  const dossiers = props.dossiers ?? [];
  return dossiers.flatMap((dossier) => dossier.goics ?? []);
});

const shortId = (uri: string) => {
  const trimmed = uri.endsWith('/') ? uri.slice(0, -1) : uri;
  if (trimmed.includes('#')) {
    const parts = trimmed.split('#');
    return parts[parts.length - 1] ?? uri;
  }
  const parts = trimmed.split('/');
  return parts[parts.length - 1] ?? uri;
};

const getLatestTb = (goic: GoicItem) => {
  const reversed = [...(goic.toestanden ?? [])].reverse();
  const preferred = reversed.find((tb) => !!tb.tb_class && !!identifierMap.value[tb.tb_class]);
  return preferred ?? reversed.find((tb) => !!tb.tb_class) ?? null;
};

const getGoicClassUri = (goic: GoicItem) => {
  const tb = getLatestTb(goic);
  if (!tb?.tb_class) return null;
  const config = identifierMap.value[tb.tb_class];
  return config?.describedClass ?? null;
};

const getGoicDisplayName = (goic: GoicItem) => {
  const tb = getLatestTb(goic);
  if (!tb?.tb_class) return `GOIC ${goic.id}`;
  const config = identifierMap.value[tb.tb_class];
  const classUri = config?.describedClass ?? tb.tb_class;
  const classLabel = shortId(classUri);

  if (config && tb.tb_data && typeof tb.tb_data === 'object' && !Array.isArray(tb.tb_data)) {
    const values: string[] = [];
    config.properties.forEach((prop) => {
      const raw = (tb.tb_data as Record<string, unknown>)[prop];
      if (raw === null || raw === undefined || raw === '') return;
      values.push(String(raw));
    });
    if (values.length) {
      return `${classLabel}: ${values.join(', ')}`;
    }
  }

  return classLabel;
};

const goicsByClass = computed<Record<string, GoicItem[]>>(() => {
  const map: Record<string, GoicItem[]> = {};
  savedGoics.value.forEach((goic) => {
    const classUri = getGoicClassUri(goic);
    if (!classUri) return;
    if (!map[classUri]) {
      map[classUri] = [];
    }
    map[classUri].push(goic);
  });
  return map;
});

const getGoicsForClass = (classUri: string | null) => {
  if (!classUri) return [];
  return goicsByClass.value[classUri] ?? [];
};

const roleGroups = computed(() => {
  return [...allowedRoles.value].sort((a, b) => (a.volgorde ?? 0) - (b.volgorde ?? 0));
});

const getRoleSelections = (tbClass: string) => roleSelections.value[tbClass] ?? [];

const addRoleSelection = (role: AllowedRole) => {
  if (!role.tb_class) return;
  if (!roleSelections.value[role.tb_class]) {
    roleSelections.value[role.tb_class] = [];
  }
  const toOptions = getGoicsForClass(role.naar_class ?? null);
  roleSelections.value[role.tb_class].push({
    fromGoicId: '',
    toGoicId: toOptions.length === 1 ? String(toOptions[0].id) : '',
  });
};

const removeRoleSelection = (tbClass: string, index: number) => {
  if (!roleSelections.value[tbClass]) return;
  roleSelections.value[tbClass].splice(index, 1);
};

const canAddRole = (role: AllowedRole) => {
  const fromOptions = getGoicsForClass(role.van_class ?? null);
  const toOptions = getGoicsForClass(role.naar_class ?? null);
  return fromOptions.length > 0 && toOptions.length > 0;
};

const roleHint = (role: AllowedRole) => {
  const fromLabel = role.van_class ? shortId(role.van_class) : 'object';
  const toLabel = role.naar_class ? shortId(role.naar_class) : 'object';
  return `Registreer eerst een ${fromLabel} en ${toLabel} in het dossier, daarna kun je hier een rol kiezen.`;
};

const loadForTransactie = async () => {
  try {
    await loadIdentifiers();
    const response = await axios.get(`/api/sjabloon/${props.transactieSoortId}`);
    const sjabloon = response.data as SjabloonResponse & {
      transactie_naam: string;
      allowed_sjablonen?: SjabloonSummary[];
      allowed_roles?: AllowedRole[];
    };
    transactieNaam.value = sjabloon.transactie_naam;
    roleSelections.value = {};
    allowedRoles.value = sjabloon.allowed_roles ?? [];

    if (sjabloon.allowed_sjablonen && sjabloon.allowed_sjablonen.length) {
      sjablonen.value = sjabloon.allowed_sjablonen;
    } else {
      sjablonen.value = [
        {
          sjabloon_uri: sjabloon.sjabloon_uri,
          label: sjabloon.sjabloon_label,
          target_class: sjabloon.target_class,
          volgorde: 1,
        },
      ];
    }

    const makeResponse = await axios.get('/api/merken');
    makes.value = makeResponse.data.makes ?? [];

    const ordered = [...sjablonen.value].sort((a, b) => (a.volgorde ?? 1) - (b.volgorde ?? 1));
    const primary = ordered[0];
    if (primary) {
      await setPrimaryObjectByUri(primary.sjabloon_uri);
    } else {
      objects.value = [initObject(sjabloon)];
    }
  } catch (error) {
    console.error("Fout bij ophalen sjabloon:", error);
  }
};

onMounted(loadForTransactie);
watch(() => props.transactieSoortId, () => {
  loadForTransactie();
});

const submitForm = async () => {
  if (!addToDossier.value) {
    return;
  }
  const hasPendingUploads = objects.value.some((object) =>
    Object.values(object.fileUploads).some((state) => state.uploading)
  );
  if (hasPendingUploads) {
    alert('Wacht tot alle uploads klaar zijn.');
    return;
  }

  const hasMissingUploads = objects.value.some((object) =>
    Object.keys(object.fileUploads).some((property) => !object.formData[property])
  );
  if (hasMissingUploads) {
    alert('Upload een bestand voor alle verplichte foto-velden.');
    return;
  }

  try {
    const roleItems = [];
    roleGroups.value.forEach((role) => {
      const selections = getRoleSelections(role.tb_class);
      const toOptions = getGoicsForClass(role.naar_class ?? null);
      const defaultTo = toOptions.length === 1 ? String(toOptions[0].id) : '';

      selections.forEach((selection) => {
        const fromId = selection.fromGoicId;
        const toId = selection.toGoicId || defaultTo;
        if (!fromId) return;
        if (!toId) return;
        roleItems.push({
          roleTbClass: role.tb_class,
          fromGoicId: Number(fromId),
          toGoicId: Number(toId),
        });
      });
    });

    const payload = {
      transactie_soort_id: props.transactieSoortId,
      case_id: props.caseId,
      objects: objects.value.map(object => ({
        client_id: object.clientId,
        sjabloon_uri: object.sjabloonUri,
        target_class: object.targetClass,
        data: object.formData,
        data_types: object.dataTypes,
      })),
      roles: {
        items: roleItems,
      },
    };
    
    const response = await axios.post('/api/mutatie', payload);
    alert('Succes! Object aangemaakt.');
    console.log('Response:', response.data);
    emit('saved');
  } catch (error: unknown) {
    const axiosError = error as {
      response?: { data?: { error?: string; report?: string } };
    };
    const apiError = axiosError.response?.data?.error;

    console.error('Fout bij opslaan:', error);
    if (axiosError.response?.data?.report) {
      console.error('SHACL report:', axiosError.response.data.report);
    }
    alert(apiError ? `Opslaan mislukt: ${apiError}` : 'Opslaan mislukt. Zie console voor details.');
  }
};
</script>
