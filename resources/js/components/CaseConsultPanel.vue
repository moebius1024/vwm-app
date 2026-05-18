<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { ref, watch } from 'vue';

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
  dossiers?: DossierItem[];
  transactieSoortId?: number | null;
  caseId?: number | null;
  mutationTarget?: {
    goic_id: number;
    mutatie_id: number;
    sjabloon_uri: string;
    tb_rdf_uri: string | null;
    tb_class: string | null;
    tb_data: Record<string, unknown> | string | null;
  } | null;
};

const props = defineProps<Props>();
const emit = defineEmits<{
  (e: 'select-mutate', target: {
    goic_id: number;
    mutatie_id: number;
    sjabloon_uri: string;
    tb_rdf_uri: string | null;
    tb_class: string | null;
    tb_data: Record<string, unknown> | string | null;
  }): void;
  (e: 'mutation-changed'): void;
}>();
const labelMap = ref<Record<string, string>>({});
const goicDisplayMap = ref<Record<string, string>>({});
const goDisplayMap = ref<Record<string, string>>({});
const goicDisplayByTail = ref<Record<string, string>>({});
const remoteGoicDisplayMap = ref<Record<string, string>>({});
const identifierMap = ref<Record<string, { describedClass: string; properties: string[] }>>({});
const describedClassByTbClass = ref<Record<string, string>>({});
const fieldOrderByTbClass = ref<Record<string, Record<string, number>>>({});
const classOrder = ref<Record<string, number>>({});
const classCrudMap = ref<Record<string, string>>({});
const sjabloonCrudMap = ref<Record<string, string>>({});
const roleCrudMap = ref<Record<string, string>>({});
const roleCrudByTypeMap = ref<Record<string, string>>({});

const hasLinkedGoics = (goic: GoicItem) =>
  !!goic.go_uri && (goic.linked_goic_count ?? 0) > 1;

const linkedOthersCount = (goic: GoicItem) =>
  Math.max((goic.linked_goic_count ?? 1) - 1, 1);

const goLinksHref = (goic: GoicItem) => {
  if (!goic.go_uri) {
    return '#';
  }

  const params = new URLSearchParams({ go: goic.go_uri });

  if (props.caseId) {
    params.set('case', String(props.caseId));
  }

  return `/raadplegen/go?${params.toString()}`;
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

const isLicensePlateProperty = (property: string) => {
  return property === 'http://ontologie.politie.nl/def/dpm#licensePlate'
    || property.endsWith('#licensePlate')
    || property.endsWith('/licensePlate');
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

const isIncidentReferenceField = (key: string) => {
  return key === 'heeftIncident'
    || key.endsWith('#heeftIncident')
    || key.endsWith('/heeftIncident');
};

const isRoleReferenceField = (key: string) => {
  const normalized = key.trim().toLowerCase();
  return normalized === 'van'
    || normalized === 'naar'
    || normalized === 'heeftincident'
    || normalized === 'heeftvoertuig'
    || normalized === 'heeftpersoon'
    || normalized.endsWith('#van')
    || normalized.endsWith('/van')
    || normalized.endsWith('#naar')
    || normalized.endsWith('/naar')
    || normalized.endsWith('#heeftincident')
    || normalized.endsWith('/heeftincident')
    || normalized.endsWith('#heeftvoertuig')
    || normalized.endsWith('/heeftvoertuig')
    || normalized.endsWith('#heeftpersoon')
    || normalized.endsWith('/heeftpersoon');
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
const hasCrud = (flags: string | null | undefined, required: 'C' | 'R' | 'U' | 'D') =>
  (flags ?? 'CRUD').toUpperCase().includes(required);

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

const buildGoicDisplayMap = (dossiers: DossierItem[]) => {
  const map: Record<string, string> = {};
  const goMap: Record<string, string> = {};
  const tailMap: Record<string, string> = {};
  dossiers.forEach((dossier) => {
    dossier.goics.forEach((goic) => {
      const reversed = [...goic.toestanden].reverse();
      const nonRoleTb = reversed.find((tb) => !!tb.tb_class && !isRoleTbClass(tb.tb_class));
      const preferredTb = reversed.find((tb) => !!tb.tb_class && !!identifierMap.value[tb.tb_class]);
      const fallbackTb = reversed.find((tb) => !!tb.tb_class);
      const lastTb = preferredTb ?? nonRoleTb ?? fallbackTb ?? null;

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

          const formatted = formatValue(raw);
          values.push(isLicensePlateProperty(prop) ? formatted.toUpperCase() : formatted);
        });

        if (values.length) {
          const uniqueValues: string[] = [];
          values.forEach((value) => {
            const normalized = value.trim().toUpperCase();
            if (!uniqueValues.some((item) => item.trim().toUpperCase() === normalized)) {
              uniqueValues.push(value);
            }
          });

          identifierValue = uniqueValues.join(', ');
        }
      }

      const display = identifierValue ? `${classLabel}: ${identifierValue}` : classLabel;
      const vehicleOnlyIdentifier = describedClass === 'http://ontologie.politie.nl/def/dpm#Vehicle' && identifierValue;
      const finalDisplay = vehicleOnlyIdentifier ? identifierValue : display;
      map[goic.rdf_uri] = finalDisplay;
      tailMap[shortId(goic.rdf_uri)] = finalDisplay;
      if (goic.go_uri && !goMap[goic.go_uri]) {
        goMap[goic.go_uri] = finalDisplay;
      }
    });
  });
  goicDisplayMap.value = map;
  goDisplayMap.value = goMap;
  goicDisplayByTail.value = tailMap;
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
  const nonRoleTb = reversed.find((item) => !!item.tb_class && !isRoleTbClass(item.tb_class));
  const preferredTb = reversed.find((item) => !!item.tb_class && !!identifierMap.value[item.tb_class]);
  const fallbackTb = reversed.find((item) => !!item.tb_class);
  const tb = preferredTb ?? nonRoleTb ?? fallbackTb ?? null;

  return classLabelForTb(tb) || classLabelForTb(goic.follow_info?.source_state ?? null);
};

const followedRegistrationTitle = (goic: GoicItem) => {
  const classLabel = goicClassLabel(goic);
  return classLabel ? `Gevolgde ${classLabel} Registratie` : 'Gevolgde Registratie';
};

const goicClassUri = (goic: GoicItem) => {
  const reversed = [...goic.toestanden].reverse();
  const nonRoleTb = reversed.find((item) => !!item.tb_class && !isRoleTbClass(item.tb_class));
  const preferredTb = reversed.find((item) => !!item.tb_class && !!identifierMap.value[item.tb_class]);
  const fallbackTb = reversed.find((item) => !!item.tb_class);
  const tb = preferredTb ?? nonRoleTb ?? fallbackTb ?? null;

  if (!tb?.tb_class) {
return null;
}

  const idConfig = identifierMap.value[tb.tb_class];

  return idConfig?.describedClass ?? describedClassByTbClass.value[tb.tb_class] ?? null;
};

const isRoleTbClass = (tbClass: string) => tbClass.toLowerCase().includes('rol');

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

const formatFieldValue = (key: string, value: unknown) => {
  let formatted = formatValue(value);
  if (isLicensePlateProperty(key)) {
    formatted = formatted.toUpperCase();
  }
  if ((isIncidentReferenceField(key) || isRoleReferenceField(key)) && /^[^:]+:\s+/.test(formatted)) {
    formatted = formatted.replace(/^[^:]+:\s+/, '');
  }
  return formatted;
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

const loadSjabloonOrder = async () => {
  if (typeof window === 'undefined') {
    return;
  }

  if (!props.transactieSoortId) {
    classOrder.value = {};
    classCrudMap.value = {};
    sjabloonCrudMap.value = {};
    roleCrudMap.value = {};
    roleCrudByTypeMap.value = {};

    return;
  }

  try {
    const response = await axios.get(`/api/sjabloon/${props.transactieSoortId}`);
    const allowed = response.data.allowed_sjablonen ?? [];
    const allowedRoles = response.data.allowed_roles ?? [];
    const map: Record<string, number> = {};
    const crudByClass: Record<string, string> = {};
    const crudBySjabloon: Record<string, string> = {};
    const crudByRole: Record<string, string> = {};
    const crudByRoleType: Record<string, string> = {};
    allowed.forEach((item: { target_class?: string | null; volgorde?: number }, index: number) => {
      if (!item.target_class) {
return;
}

      map[item.target_class] = item.volgorde ?? index + 1;
      crudByClass[item.target_class] = String((item as { crud_flags?: string | null }).crud_flags ?? 'CRUD').toUpperCase();
      const uri = (item as { sjabloon_uri?: string | null }).sjabloon_uri ?? null;
      if (uri) {
        crudBySjabloon[uri] = String((item as { crud_flags?: string | null }).crud_flags ?? 'CRUD').toUpperCase();
      }
    });
    allowedRoles.forEach((item: { tb_class?: string | null; role_type?: string | null; crud_flags?: string | null }) => {
      if (!item.tb_class) {
        return;
      }

      crudByRole[item.tb_class] = String(item.crud_flags ?? 'CRD').toUpperCase();
      if (item.role_type) {
        crudByRoleType[item.role_type] = String(item.crud_flags ?? 'CRD').toUpperCase();
      }
    });
    classOrder.value = map;
    classCrudMap.value = crudByClass;
    sjabloonCrudMap.value = crudBySjabloon;
    roleCrudMap.value = crudByRole;
    roleCrudByTypeMap.value = crudByRoleType;
  } catch (error) {
    console.error('Fout bij ophalen sjabloon-volgorde:', error);
    classOrder.value = {};
    classCrudMap.value = {};
    sjabloonCrudMap.value = {};
    roleCrudMap.value = {};
    roleCrudByTypeMap.value = {};
  }
};

const getOrderValue = (goic: GoicItem) => {
  const classUri = goicClassUri(goic);

  if (classUri && classOrder.value[classUri] !== undefined) {
    return classOrder.value[classUri];
  }

  return 9999;
};

const orderedGoics = (dossier: DossierItem) => {
  return [...dossier.goics].sort((a, b) => {
    const orderA = getOrderValue(a);
    const orderB = getOrderValue(b);

    if (orderA !== orderB) {
return orderA - orderB;
}

    return a.id - b.id;
  });
};

const visibleGoics = (dossier: DossierItem) => {
  return orderedGoics(dossier).filter((goic) =>
    visibleToestanden(goic).length > 0 || visibleFollowSourceEntries(goic).length > 0,
  );
};

const dossiersWithVisibleGoics = () => {
  return (props.dossiers ?? []).filter((dossier) => visibleGoics(dossier).length > 0);
};

const apiUrl = (path: string) => {
  if (typeof window !== 'undefined') {
    return path;
  }

  return new URL(path, 'http://localhost').toString();
};

const shouldSkipFieldForGoic = (key: string, value: unknown, goic: GoicItem) => {
  if (isAssociationLikeField(key)) {
    return true;
  }

  return isSelfReferenceValue(value, goic, key);
};

const tbEntries = (tb: ToestandItem): [string, unknown][] => {
  if (!tb.tb_data || typeof tb.tb_data !== 'object' || Array.isArray(tb.tb_data)) {
    return [];
  }

  const entries = Object.entries(tb.tb_data as Record<string, unknown>);
  const tbClass = tb.tb_class ?? '';
  const orderMap = tbClass ? fieldOrderByTbClass.value[tbClass] ?? null : null;

  return entries.sort(([aKey], [bKey]) => {
    if (orderMap) {
      const aOrder = orderMap[aKey] ?? Number.MAX_SAFE_INTEGER;
      const bOrder = orderMap[bKey] ?? Number.MAX_SAFE_INTEGER;
      if (aOrder !== bOrder) {
        return aOrder - bOrder;
      }
    }

    return aKey.localeCompare(bKey);
  });
};

const isAssociationToestand = (tb: ToestandItem) => {
  const value = `${tb.tb_class ?? ''} ${tb.sjabloon_uri ?? ''}`.toLowerCase();
  return value.includes('dataobjectassociation');
};

const visibleTbEntries = (tb: ToestandItem, goic: GoicItem): [string, unknown][] => {
  if (isAssociationToestand(tb)) {
    return [];
  }

  return tbEntries(tb).filter(([key, value]) => !shouldSkipFieldForGoic(String(key), value, goic));
};

const visibleToestanden = (goic: GoicItem) => {
  const filtered = goic.toestanden.filter((tb) => {
    if (visibleTbEntries(tb, goic).length <= 0) {
      return false;
    }

    if (isRoleToestand(tb)) {
      const roleTypeUri = getRoleTypeUri(tb);
      if (roleTypeUri) {
        return hasCrud(roleCrudByTypeMap.value[roleTypeUri] ?? 'CRD', 'R');
      }
      const roleKey = tb.sjabloon_uri ?? tb.tb_class ?? '';
      const flags = roleCrudMap.value[roleKey];
      return hasCrud(flags ?? 'CRD', 'R');
    }

    const key = tb.tb_class ?? tb.sjabloon_uri ?? '';
    const flags = key ? sjabloonCrudMap.value[key] : null;
    return hasCrud(flags ?? 'CRUD', 'R');
  });

  const rank = (tb: ToestandItem) => {
    if (isRoleToestand(tb)) {
      return 2;
    }
    if (isToestandsWeergaveToestand(tb)) {
      return 1;
    }
    return 0;
  };

  return filtered
    .map((tb, index) => ({ tb, index }))
    .sort((a, b) => {
      const diff = rank(a.tb) - rank(b.tb);
      if (diff !== 0) {
        return diff;
      }
      return a.index - b.index;
    })
    .map((item) => item.tb);
};

const isRoleToestand = (tb: ToestandItem) => {
  const marker = `${tb.tb_class ?? ''} ${tb.sjabloon_uri ?? ''}`.toLowerCase();
  if (marker.includes('roltype') || marker.includes('rol')) {
    return true;
  }

  const entries = tbEntries(tb).map(([key]) => String(key).toLowerCase());
  return entries.includes('roltype') || entries.some((key) => key.endsWith('#roltype') || key.endsWith('/roltype'));
};

const getRoleTypeUri = (tb: ToestandItem): string | null => {
  if (!tb.tb_data || typeof tb.tb_data !== 'object' || Array.isArray(tb.tb_data)) {
    return null;
  }

  const data = tb.tb_data as Record<string, unknown>;
  const direct = data.rolType;
  if (typeof direct === 'string' && direct !== '') {
    return direct;
  }

  for (const [key, value] of Object.entries(data)) {
    if (!key.toLowerCase().endsWith('roltype')) {
      continue;
    }
    if (typeof value === 'string' && value !== '') {
      return value;
    }
  }

  return null;
};

const mutationKey = (goic: GoicItem, tb: ToestandItem) => `${goic.id}:${tb.mutatie_id}`;
const activeMutationKey = () => {
  if (!props.mutationTarget) {
    return null;
  }

  return `${props.mutationTarget.goic_id}:${props.mutationTarget.mutatie_id}`;
};

const isMutationActive = (goic: GoicItem, tb: ToestandItem) => activeMutationKey() === mutationKey(goic, tb);
const isMutationDimmed = (goic: GoicItem, tb: ToestandItem) => {
  if (!activeMutationKey()) {
    return false;
  }

  return !isMutationActive(goic, tb);
};

const selectMutate = (goic: GoicItem, tb: ToestandItem) => {
  emit('select-mutate', {
    goic_id: goic.id,
    mutatie_id: tb.mutatie_id,
    sjabloon_uri: tb.sjabloon_uri,
    tb_rdf_uri: tb.tb_rdf_uri,
    tb_class: tb.tb_class,
    tb_data: tb.tb_data,
  });
};

const canMutate = (tb: ToestandItem) => {
  if (isRoleToestand(tb)) {
    return false;
  }

  const key = tb.tb_class ?? tb.sjabloon_uri ?? '';
  const flags = key ? sjabloonCrudMap.value[key] : null;
  return hasCrud(flags ?? 'CRUD', 'U');
};

const canDelete = (tb: ToestandItem) => {
  if (isRoleToestand(tb)) {
    const roleTypeUri = getRoleTypeUri(tb);
    if (roleTypeUri) {
      const flags = roleCrudByTypeMap.value[roleTypeUri];
      return typeof flags === 'string' ? hasCrud(flags, 'D') : false;
    }
    const roleKey = tb.sjabloon_uri ?? tb.tb_class ?? '';
    const flags = roleCrudMap.value[roleKey];
    return typeof flags === 'string' ? hasCrud(flags, 'D') : false;
  }

  const key = tb.tb_class ?? tb.sjabloon_uri ?? '';
  const flags = key ? sjabloonCrudMap.value[key] : null;
  return hasCrud(flags ?? 'CRUD', 'D');
};

const deleteRoleToestand = async (goic: GoicItem, tb: ToestandItem) => {
  const ok = typeof window !== 'undefined'
    ? window.confirm('Rol verwijderen?\n\nJe staat op het punt deze rol te beëindigen. Dit kan niet ongedaan worden gemaakt.')
    : false;
  if (!ok) {
    return;
  }

  try {
    await axios.post(apiUrl('/api/mutatie'), {
      mode: 'delete',
      delete_type: 'role',
      transactie_soort_id: props.transactieSoortId,
      case_id: props.caseId,
      target: {
        goic_id: goic.id,
        mutatie_id: tb.mutatie_id,
        tb_rdf_uri: tb.tb_rdf_uri,
        sjabloon_uri: tb.sjabloon_uri,
      },
    });
    emit('mutation-changed');
  } catch (error) {
    console.error('Fout bij verwijderen rol:', error);
    if (typeof window !== 'undefined') {
      window.alert('Verwijderen mislukt. Zie console voor details.');
    }
  }
};

const isToestandsWeergaveToestand = (tb: ToestandItem) => {
  const marker = `${tb.tb_class ?? ''} ${tb.sjabloon_uri ?? ''}`.toLowerCase();
  return marker.includes('toestandsweergave');
};

const deleteToestand = async (goic: GoicItem, tb: ToestandItem) => {
  const ok = typeof window !== 'undefined'
    ? window.confirm('Toestand verwijderen?\n\nJe staat op het punt deze toestandsbeschrijving te beëindigen. Dit kan niet ongedaan worden gemaakt.')
    : false;
  if (!ok) {
    return;
  }

  try {
    await axios.post(apiUrl('/api/mutatie'), {
      mode: 'delete',
      delete_type: 'toestand',
      transactie_soort_id: props.transactieSoortId,
      case_id: props.caseId,
      target: {
        goic_id: goic.id,
        mutatie_id: tb.mutatie_id,
        tb_rdf_uri: tb.tb_rdf_uri,
        sjabloon_uri: tb.sjabloon_uri,
      },
    });
    emit('mutation-changed');
  } catch (error) {
    console.error('Fout bij verwijderen toestand:', error);
    if (typeof window !== 'undefined') {
      window.alert('Verwijderen mislukt. Zie console voor details.');
    }
  }
};

const visibleFollowSourceEntries = (goic: GoicItem): [string, unknown][] => {
  const state = goic.follow_info?.source_state;
  if (!state || isAssociationToestand(state)) {
    return [];
  }

  return tbEntries(state).filter(([key, value]) => !shouldSkipFieldForGoic(String(key), value, goic));
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
    const sjablonenResponse = await axios.get(apiUrl('/api/sjablonen'));
    const sjablonen = sjablonenResponse.data.sjablonen ?? [];
    const classMap: Record<string, string> = {};
    const orderMapByTbClass: Record<string, Record<string, number>> = {};
    sjablonen.forEach((row: { sjabloon_uri?: string | null; target_class?: string | null }) => {
      if (row?.sjabloon_uri && row?.target_class) {
        classMap[row.sjabloon_uri] = row.target_class;
      }
    });

    const detailResponses = await Promise.all(
      sjablonen
        .map((row: { sjabloon_uri?: string | null }) => row?.sjabloon_uri ?? null)
        .filter((uri: string | null): uri is string => !!uri)
        .map((uri: string) => axios.get(apiUrl('/api/sjabloon/uri'), { params: { uri } })),
    );

    detailResponses.forEach((response) => {
      const uri = response.data?.sjabloon_uri as string | undefined;
      const velden = response.data?.velden as Array<{ property?: string; volgorde?: number }> | undefined;
      if (!uri || !Array.isArray(velden)) {
        return;
      }
      const fieldOrder: Record<string, number> = {};
      velden.forEach((veld, index) => {
        if (!veld?.property) {
          return;
        }
        fieldOrder[veld.property] = typeof veld.volgorde === 'number' ? veld.volgorde : index + 1;
      });
      orderMapByTbClass[uri] = fieldOrder;
    });

    describedClassByTbClass.value = classMap;
    fieldOrderByTbClass.value = orderMapByTbClass;
  } catch (error) {
    console.error('Fout bij ophalen sjabloon target classes:', error);
    describedClassByTbClass.value = {};
    fieldOrderByTbClass.value = {};
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

watch(() => props.transactieSoortId, () => {
  loadSjabloonOrder();
}, { immediate: true });
</script>

<template>
  <div class="space-y-4">
    <div v-if="dossiersWithVisibleGoics().length" class="space-y-4">
      <div v-for="dossier in dossiersWithVisibleGoics()" :key="dossier.id" class="rounded-xl border border-gray-200 p-5 dark:border-gray-700">
        <div class="space-y-3">
          <div v-for="goic in visibleGoics(dossier)" :key="goic.id" class="rounded-lg border border-gray-100 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="text-sm font-medium text-gray-800 dark:text-gray-200">
                <span v-if="goicClassLabel(goic)">{{ goicClassLabel(goic) }} (#{{ goic.id }})</span>
                <span v-else>GOIC #{{ goic.id }}</span>
              </div>
              <Link
                v-if="hasLinkedGoics(goic)"
                :href="goLinksHref(goic)"
                class="inline-flex items-center rounded-md border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-800 transition hover:bg-indigo-100 dark:border-indigo-300/30 dark:bg-indigo-900/30 dark:text-indigo-100 dark:hover:bg-indigo-900/50"
              >
                Gekoppelde Registraties ({{ linkedOthersCount(goic) }})
              </Link>
            </div>

            <div v-if="visibleToestanden(goic).length || visibleFollowSourceEntries(goic).length" class="mt-3 space-y-2">
              <div
                v-if="goic.follow_info?.is_followed && visibleFollowSourceEntries(goic).length"
                class="rounded-lg border border-indigo-200 bg-indigo-50/60 p-3 text-xs text-indigo-900 dark:border-indigo-400/30 dark:bg-indigo-900/20 dark:text-indigo-100"
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

              <div
                v-for="tb in visibleToestanden(goic)"
                :key="tb.mutatie_id"
                class="rounded-lg border p-3 transition dark:bg-gray-900"
                :class="isMutationActive(goic, tb)
                  ? 'border-amber-400 bg-amber-50/40 dark:border-amber-500/40'
                  : isMutationDimmed(goic, tb)
                    ? 'border-gray-200 bg-gray-100 opacity-60 dark:border-gray-700 dark:bg-gray-800'
                    : 'border-gray-200 bg-white dark:border-gray-700'"
              >
                <div class="mt-1 flex items-start justify-between gap-3">
                  <div class="min-w-0 flex-1">
                    <span v-if="isMutationActive(goic, tb)" class="mb-1 inline-block text-[11px] font-semibold text-amber-700 dark:text-amber-300">(muteren)</span>
                    <div v-if="visibleTbEntries(tb, goic).length" class="space-y-1 text-xs text-gray-700 dark:text-gray-200">
                      <template v-for="([key, value]) in visibleTbEntries(tb, goic)" :key="key">
                        <div class="flex flex-wrap items-start gap-2">
                          <span class="min-w-[180px] font-medium text-gray-600 dark:text-gray-300">
                            {{ fieldLabelFor(String(key)) || 'Onbekend veld' }}
                          </span>
                          <span v-if="isBestandField(String(key))" class="break-all text-gray-800 dark:text-gray-100">
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
                            {{ formatFieldValue(String(key), value) }}
                          </span>
                        </div>
                      </template>
                    </div>
                    <div v-else class="rounded bg-gray-50 p-2 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                      Geen velden ingevuld.
                    </div>
                  </div>
                  <div class="shrink-0 self-start rounded-md border border-gray-200 bg-gray-50 p-1 dark:border-gray-700 dark:bg-gray-800">
                    <div v-if="!isRoleToestand(tb)" class="flex flex-col gap-1">
                      <button
                        v-if="canMutate(tb)"
                        type="button"
                        class="inline-flex items-center rounded-md border border-amber-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-amber-800 transition hover:bg-amber-100 dark:border-amber-300/30 dark:bg-gray-900 dark:text-amber-100 dark:hover:bg-amber-900/40"
                        @click="selectMutate(goic, tb)"
                      >
                        Muteren
                      </button>
                      <button
                        v-if="canDelete(tb)"
                        type="button"
                        class="inline-flex items-center rounded-md border border-rose-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50 dark:border-rose-300/30 dark:bg-gray-900 dark:text-rose-200 dark:hover:bg-rose-900/20"
                        @click.prevent="deleteToestand(goic, tb)"
                      >
                        Verwijderen
                      </button>
                    </div>
                    <button
                      v-else-if="canDelete(tb)"
                      type="button"
                      class="inline-flex items-center rounded-md border border-rose-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50 dark:border-rose-300/30 dark:bg-gray-900 dark:text-rose-200 dark:hover:bg-rose-900/20"
                      @click="deleteRoleToestand(goic, tb)"
                    >
                      Verwijderen
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div v-else class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
      Geen dossiers gevonden voor deze case.
    </div>
  </div>
</template>
