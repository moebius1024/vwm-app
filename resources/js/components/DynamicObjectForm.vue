<template>
  <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div
      v-if="activeMutationTarget"
      class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-300/30 dark:bg-amber-900/20 dark:text-amber-100"
    >
      <span class="font-semibold">Mutatiemodus: {{ shortId(activeMutationTarget.sjabloon_uri) }}</span>
      <button
        type="button"
        class="rounded-full border border-amber-200 bg-white px-3 py-1 font-semibold text-amber-800 transition hover:bg-amber-100 dark:border-amber-300/30 dark:bg-gray-900 dark:text-amber-100 dark:hover:bg-amber-900/40"
        @click="emit('cancel-mutate')"
      >
        Muteren annuleren
      </button>
    </div>

    <div class="mb-6 rounded-xl border border-amber-200/70 bg-amber-50 px-4 py-4 dark:border-amber-300/20 dark:bg-amber-900/20">
      <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-200">Registreren</p>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          v-for="sjabloon in registratieSjablonen"
          :key="sjabloon.buttonKey"
          type="button"
          class="rounded-full border px-4 py-2 text-xs font-semibold transition"
          :disabled="!!activeMutationTarget"
          :class="isSjabloonSelected(sjabloon)
            ? 'border-amber-600 bg-amber-600 text-white shadow-sm'
            : 'border-amber-200 bg-white text-amber-800 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-amber-300/30 dark:bg-gray-900 dark:text-amber-100 dark:hover:bg-amber-900/40'
          "
          @click="selectRegistratieSjabloon(sjabloon)"
        >
          {{ sjabloon.buttonLabel || sjabloon.label || shortId(sjabloon.sjabloon_uri) }}
        </button>
      </div>
    </div>
    <div :id="registerAnchorId"></div>

    <div v-if="toevoegSjablonen.length" class="mb-6 rounded-xl border border-amber-200/70 bg-amber-50 px-4 py-4 text-sm text-amber-900 dark:border-amber-300/20 dark:bg-amber-900/20 dark:text-amber-100">
      <div class="mb-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-200">Toevoegen</p>
      </div>

      <div class="flex flex-wrap gap-2">
        <button
          v-for="sjabloon in toevoegSjablonen"
          :key="sjabloon.buttonKey"
          type="button"
          class="rounded-full border px-4 py-2 text-xs font-semibold transition"
          :disabled="!!activeMutationTarget"
          :class="isSjabloonSelected(sjabloon)
            ? 'border-amber-600 bg-amber-600 text-white shadow-sm'
            : 'border-amber-200 bg-white text-amber-800 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-amber-300/30 dark:bg-gray-900 dark:text-amber-100 dark:hover:bg-amber-900/40'
          "
          @click="selectToevoegSjabloon(sjabloon)"
        >
          {{ sjabloon.buttonLabel || sjabloon.label || shortId(sjabloon.sjabloon_uri) }}
        </button>
      </div>
    </div>
    <div :id="toevoegAnchorId"></div>

    <Teleport :to="activeObjectAnchorSelector" :disabled="!activeObjectAnchorSelector">
      <div v-if="showObjectEditor" class="mb-6 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <div v-for="(object, index) in objects" :key="object.id" class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
          <div class="mb-1 flex flex-wrap items-center justify-between gap-3">
            <div>
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

          <div
            v-if="needsExistingGoicSelection(object)"
            class="mb-4 rounded-lg border border-amber-200/70 bg-amber-50 px-3 py-3 dark:border-amber-300/30 dark:bg-amber-900/20"
          >
            <label class="mb-1 block text-sm font-medium text-amber-900 dark:text-amber-100">
              Bestaand {{ shortId(object.targetClass || 'object') }}
            </label>

            <template v-if="getExistingGoicOptionsForObject(object).length === 1">
              <input
                type="text"
                class="h-10 w-full rounded-lg border border-amber-200 bg-white px-3 text-sm text-amber-900 shadow-sm outline-none dark:border-amber-300/30 dark:bg-gray-900 dark:text-amber-100"
                :value="getGoicDisplayName(getExistingGoicOptionsForObject(object)[0])"
                disabled
              >
              <p class="mt-1 text-xs text-amber-700/80 dark:text-amber-100/80">Automatisch gekoppeld (er is maar 1 optie).</p>
            </template>

            <template v-else-if="getExistingGoicOptionsForObject(object).length > 1">
              <select
                v-model="object.existingGoicId"
                class="h-10 w-full rounded-lg border border-amber-200 bg-white px-3 text-sm text-amber-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-amber-300/30 dark:bg-gray-900 dark:text-amber-100"
                required
              >
                <option disabled value="">Kies {{ shortId(object.targetClass || 'object') }}</option>
                <option v-for="goic in getExistingGoicOptionsForObject(object)" :key="goic.id" :value="String(goic.id)">
                  {{ getGoicDisplayName(goic) }}
                </option>
              </select>
            </template>

            <p v-else class="text-xs text-amber-700/80 dark:text-amber-100/80">
              Geen bestaand {{ shortId(object.targetClass || 'object') }} gevonden in dit dossier.
            </p>
          </div>

          <div class="grid gap-4 md:grid-cols-2">
            <div v-for="veld in object.velden" :key="veld.property" class="flex flex-col gap-0.5">
              <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
                {{ veld.label }}
              </label>
              <div v-if="veld.type === 'multi-uri'" class="space-y-2">
                <div
                  v-for="(value, idx) in (object.formData[veld.property] as string[])"
                  :key="idx"
                  class="flex items-center gap-2"
                >
                  <input
                    v-model="(object.formData[veld.property] as string[])[idx]"
                    type="url"
                    placeholder="Plak de URI van het object"
                    :data-field-key="fieldErrorKey(object, veld.property)"
                    class="h-10 flex-1 rounded-lg border border-gray-300 bg-white px-3 text-gray-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                    :required="veld.required"
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
                  :data-field-key="fieldErrorKey(object, veld.property)"
                  class="h-10 w-full min-w-0 overflow-hidden rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-900 shadow-sm outline-none transition file:mr-2 file:max-w-full file:overflow-hidden file:text-ellipsis file:whitespace-nowrap focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                  @change="onFileSelected($event, object, veld)"
                  :required="veld.required"
                  :disabled="isFileFieldLockedForMutation(object)"
                >
                <p v-if="isFileFieldLockedForMutation(object)" class="text-xs text-amber-700 dark:text-amber-200">
                  Bij muteren blijft hetzelfde bestand gekoppeld.
                </p>
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
                :data-field-key="fieldErrorKey(object, veld.property)"
                class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                :required="veld.required"
              ></textarea>
              <select
                v-else-if="isGoicLookupField(veld)"
                v-model="object.formData[veld.property] as string"
                :data-field-key="fieldErrorKey(object, veld.property)"
                class="h-10 rounded-lg border border-gray-300 bg-white px-3 text-gray-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                :class="fieldWidthClass(veld)"
                :required="veld.required"
                @change="delete fieldErrors[fieldErrorKey(object, veld.property)]"
              >
                <option value="" disabled>Kies...</option>
                <option
                  v-for="goic in getGoicsForLookupField(veld)"
                  :key="`lookup-goic-${object.id}-${veld.property}-${goic.id}`"
                  :value="goic.rdf_uri"
                >
                  {{ getGoicDisplayName(goic) }}
                </option>
              </select>
              <select
                v-else-if="Array.isArray(veld.options) && veld.options.length > 0"
                v-model="object.formData[veld.property] as string"
                :data-field-key="fieldErrorKey(object, veld.property)"
                class="h-10 rounded-lg border border-gray-300 bg-white px-3 text-gray-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                :class="fieldWidthClass(veld)"
                :required="veld.required"
                @change="onFieldInput(object, veld)"
              >
                <option value="" disabled>Kies...</option>
                <option v-for="option in veld.options" :key="`${veld.property}-${option}`" :value="option">
                  {{ option }}
                </option>
              </select>
              <input
                v-else
                v-model="object.formData[veld.property] as string"
                :type="veld.type"
                :list="fieldLookupListId(object, veld)"
                :data-field-key="fieldErrorKey(object, veld.property)"
                class="h-10 rounded-lg border border-gray-300 bg-white px-3 text-gray-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                :class="[fieldWidthClass(veld), veld.type === 'text' ? 'text-xs' : '']"
                @input="onFieldInput(object, veld)"
                @blur="onFieldBlur(object, veld)"
                :required="veld.required"
              >
              <datalist
                v-if="fieldLookupListId(object, veld)"
                :id="fieldLookupListId(object, veld)!"
              >
                <option
                  v-for="option in getFieldLookupOptions(object, veld)"
                  :key="`${fieldLookupListId(object, veld)}-${option}`"
                  :value="option"
                />
              </datalist>
              <p v-if="!activeRoleForSelection && fieldErrors[fieldErrorKey(object, veld.property)]" class="text-xs text-red-600 dark:text-red-300">
                {{ fieldErrors[fieldErrorKey(object, veld.property)] }}
              </p>
            </div>
          </div>

        </div>
      </div>
    </Teleport>

    <form @submit.prevent="submitForm" novalidate class="space-y-6">
      <div v-if="roleGroups.length && !activeMutationTarget" class="rounded-xl border border-amber-200/70 bg-amber-50 px-4 py-4 text-sm text-amber-900 dark:border-amber-300/20 dark:bg-amber-900/20 dark:text-amber-100">
        <div class="mb-3">
          <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-200">Rollen</p>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            v-for="role in roleGroups"
            :key="`role-btn-${role.tb_class}`"
            type="button"
            class="rounded-full border px-4 py-2 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60"
            :class="isRoleSelected(role.tb_class)
              ? 'border-amber-600 bg-amber-600 text-white shadow-sm'
              : 'border-amber-200 bg-white text-amber-800 hover:bg-amber-100 dark:border-amber-300/30 dark:bg-gray-900 dark:text-amber-100 dark:hover:bg-amber-900/40'"
            :disabled="!canAddRole(role)"
            @click.prevent="addRoleSelection(role)"
          >
            {{ roleButtonLabel(role) }}
          </button>
        </div>
        <p v-if="roleError" class="mt-2 text-xs text-red-600 dark:text-red-300">
          {{ roleError }}
        </p>
        <p v-if="!activeRoleForSelection" class="mt-2 text-xs text-amber-700/80 dark:text-amber-100/80">
          Kies een roltype om de rolregel te tonen.
        </p>
      </div>

      <div
        v-if="activeRoleForSelection && activeRoleSelection"
        class="rounded-xl border border-gray-200 bg-white px-4 py-4 dark:border-gray-700 dark:bg-gray-900"
      >
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
          {{ roleButtonLabel(activeRoleForSelection) }}
        </p>
        <div class="flex flex-wrap items-center gap-2">
          <select
            v-model="activeRoleSelection.fromGoicId"
            :disabled="getGoicsForClass(activeRoleForSelection.van_class ?? null).length === 0"
            class="h-10 rounded-lg border border-amber-200 bg-white px-3 text-sm text-amber-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-amber-300/30 dark:bg-gray-900 dark:text-amber-100"
            @change="roleError = ''"
          >
            <option disabled value="">Kies {{ shortId(activeRoleForSelection.van_class ?? 'object') }}</option>
            <option v-for="goic in getGoicsForClass(activeRoleForSelection.van_class ?? null)" :key="goic.id" :value="String(goic.id)">
              {{ getGoicDisplayName(goic) }}
            </option>
          </select>
          <select
            v-model="activeRoleSelection.toGoicId"
            :disabled="getGoicsForClass(activeRoleForSelection.naar_class ?? null).length === 0"
            class="h-10 rounded-lg border border-amber-200 bg-white px-3 text-sm text-amber-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-amber-300/30 dark:bg-gray-900 dark:text-amber-100"
            @change="roleError = ''"
          >
            <option disabled value="">Kies {{ shortId(activeRoleForSelection.naar_class ?? 'object') }}</option>
            <option v-for="goic in getGoicsForClass(activeRoleForSelection.naar_class ?? null)" :key="goic.id" :value="String(goic.id)">
              {{ getGoicDisplayName(goic) }}
            </option>
          </select>
        </div>
      </div>

      <div class="flex flex-wrap items-center justify-end gap-3">
        <button
          v-if="activeMutationTarget"
          type="button"
          class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-xs font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-300/50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
          @click="emit('cancel-mutate')"
        >
          Annuleren
        </button>
        <button
          type="submit"
          class="inline-flex items-center justify-center rounded-lg bg-amber-600 px-5 py-2.5 text-xs font-semibold text-white shadow-sm transition hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500/50 disabled:cursor-not-allowed disabled:opacity-50"
        >
          Opslaan naar Dossier
        </button>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
import axios from 'axios';
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';

interface Veld {
  label: string;
  property: string;
  type: string;
  volgorde: number;
  required: boolean;
  field_width?: string | null;
  options?: string[];
  lookup?: {
    endpoint?: string | null;
    query_param?: string | null;
    source_field?: string | null;
    trigger?: string | null;
    debounce_ms?: number | null;
    min_length?: number | null;
    class_uri?: string | null;
  } | null;
}

interface SjabloonSummary {
  sjabloon_uri: string;
  label: string | null;
  target_class: string | null;
  volgorde?: number;
  crud_flags?: string | null;
  button_label_register?: string | null;
  button_label_attach?: string | null;
}

interface SelectableSjabloon extends SjabloonSummary {
  buttonKey: string;
  buttonLabel?: string | null;
  selection_mode: 'default' | 'attach-existing';
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
  crud_flags?: string | null;
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
  existingGoicId: string;
  attachToExisting: boolean;
}

const props = defineProps<{
  transactieSoortId: number;
  caseId: number;
  dossiers?: DossierItem[];
  mutationTarget?: {
    goic_id: number;
    mutatie_id: number;
    sjabloon_uri: string;
    tb_rdf_uri: string | null;
    tb_class: string | null;
    tb_data: Record<string, unknown> | string | null;
  } | null;
}>();
const emit = defineEmits<{
  (e: 'saved'): void;
  (e: 'cancel-mutate'): void;
}>();

const transactieNaam = ref('Laden...');
const sjablonen = ref<SjabloonSummary[]>([]);
const allowedRoles = ref<AllowedRole[]>([]);
const objects = ref<ObjectBlock[]>([]);
const selectedSjabloonUri = ref<string | null>(null);
const identifierMap = ref<Record<string, { describedClass: string; properties: string[] }>>({});
const describedClassByTbClass = ref<Record<string, string>>({});
const labelMap = ref<Record<string, string>>({});
let objectCounter = 1;
let clientCounter = 1;
type RoleSelection = { fromGoicId: string; toGoicId: string };
type RolePayloadItem = { roleTbClass: string; fromGoicId: number; toGoicId: number };
const roleSelections = ref<Record<string, RoleSelection[]>>({});
const fieldErrors = ref<Record<string, string>>({});
const roleError = ref<string>('');
const fieldLookupOptions = ref<Record<string, string[]>>({});
const kentekenLookupTimers = new Map<string, ReturnType<typeof setTimeout>>();
const kentekenLastLookup = new Map<string, string>();
const KENTEKEN_LOOKUP_DEBOUNCE_MS = 500;
const activeMutationTarget = ref<NonNullable<typeof props.mutationTarget> | null>(null);
const selectedSjabloonSelectionMode = ref<'default' | 'attach-existing'>('default');
const selectedSjabloonGroupOverride = ref<'register' | 'toevoeg' | null>(null);

const fieldErrorKey = (object: ObjectBlock, property: string) => `${object.id}::${property}`;
const hasCrud = (flags: string | null | undefined, letter: 'C' | 'R' | 'U' | 'D' | 'A') =>
  String(flags ?? '').toUpperCase().includes(letter);

const isToestandsWeergaveSjabloon = (sjabloon: SjabloonSummary) => {
  const uri = typeof sjabloon.sjabloon_uri === 'string' ? sjabloon.sjabloon_uri : '';
  const targetClass = typeof sjabloon.target_class === 'string' ? sjabloon.target_class : '';

  return uri.includes('ToestandsWeergave') || targetClass.includes('ToestandsWeergave');
};

const hasAttachCrud = (sjabloon: SjabloonSummary) => hasCrud(sjabloon.crud_flags, 'A');
const isLegacyToevoegSjabloon = (sjabloon: SjabloonSummary) => isToestandsWeergaveSjabloon(sjabloon);

const orderedSjablonen = computed(() =>
  [...sjablonen.value].sort((a, b) => (a.volgorde ?? 1) - (b.volgorde ?? 1))
);

const registratieSjablonen = computed<SelectableSjabloon[]>(() =>
  orderedSjablonen.value
    .filter((sjabloon) => hasCrud(sjabloon.crud_flags, 'C') && !isLegacyToevoegSjabloon(sjabloon))
    .map((sjabloon) => ({
      ...sjabloon,
      buttonKey: `${sjabloon.sjabloon_uri}::register`,
      buttonLabel: sjabloon.button_label_register ?? sjabloon.label,
      selection_mode: 'default',
    }))
);

const toevoegSjablonen = computed<SelectableSjabloon[]>(() => {
  const items: SelectableSjabloon[] = [];

  orderedSjablonen.value.forEach((sjabloon) => {
    if (hasAttachCrud(sjabloon)) {
      items.push({
        ...sjabloon,
        buttonKey: `${sjabloon.sjabloon_uri}::toevoeg-attach`,
        buttonLabel: sjabloon.button_label_attach ?? sjabloon.label,
        selection_mode: 'attach-existing',
      });

      return;
    }

    if (isLegacyToevoegSjabloon(sjabloon)) {
      items.push({
        ...sjabloon,
        buttonKey: `${sjabloon.sjabloon_uri}::toevoeg`,
        buttonLabel: sjabloon.button_label_attach ?? sjabloon.label,
        selection_mode: 'default',
      });
    }
  });

  return items;
});

const registerAnchorId = computed(() => `register-anchor-${props.caseId}`);
const toevoegAnchorId = computed(() => `toevoeg-anchor-${props.caseId}`);

const selectedSjabloonGroup = computed<'register' | 'toevoeg' | null>(() => {
  if (selectedSjabloonGroupOverride.value) {
    return selectedSjabloonGroupOverride.value;
  }

  const selectedUri = selectedSjabloonUri.value;

  if (!selectedUri) {
    return null;
  }

  const selected = sjablonen.value.find((item) => item.sjabloon_uri === selectedUri);

  if (!selected) {
    return null;
  }

  return isToestandsWeergaveSjabloon(selected) ? 'toevoeg' : 'register';
});

const isSjabloonSelected = (sjabloon: SelectableSjabloon) => {
  return selectedSjabloonUri.value === sjabloon.sjabloon_uri
    && selectedSjabloonSelectionMode.value === sjabloon.selection_mode;
};

const activeObjectAnchorSelector = computed(() => {
  if (selectedSjabloonGroup.value === 'register') {
    return `#${registerAnchorId.value}`;
  }

  if (selectedSjabloonGroup.value === 'toevoeg') {
    return `#${toevoegAnchorId.value}`;
  }

  return null;
});

const showObjectEditor = computed(() => {
  return !activeRoleForSelection.value && selectedSjabloonGroup.value !== null && objects.value.length > 0;
});

const clearValidationUi = () => {
  fieldErrors.value = {};
  roleError.value = '';
};

const setFieldValue = (object: ObjectBlock, property: string, value: unknown) => {
  if (value === null || value === undefined) {
return;
}

  const stringValue = String(value).trim();

  if (stringValue === '') {
return;
}

  if (!(property in object.formData)) {
return;
}

  if (Array.isArray(object.formData[property])) {
return;
}

  object.formData[property] = stringValue;
};

const applyLookupRecordToObject = (object: ObjectBlock, endpoint: string, record: Record<string, unknown>) => {
  object.velden.forEach((veld) => {
    const lookup = veld.lookup;

    if (!lookup || lookup.endpoint !== endpoint) {
      return;
    }

    const sourceField = typeof lookup.source_field === 'string' ? lookup.source_field : '';

    if (sourceField === '') {
      return;
    }

    if (Object.prototype.hasOwnProperty.call(record, sourceField)) {
      setFieldValue(object, veld.property, record[sourceField]);
    }
  });
};

const updateFieldLookupOptions = (object: ObjectBlock, veld: Veld, options: unknown) => {
  const key = fieldErrorKey(object, veld.property);

  if (!Array.isArray(options)) {
    delete fieldLookupOptions.value[key];

    return;
  }

  const values = options
    .map((entry) => String(entry ?? '').trim())
    .filter((entry) => entry !== '');

  fieldLookupOptions.value[key] = Array.from(new Set(values));
};

const executeLookup = async (object: ObjectBlock, veld: Veld, endpoint: string, queryParam: string, rawValue: string) => {
  if (!endpoint || !queryParam) {
return false;
}

  try {
    const response = await axios.get(endpoint, {
      params: { [queryParam]: rawValue },
    });

    const found = response.data?.found === true;
    const record = response.data?.record;
    const options = response.data?.options;

    updateFieldLookupOptions(object, veld, options);

    if (found && record && typeof record === 'object') {
      applyLookupRecordToObject(object, endpoint, record as Record<string, unknown>);
    }

    return true;
  } catch (error) {
    console.warn('Lookup mislukt:', endpoint, queryParam, error);

    return false;
  }
};

const kentekenLookupKey = (object: ObjectBlock, veld: Veld) => `${object.clientId}:${veld.property}`;

const triggerMetadataLookup = async (object: ObjectBlock, veld: Veld, triggerType: 'input' | 'blur') => {
  const lookup = veld.lookup;

  if (!lookup || !lookup.endpoint || !lookup.query_param) {
    return;
  }

  const endpoint = lookup.endpoint;
  const queryParam = lookup.query_param;

  const configuredTrigger = (lookup.trigger ?? 'blur').toLowerCase();

  if (configuredTrigger !== triggerType) {
    return;
  }

  const key = kentekenLookupKey(object, veld);
  const existingTimer = kentekenLookupTimers.get(key);

  if (existingTimer) {
    clearTimeout(existingTimer);
    kentekenLookupTimers.delete(key);
  }

  const raw = object.formData[veld.property];

  if (Array.isArray(raw) || typeof raw !== 'string') {
    return;
  }

  const trimmed = raw.trim();
  const minLength = typeof lookup.min_length === 'number' && lookup.min_length > 0 ? lookup.min_length : 1;

  if (trimmed.length < minLength) {
    kentekenLastLookup.delete(key);

    return;
  }

  const performLookup = async () => {
    if (kentekenLastLookup.get(key) === trimmed) {
      return;
    }

    kentekenLastLookup.set(key, trimmed);
    const ok = await executeLookup(object, veld, endpoint, queryParam, trimmed);

    if (!ok) {
      kentekenLastLookup.delete(key);
    }
  };

  if (triggerType === 'blur') {
    await performLookup();

    return;
  }

  const debounceMs = typeof lookup.debounce_ms === 'number' && lookup.debounce_ms > 0
    ? lookup.debounce_ms
    : KENTEKEN_LOOKUP_DEBOUNCE_MS;
  const timer = setTimeout(() => {
    kentekenLookupTimers.delete(key);
    void performLookup();
  }, debounceMs);
  kentekenLookupTimers.set(key, timer);
};

const onFieldInput = (object: ObjectBlock, veld: Veld) => {
  delete fieldErrors.value[fieldErrorKey(object, veld.property)];
  void triggerMetadataLookup(object, veld, 'input');
};

const onFieldBlur = (object: ObjectBlock, veld: Veld) => {
  void triggerMetadataLookup(object, veld, 'blur');
};

const fieldLookupListId = (object: ObjectBlock, veld: Veld) => {
  if (veld.type !== 'text') {
    return undefined;
  }

  if (!veld.lookup?.endpoint) {
    return undefined;
  }

  return `lookup-${object.id}-${veld.property.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
};

const getFieldLookupOptions = (object: ObjectBlock, veld: Veld) => {
  const key = fieldErrorKey(object, veld.property);

  return fieldLookupOptions.value[key] ?? [];
};

const fieldWidthClass = (veld: Veld) => {
  const width = (veld.field_width ?? '').toLowerCase();

  if (width === 'sm' || width === 'small' || width === 'kort') {
    return 'w-44 max-w-full';
  }

  if (width === 'md' || width === 'medium') {
    return 'w-64 max-w-full';
  }

  return 'w-full';
};

const apiUrl = (path: string) => {
  if (typeof window !== 'undefined') {
    return path;
  }

  return new URL(path, 'http://localhost').toString();
};

const loadIdentifiers = async () => {
  try {
    const response = await axios.get(apiUrl('/api/identifiers'));
    const rows = (response.data.identifiers ?? []) as IdentifierItem[];
    const map: Record<string, { describedClass: string; properties: string[] }> = {};
    rows.forEach((row) => {
      if (!row.tb_class || !row.described_class) {
return;
}

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

const loadDescribedClassMap = async () => {
  try {
    const response = await axios.get(apiUrl('/api/sjablonen'));
    const sjablonen = response.data?.sjablonen ?? [];
    const map: Record<string, string> = {};
    sjablonen.forEach((row: { sjabloon_uri?: string | null; target_class?: string | null }) => {
      if (row?.sjabloon_uri && row?.target_class) {
        map[row.sjabloon_uri] = row.target_class;
      }
    });
    describedClassByTbClass.value = map;
  } catch (error) {
    console.error('Fout bij ophalen sjabloon target classes:', error);
    describedClassByTbClass.value = {};
  }
};

const loadClassLabels = async () => {
  const classUris = new Set<string>();

  Object.values(describedClassByTbClass.value).forEach((uri) => {
    if (typeof uri === 'string' && uri.startsWith('http')) {
      classUris.add(uri);
    }
  });

  Object.values(identifierMap.value).forEach((row) => {
    if (typeof row.describedClass === 'string' && row.describedClass.startsWith('http')) {
      classUris.add(row.describedClass);
    }
  });

  const uris = Array.from(classUris);

  if (!uris.length) {
    labelMap.value = {};

    return;
  }

  try {
    const response = await axios.post(apiUrl('/api/labels'), { uris });
    labelMap.value = response.data?.labels ?? {};
  } catch (error) {
    console.error('Fout bij ophalen class-labels:', error);
    labelMap.value = {};
  }
};

const initObject = (sjabloon: SjabloonResponse, selectionMode: 'default' | 'attach-existing' = 'default'): ObjectBlock => {
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
      dataTypes[veld.property] = 'literal';
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
    existingGoicId: '',
    attachToExisting: selectionMode === 'attach-existing',
  };
};

const isToestandsWeergaveObject = (object: ObjectBlock) => {
  return typeof object.sjabloonUri === 'string' && object.sjabloonUri.includes('ToestandsWeergave');
};

const isPersoonsBeschrijvingObject = (object: ObjectBlock) => {
  return typeof object.sjabloonUri === 'string' && object.sjabloonUri.endsWith('PersoonsBeschrijving');
};

const getToestandClassUri = (tb: ToestandItem) => {
  if (typeof tb.tb_class === 'string' && tb.tb_class.trim() !== '') {
    return tb.tb_class;
  }

  if (typeof tb.sjabloon_uri === 'string' && tb.sjabloon_uri.trim() !== '') {
    return tb.sjabloon_uri;
  }

  return null;
};

const hasActiveTbClassSuffix = (goic: GoicItem, suffix: string) => {
  return (goic.toestanden ?? []).some((tb) => {
    const classUri = getToestandClassUri(tb);

    return typeof classUri === 'string' && classUri.endsWith(suffix);
  });
};

const hasActiveSignalementOnGoic = (goic: GoicItem) => hasActiveTbClassSuffix(goic, 'Signalement');
const hasActivePersoonsBeschrijvingOnGoic = (goic: GoicItem) =>
  (goic.toestanden ?? []).some((tb) => {
    const classUri = getToestandClassUri(tb);

    return typeof classUri === 'string' && classUri.endsWith('PersoonsBeschrijving');
  });

const getExistingGoicOptionsForObject = (object: ObjectBlock) => {
  if (object.attachToExisting) {
    const options = getGoicsForObject(object);

    if (!isPersoonsBeschrijvingObject(object)) {
      return options;
    }

    return options.filter((goic) =>
      hasActiveSignalementOnGoic(goic) && !hasActivePersoonsBeschrijvingOnGoic(goic)
    );
  }

  if (isToestandsWeergaveObject(object)) {
    return getGoicsForObject(object);
  }

  return [];
};

const needsExistingGoicSelection = (object: ObjectBlock) => {
  return isToestandsWeergaveObject(object) || object.attachToExisting;
};

const isFileFieldLockedForMutation = (object: ObjectBlock) => {
  return !!activeMutationTarget.value && isToestandsWeergaveObject(object);
};

const loadSjabloonByUri = async (uri: string): Promise<SjabloonResponse | null> => {
  try {
    const response = await axios.get(apiUrl('/api/sjabloon/uri'), { params: { uri } });

    return response.data as SjabloonResponse;
  } catch (error) {
    console.error('Fout bij ophalen sjabloon via uri:', error);

    return null;
  }
};

const setPrimaryObjectByUri = async (
  uri: string | null,
  group: 'register' | 'toevoeg' = 'register',
  selectionMode: 'default' | 'attach-existing' = 'default',
) => {
  clearValidationUi();

  if (!uri) {
return;
}

  const sjabloon = await loadSjabloonByUri(uri);

  if (!sjabloon) {
return;
}

  selectedSjabloonUri.value = uri;
  selectedSjabloonSelectionMode.value = selectionMode;
  selectedSjabloonGroupOverride.value = group;
  objects.value = [initObject(sjabloon, selectionMode)];
  syncExistingGoicSelectionForObject(objects.value[0]);
  roleSelections.value = {};
  activeMutationTarget.value = null;
};

const selectRegistratieSjabloon = async (sjabloon: SelectableSjabloon) => {
  await setPrimaryObjectByUri(sjabloon.sjabloon_uri, 'register', 'default');
};

const selectToevoegSjabloon = async (sjabloon: SelectableSjabloon) => {
  await setPrimaryObjectByUri(sjabloon.sjabloon_uri, 'toevoeg', sjabloon.selection_mode);
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

  if (!Array.isArray(current)) {
return;
}

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
  formData.append('case_id', String(props.caseId));
  formData.append('transactie_soort_id', String(props.transactieSoortId));

  try {
    const response = await axios.post(apiUrl('/api/bestand/upload'), formData, {
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
  if (isFileFieldLockedForMutation(object)) {
    return;
  }

  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];

  if (!file) {
return;
}

  delete fieldErrors.value[fieldErrorKey(object, veld.property)];
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

const isRoleTbClass = (tbClassUri: string | null | undefined) => {
  if (typeof tbClassUri !== 'string' || tbClassUri.trim() === '') {
    return false;
  }

  return tbClassUri.toLowerCase().includes('rol');
};

const stripRoleTypePrefix = (label: string) =>
  label.replace(/^RolType_/i, '').trim();

const roleButtonLabel = (role: AllowedRole) => {
  const base = (role.label && role.label.trim() !== '')
    ? role.label
    : shortId(role.tb_class);

  return stripRoleTypePrefix(base);
};

const getLatestTb = (goic: GoicItem) => {
  const reversed = [...(goic.toestanden ?? [])].reverse();
  const nonRole = reversed.find((tb) => {
    const classUri = getToestandClassUri(tb);

    return !!classUri && !isRoleTbClass(classUri);
  });
  const preferred = reversed.find((tb) => {
    const classUri = getToestandClassUri(tb);

    return !!classUri && !isRoleTbClass(classUri) && !!identifierMap.value[classUri];
  });

  return preferred ?? nonRole ?? reversed.find((tb) => !!getToestandClassUri(tb)) ?? null;
};

const getGoicClassUri = (goic: GoicItem) => {
  const tb = getLatestTb(goic);
  const tbClassUri = tb ? getToestandClassUri(tb) : null;

  if (!tbClassUri) {
return null;
}

  const config = identifierMap.value[tbClassUri];

  return config?.describedClass ?? describedClassByTbClass.value[tbClassUri] ?? null;
};

const getGoicDisplayName = (goic: GoicItem) => {
  const classUri = getGoicClassUri(goic);

  if (!classUri) {
    return `GOIC #${goic.id}`;
  }

  const classLabel = labelMap.value[classUri] ?? shortId(classUri);

  return `${classLabel} (#${goic.id})`;
};

const goicsByClass = computed<Record<string, GoicItem[]>>(() => {
  const map: Record<string, GoicItem[]> = {};
  savedGoics.value.forEach((goic) => {
    const classUri = getGoicClassUri(goic);

    if (!classUri) {
return;
}

    if (!map[classUri]) {
      map[classUri] = [];
    }

    map[classUri].push(goic);
  });

  return map;
});

const getGoicsForClass = (classUri: string | null) => {
  if (!classUri) {
return [];
}

  return goicsByClass.value[classUri] ?? [];
};

const getGoicsForObject = (object: ObjectBlock) => {
  if (!object.targetClass) {
    return [];
  }

  return getGoicsForClass(object.targetClass);
};

const isGoicLookupField = (veld: Veld) => {
  return veld.type === 'url'
    && !!veld.lookup
    && typeof veld.lookup.class_uri === 'string'
    && veld.lookup.class_uri.trim() !== '';
};

const getGoicsForLookupField = (veld: Veld) => {
  const classUri = typeof veld.lookup?.class_uri === 'string' ? veld.lookup.class_uri.trim() : '';

  if (classUri === '') {
return [];
}

  return getGoicsForClass(classUri);
};

const syncExistingGoicSelectionForObject = (object: ObjectBlock) => {
  if (!needsExistingGoicSelection(object)) {
    object.existingGoicId = '';

    return;
  }

  const options = getExistingGoicOptionsForObject(object);

  if (options.length === 1) {
    object.existingGoicId = String(options[0].id);

    return;
  }

  if (options.length > 1 && object.existingGoicId) {
    const stillValid = options.some((goic) => String(goic.id) === object.existingGoicId);

    if (stillValid) {
      return;
    }
  }

  object.existingGoicId = '';
};

const roleGroups = computed(() => {
  return [...allowedRoles.value]
    .filter((role) => hasCrud(role.crud_flags, 'C'))
    .sort((a, b) => (a.volgorde ?? 0) - (b.volgorde ?? 0));
});

const getRoleSelections = (tbClass: string) => roleSelections.value[tbClass] ?? [];
const isRoleSelected = (tbClass: string) => getRoleSelections(tbClass).length > 0;
const activeRoleForSelection = computed(() =>
  roleGroups.value.find((role) => isRoleSelected(role.tb_class)) ?? null
);
const activeRoleSelection = computed(() => {
  if (!activeRoleForSelection.value) {
    return null;
  }

  return getRoleSelections(activeRoleForSelection.value.tb_class)[0] ?? null;
});

const focusFieldByErrorKey = (errorKey: string) => {
  if (typeof document === 'undefined') {
    return;
  }

  const selector = `[data-field-key="${errorKey}"]`;
  const element = document.querySelector(selector) as HTMLElement | null;

  if (!element) {
    return;
  }

  element.scrollIntoView({ behavior: 'smooth', block: 'center' });
  element.focus();
};

const isEmptyRequiredValue = (object: ObjectBlock, veld: Veld) => {
  const value = object.formData[veld.property];

  if (veld.type === 'multi-uri') {
    if (!Array.isArray(value)) {
      return true;
    }

    return value.every((item) => String(item ?? '').trim() === '');
  }

  if (Array.isArray(value)) {
    return value.length === 0 || value.every((item) => String(item ?? '').trim() === '');
  }

  return String(value ?? '').trim() === '';
};

const validateBeforeSubmit = () => {
  // In rolmodus valideren we alleen de rolinvoer.
  if (activeRoleForSelection.value && activeRoleSelection.value) {
    fieldErrors.value = {};
    roleError.value = '';

    if (!activeRoleSelection.value.fromGoicId || !activeRoleSelection.value.toGoicId) {
      roleError.value = 'Kies beide objecten voor de geselecteerde rol.';

      return false;
    }

    return true;
  }

  const nextFieldErrors: Record<string, string> = {};
  roleError.value = '';
  let firstErrorKey: string | null = null;

  objects.value.forEach((object) => {
    object.velden.forEach((veld) => {
      if (!veld.required) {
        return;
      }

      if (!isEmptyRequiredValue(object, veld)) {
        return;
      }

      const key = fieldErrorKey(object, veld.property);
      nextFieldErrors[key] = 'Dit veld is verplicht.';

      if (!firstErrorKey) {
        firstErrorKey = key;
      }
    });
  });

  fieldErrors.value = nextFieldErrors;

  if (firstErrorKey) {
    focusFieldByErrorKey(firstErrorKey);

    return false;
  }

  return true;
};

const addRoleSelection = (role: AllowedRole) => {
  if (!role.tb_class) {
return;
}

  clearValidationUi();
  selectedSjabloonUri.value = null;
  selectedSjabloonSelectionMode.value = 'default';
  selectedSjabloonGroupOverride.value = null;

  if (isRoleSelected(role.tb_class)) {
    return;
  }

  // Single-select gedrag zoals bij registreren:
  // altijd exact 1 actieve rolknop + 1 bijbehorende regel.
  roleSelections.value = {};

  if (getRoleSelections(role.tb_class).length > 0) {
    return;
  }

  const toOptions = getGoicsForClass(role.naar_class ?? null);
  roleSelections.value[role.tb_class] = [{
    fromGoicId: '',
    toGoicId: toOptions.length === 1 ? String(toOptions[0].id) : '',
  }];
};

const canAddRole = (role: AllowedRole) => {
  const fromOptions = getGoicsForClass(role.van_class ?? null);
  const toOptions = getGoicsForClass(role.naar_class ?? null);

  return fromOptions.length > 0 && toOptions.length > 0;
};

const loadForTransactie = async () => {
  try {
    await loadDescribedClassMap();
    await loadIdentifiers();
    await loadClassLabels();
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
      sjablonen.value = sjabloon.allowed_sjablonen.filter((item) =>
        hasCrud(item.crud_flags, 'C') || hasCrud(item.crud_flags, 'A')
      );
    } else {
      sjablonen.value = [
        {
          sjabloon_uri: sjabloon.sjabloon_uri,
          label: sjabloon.sjabloon_label,
          target_class: sjabloon.target_class,
          volgorde: 1,
          crud_flags: 'CRUD',
        },
      ];
    }

    const primary = registratieSjablonen.value[0] ?? orderedSjablonen.value[0];

    if (primary) {
      await setPrimaryObjectByUri(primary.sjabloon_uri, 'register', 'default');
    } else {
      objects.value = [initObject(sjabloon)];
    }

    activeMutationTarget.value = null;

    objects.value.forEach((object) => syncExistingGoicSelectionForObject(object));

  } catch (error) {
    console.error("Fout bij ophalen sjabloon:", error);
  }
};

onMounted(loadForTransactie);
onUnmounted(() => {
  kentekenLookupTimers.forEach((timer) => clearTimeout(timer));
  kentekenLookupTimers.clear();
});
watch(() => props.transactieSoortId, () => {
  loadForTransactie();
});
watch(
  () => [props.dossiers, objects.value.length],
  () => {
    objects.value.forEach((object) => syncExistingGoicSelectionForObject(object));
  },
  { deep: true }
);

const submitForm = async () => {
  if (!validateBeforeSubmit()) {
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
    Object.keys(object.fileUploads).some((property) =>
      object.velden.some((veld) => veld.property === property && veld.required) && !object.formData[property]
    )
  );

  if (hasMissingUploads) {
    alert('Upload een bestand voor alle verplichte foto-velden.');

    return;
  }

  try {
    const missingExistingGoicSelection = objects.value.some((object) => {
      if (!needsExistingGoicSelection(object)) {
        return false;
      }

      const options = getExistingGoicOptionsForObject(object);

      if (options.length === 0) {
        return true;
      }

      return options.length > 1 && !object.existingGoicId;
    });

    if (missingExistingGoicSelection) {
      alert('Kies voor elke toevoegen-actie het bestaande object waar je op wilt registreren.');

      return;
    }

    const roleItems: RolePayloadItem[] = [];
    roleGroups.value.forEach((role) => {
      const selections = getRoleSelections(role.tb_class);
      const toOptions = getGoicsForClass(role.naar_class ?? null);
      const defaultTo = toOptions.length === 1 ? String(toOptions[0].id) : '';

      selections.forEach((selection) => {
        const fromId = selection.fromGoicId;
        const toId = selection.toGoicId || defaultTo;

        if (!fromId) {
return;
}

        if (!toId) {
return;
}

        roleItems.push({
          roleTbClass: role.tb_class,
          fromGoicId: Number(fromId),
          toGoicId: Number(toId),
        });
      });
    });

    const roleModeActive = !!activeRoleForSelection.value && !!activeRoleSelection.value;
    const mutateModeActive = !!activeMutationTarget.value;

    const payload = {
      mode: mutateModeActive ? 'mutate' : (roleModeActive ? 'register' : 'register'),
      transactie_soort_id: props.transactieSoortId,
      case_id: props.caseId,
      target: mutateModeActive ? {
        goic_id: activeMutationTarget.value?.goic_id,
        mutatie_id: activeMutationTarget.value?.mutatie_id,
        tb_rdf_uri: activeMutationTarget.value?.tb_rdf_uri,
        sjabloon_uri: activeMutationTarget.value?.sjabloon_uri,
      } : null,
      objects: roleModeActive ? [] : objects.value.map(object => ({
        client_id: object.clientId,
        sjabloon_uri: object.sjabloonUri,
        target_class: object.targetClass,
        attach_to_existing: object.attachToExisting,
        existing_goic_id: object.existingGoicId ? Number(object.existingGoicId) : null,
        data: object.formData,
        data_types: object.dataTypes,
      })),
      roles: {
        items: roleItems,
      },
    };
    
    const response = await axios.post(apiUrl('/api/mutatie'), payload);
    alert(mutateModeActive ? 'Succes! Mutatie opgeslagen.' : 'Succes! Object aangemaakt.');
    console.log('Response:', response.data);
    activeMutationTarget.value = null;
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

const applyMutationTarget = async (target: NonNullable<typeof props.mutationTarget>) => {
  const sjabloon = await loadSjabloonByUri(target.sjabloon_uri);

  if (!sjabloon) {
    return;
  }

  clearValidationUi();
  roleSelections.value = {};
  selectedSjabloonUri.value = target.sjabloon_uri;
  selectedSjabloonSelectionMode.value = 'default';
  selectedSjabloonGroupOverride.value = isToestandsWeergaveSjabloon({
    sjabloon_uri: target.sjabloon_uri,
    label: null,
    target_class: sjabloon.target_class,
  }) ? 'toevoeg' : 'register';
  const block = initObject(sjabloon, 'default');
  block.existingGoicId = String(target.goic_id);

  if (target.tb_data && typeof target.tb_data === 'object' && !Array.isArray(target.tb_data)) {
    Object.entries(target.tb_data as Record<string, unknown>).forEach(([key, value]) => {
      if (!(key in block.formData)) {
        return;
      }

      if (Array.isArray(block.formData[key])) {
        if (Array.isArray(value)) {
          (block.formData[key] as string[]) = value.map((item) => String(item ?? '')).filter((item) => item !== '');
        } else if (value !== null && value !== undefined && String(value).trim() !== '') {
          (block.formData[key] as string[]) = [String(value)];
        }
      } else {
        block.formData[key] = value === null || value === undefined ? '' : String(value);
      }
    });
  }

  objects.value = [block];
  activeMutationTarget.value = target;
};

watch(
  () => props.mutationTarget,
  async (target) => {
    if (!target) {
      activeMutationTarget.value = null;

      if (objects.value.length === 1 && selectedSjabloonUri.value) {
        await loadForTransactie();
      }

      return;
    }

    await applyMutationTarget(target);
  },
  { immediate: true }
);
</script>
