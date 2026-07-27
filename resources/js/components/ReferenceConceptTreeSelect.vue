<script setup lang="ts">
import axios from 'axios';
import { ChevronDown, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import ReferenceConceptTreeNode from '@/components/ReferenceConceptTreeNode.vue';

type ReferenceConcept = {
  uri: string;
  label: string;
  code: string;
  has_children: boolean;
  selectable: boolean;
};

const props = withDefaults(defineProps<{
  modelValue: string;
  endpoint: string;
  required?: boolean;
}>(), {
  required: false,
});

const emit = defineEmits<{
  (event: 'update:modelValue', value: string): void;
}>();

const rootKey = '__root__';
const isOpen = ref(false);
const conceptsByParent = ref<Record<string, ReferenceConcept[]>>({});
const expandedParents = ref(new Set<string>());
const loadingParents = ref(new Set<string>());
const errorsByParent = ref<Record<string, string>>({});
const selectedConcept = ref<ReferenceConcept | null>(null);

const selectedLabel = computed(() => {
  if (selectedConcept.value?.uri === props.modelValue) {
    return `${selectedConcept.value.label} (${selectedConcept.value.code})`;
  }

  return props.modelValue || 'Kies een goedsoort';
});

const parentKey = (parentUri: string | null) => parentUri ?? rootKey;

const loadChildren = async (parentUri: string | null): Promise<void> => {
  const key = parentKey(parentUri);

  if (conceptsByParent.value[key] || loadingParents.value.has(key)) {
    return;
  }

  loadingParents.value.add(key);
  delete errorsByParent.value[key];

  try {
    const response = await axios.get(props.endpoint, {
      params: parentUri ? { parent: parentUri } : {},
    });

    conceptsByParent.value[key] = Array.isArray(response.data?.concepten) ? response.data.concepten : [];
  } catch (error) {
    console.error('Goedsoorten ophalen mislukt:', error);
    errorsByParent.value[key] = 'De goedsoorten konden niet worden opgehaald. Probeer het opnieuw.';
  } finally {
    loadingParents.value.delete(key);
  }
};

const toggleTree = async (): Promise<void> => {
  isOpen.value = !isOpen.value;

  if (isOpen.value) {
    await loadChildren(null);
  }
};

const toggleBranch = async (concept: ReferenceConcept): Promise<void> => {
  if (!concept.has_children) {
    return;
  }

  if (expandedParents.value.has(concept.uri)) {
    expandedParents.value.delete(concept.uri);
    expandedParents.value = new Set(expandedParents.value);

    return;
  }

  expandedParents.value.add(concept.uri);
  expandedParents.value = new Set(expandedParents.value);
  await loadChildren(concept.uri);
};

const selectConcept = (concept: ReferenceConcept): void => {
  if (!concept.selectable) {
    return;
  }

  selectedConcept.value = concept;
  emit('update:modelValue', concept.uri);
  isOpen.value = false;
};

const clearSelection = (): void => {
  selectedConcept.value = null;
  emit('update:modelValue', '');
};

watch(() => props.modelValue, (value) => {
  if (value === '') {
    selectedConcept.value = null;
  }
});
</script>

<template>
  <div class="relative">
    <input :value="modelValue" type="hidden" :required="required">
    <div class="flex gap-2">
      <button
        type="button"
        class="flex h-10 flex-1 items-center justify-between gap-3 rounded-lg border border-gray-300 bg-white px-3 text-left text-sm text-gray-900 shadow-sm outline-none transition hover:bg-gray-50 focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:hover:bg-gray-600"
        :aria-expanded="isOpen"
        @click="toggleTree"
      >
        <span class="truncate" :class="{ 'text-gray-500 dark:text-gray-400': !modelValue }">{{ selectedLabel }}</span>
        <ChevronDown class="size-4 shrink-0" :class="{ 'rotate-180': isOpen }" />
      </button>
      <button
        v-if="modelValue"
        type="button"
        class="inline-flex size-10 items-center justify-center rounded-lg border border-gray-300 text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
        aria-label="Wis gekozen goedsoort"
        @click="clearSelection"
      >
        <X class="size-4" />
      </button>
    </div>

    <div v-if="isOpen" class="absolute z-20 mt-2 max-h-80 w-full overflow-auto rounded-lg border border-gray-200 bg-white p-2 shadow-lg dark:border-gray-600 dark:bg-gray-800">
      <ul role="tree" class="space-y-1">
        <ReferenceConceptTreeNode
          v-for="concept in conceptsByParent[rootKey] ?? []"
          :key="concept.uri"
          :concept="concept"
          :concepts-by-parent="conceptsByParent"
          :expanded-parents="expandedParents"
          :loading-parents="loadingParents"
          :errors-by-parent="errorsByParent"
          @toggle="toggleBranch"
          @select="selectConcept"
        />
      </ul>
      <p v-if="loadingParents.has(rootKey)" class="px-2 py-2 text-sm text-gray-500 dark:text-gray-400">Goedsoorten laden...</p>
      <p v-else-if="errorsByParent[rootKey]" class="px-2 py-2 text-sm text-red-600 dark:text-red-300">{{ errorsByParent[rootKey] }}</p>
      <p v-else-if="(conceptsByParent[rootKey] ?? []).length === 0" class="px-2 py-2 text-sm text-gray-500 dark:text-gray-400">Geen goedsoorten gevonden.</p>
    </div>
  </div>
</template>
