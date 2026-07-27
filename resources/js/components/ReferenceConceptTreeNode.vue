<script setup lang="ts">
import { ChevronDown, ChevronRight, LoaderCircle } from 'lucide-vue-next';

defineOptions({
  name: 'ReferenceConceptTreeNode',
});

type ReferenceConcept = {
  uri: string;
  label: string;
  code: string;
  has_children: boolean;
  selectable: boolean;
};

defineProps<{
  concept: ReferenceConcept;
  conceptsByParent: Record<string, ReferenceConcept[]>;
  expandedParents: Set<string>;
  loadingParents: Set<string>;
  errorsByParent: Record<string, string>;
}>();

const emit = defineEmits<{
  (event: 'toggle', concept: ReferenceConcept): void;
  (event: 'select', concept: ReferenceConcept): void;
}>();
</script>

<template>
  <li role="treeitem" :aria-expanded="concept.has_children ? expandedParents.has(concept.uri) : undefined">
    <div class="flex items-center gap-1">
      <button
        v-if="concept.has_children"
        type="button"
        class="inline-flex size-7 shrink-0 items-center justify-center rounded text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700"
        :aria-label="`${expandedParents.has(concept.uri) ? 'Sluit' : 'Open'} ${concept.label}`"
        @click="emit('toggle', concept)"
      >
        <LoaderCircle v-if="loadingParents.has(concept.uri)" class="size-4 animate-spin" />
        <ChevronDown v-else-if="expandedParents.has(concept.uri)" class="size-4" />
        <ChevronRight v-else class="size-4" />
      </button>
      <span v-else class="w-7 shrink-0" />
      <button
        type="button"
        class="min-w-0 flex-1 rounded px-2 py-1.5 text-left text-sm transition"
        :class="concept.selectable
          ? 'text-gray-900 hover:bg-amber-50 dark:text-white dark:hover:bg-amber-900/30'
          : 'cursor-default font-medium text-gray-700 dark:text-gray-200'"
        :disabled="!concept.selectable"
        @click="emit('select', concept)"
      >
        <span class="block truncate">{{ concept.label }}</span>
        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ concept.code }}</span>
      </button>
    </div>
    <div v-if="expandedParents.has(concept.uri)" class="ml-7 border-l border-gray-200 pl-2 dark:border-gray-700">
      <p v-if="errorsByParent[concept.uri]" class="px-2 py-1 text-xs text-red-600 dark:text-red-300">
        {{ errorsByParent[concept.uri] }}
      </p>
      <ul v-else role="group" class="space-y-1">
        <ReferenceConceptTreeNode
          v-for="child in conceptsByParent[concept.uri] ?? []"
          :key="child.uri"
          :concept="child"
          :concepts-by-parent="conceptsByParent"
          :expanded-parents="expandedParents"
          :loading-parents="loadingParents"
          :errors-by-parent="errorsByParent"
          @toggle="emit('toggle', $event)"
          @select="emit('select', $event)"
        />
      </ul>
    </div>
  </li>
</template>
