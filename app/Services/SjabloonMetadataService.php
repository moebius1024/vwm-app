<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SjabloonMetadataService
{
    private const ONTOLOGY_GRAPH = 'http://vwm.voorbeeld.nl/model/ontologie';

    private const SHAPE_GRAPHS = [
        'http://vwm.voorbeeld.nl/model/shapes/domain',
        'http://vwm.voorbeeld.nl/model/shapes/process',
        'http://vwm.voorbeeld.nl/model/shapes/ui',
    ];

    public function __construct(private readonly GraphService $graphService) {}

    public function listSjablonen(): array
    {
        $query = '
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

            SELECT ?sjabloon ?label ?targetClass
            WHERE {
                GRAPH <'.self::ONTOLOGY_GRAPH.'> {
                    ?sjabloon rdfs:subClassOf vwm:ToestandsBeschrijving .
                    OPTIONAL { ?sjabloon rdfs:label ?label . }
                    OPTIONAL { ?sjabloon vwm:beschrijftClass ?targetClass . }
                }
            }
            ORDER BY ?label
        ';

        $rows = $this->graphService->query($query);

        return array_map(function ($row) {
            return [
                'sjabloon_uri' => $row['sjabloon'],
                'label' => $row['label'] ?? null,
                'target_class' => $row['targetClass'] ?? null,
            ];
        }, $rows);
    }

    public function listRolTypes(): array
    {
        $query = '
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

            SELECT ?rolType ?roleKey ?label
            WHERE {
                GRAPH <'.self::ONTOLOGY_GRAPH.'> {
                    ?rolType a vwm:RolType ;
                             vwm:roleKey ?roleKey .
                    OPTIONAL { ?rolType rdfs:label ?label . }
                }
            }
            ORDER BY ?roleKey
        ';

        $rows = $this->graphService->query($query);

        return array_map(function ($row) {
            return [
                'role_key' => $row['roleKey'],
                'uri' => $row['rolType'],
                'label' => $row['label'] ?? null,
            ];
        }, $rows);
    }

    public function listLabels(array $uris = []): array
    {
        $ontologyGraph = self::ONTOLOGY_GRAPH;
        $values = array_filter($uris, fn ($uri) => is_string($uri) && $uri !== '');

        if (! empty($values)) {
            $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", array_unique($values)));
            $query = "
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                SELECT ?uri ?label
                WHERE {
                    GRAPH <{$ontologyGraph}> {
                        VALUES ?uri { {$iriList} }
                        ?uri rdfs:label ?label .
                    }
                }
            ";
        } else {
            $query = '
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                SELECT ?uri ?label
                WHERE {
                    GRAPH <'.self::ONTOLOGY_GRAPH.'> {
                        ?uri rdfs:label ?label .
                    }
                }
            ';
        }

        $rows = $this->graphService->query($query);
        $labels = [];
        foreach ($rows as $row) {
            if (! empty($row['label'])) {
                $labels[$row['uri']] = $row['label'];
            }
        }

        return $labels;
    }

    public function listIdentifiers(): array
    {
        $this->assertShapesPresent();
        $shapeGraphs = $this->shapeGraphValuesClause();

        $query = '
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT ?tbClass ?describedClass ?property
            WHERE {
                VALUES ?shapeGraph { '.$shapeGraphs.' }
                GRAPH ?shapeGraph {
                    ?shape sh:targetClass ?tbClass ;
                           sh:property ?propShape .
                    ?propShape sh:path ?property .
                }
                GRAPH <'.self::ONTOLOGY_GRAPH.'> {
                    ?property vwm:isIdentifier true .
                    ?tbClass vwm:beschrijftClass ?describedClass .
                }
            }
            ORDER BY ?tbClass ?property
        ';

        $rows = $this->graphService->query($query);
        $map = [];
        foreach ($rows as $row) {
            $tbClass = $row['tbClass'];
            if (! isset($map[$tbClass])) {
                $map[$tbClass] = [
                    'tb_class' => $tbClass,
                    'described_class' => $row['describedClass'],
                    'properties' => [],
                ];
            }
            $map[$tbClass]['properties'][] = $row['property'];
        }

        return array_values($map);
    }

    public function fetchSjabloon(string $sjabloonUri): array
    {
        $this->assertShapesPresent();
        $ontologyGraph = self::ONTOLOGY_GRAPH;
        $shapeGraphs = $this->shapeGraphValuesClause();

        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX ui: <http://ontologie.politie.nl/def/ui#>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
            PREFIX sh: <http://www.w3.org/ns/shacl#>

            SELECT ?sjabloonLabel ?label ?property ?datatype ?nodeKind ?order ?minCount ?targetClass
                   ?lookupEndpoint ?lookupQueryParam ?lookupSourceField ?lookupTrigger ?lookupDebounceMs ?lookupMinLength ?lookupClass
                   ?enumValue ?fieldWidth
            WHERE {
                BIND(<{$sjabloonUri}> as ?sjabloon)
                VALUES ?shapeGraph { {$shapeGraphs} }
                GRAPH ?shapeGraph {
                    ?shape sh:targetClass ?sjabloon ;
                           sh:property ?propShape .
                    ?propShape sh:path ?property .
                    OPTIONAL { ?propShape sh:datatype ?datatype . }
                    OPTIONAL { ?propShape sh:nodeKind ?nodeKind . }
                    OPTIONAL { ?propShape sh:order ?order . }
                    OPTIONAL { ?propShape sh:minCount ?minCount . }
                    OPTIONAL { ?propShape ui:lookupEndpoint ?lookupEndpoint . }
                    OPTIONAL { ?propShape ui:lookupQueryParam ?lookupQueryParam . }
                    OPTIONAL { ?propShape ui:lookupSourceField ?lookupSourceField . }
                    OPTIONAL { ?propShape ui:lookupTrigger ?lookupTrigger . }
                    OPTIONAL { ?propShape ui:lookupDebounceMs ?lookupDebounceMs . }
                    OPTIONAL { ?propShape ui:lookupMinLength ?lookupMinLength . }
                    OPTIONAL { ?propShape ui:lookupClass ?lookupClass . }
                    OPTIONAL { ?propShape ui:fieldWidth ?fieldWidth . }
                    OPTIONAL {
                        ?propShape sh:in ?enumList .
                        ?enumList rdf:rest*/rdf:first ?enumValue .
                    }
                }
                GRAPH <{$ontologyGraph}> {
                    OPTIONAL { ?sjabloon rdfs:label ?sjabloonLabel . }
                    OPTIONAL { ?sjabloon vwm:beschrijftClass ?targetClass . }
                    OPTIONAL { ?property rdfs:label ?label . }
                }
            }
            ORDER BY ?order ?label
        ";

        $rows = $this->graphService->query($query);
        $sjabloonLabel = null;
        $targetClass = null;
        foreach ($rows as $row) {
            if ($sjabloonLabel === null && ! empty($row['sjabloonLabel'])) {
                $sjabloonLabel = $row['sjabloonLabel'];
            }
            if ($targetClass === null && ! empty($row['targetClass'])) {
                $targetClass = $row['targetClass'];
            }
            if ($sjabloonLabel !== null && $targetClass !== null) {
                break;
            }
        }
        $velden = array_map(function ($row) {
            $property = $row['property'] ?? '';
            $label = $row['label'] ?? $this->shortId($property);
            $datatype = $row['datatype'] ?? null;
            $nodeKind = $row['nodeKind'] ?? null;
            $order = $row['order'] ?? null;
            $minCount = isset($row['minCount']) ? (int) $row['minCount'] : 0;
            $type = $this->mapVeldType($property, $datatype, $nodeKind);
            $lookupEndpoint = $row['lookupEndpoint'] ?? null;
            $lookupQueryParam = $row['lookupQueryParam'] ?? null;
            $lookupSourceField = $row['lookupSourceField'] ?? null;
            $lookupTrigger = $row['lookupTrigger'] ?? null;
            $lookupDebounceMs = isset($row['lookupDebounceMs']) ? (int) $row['lookupDebounceMs'] : null;
            $lookupMinLength = isset($row['lookupMinLength']) ? (int) $row['lookupMinLength'] : null;
            $lookupClass = $row['lookupClass'] ?? null;

            $hasLookupEndpoint = is_string($lookupEndpoint) && $lookupEndpoint !== '';
            $hasLookupClass = is_string($lookupClass) && $lookupClass !== '';

            $lookup = null;
            if ($hasLookupEndpoint || $hasLookupClass) {
                $lookup = [
                    'endpoint' => $hasLookupEndpoint ? $lookupEndpoint : null,
                    'query_param' => is_string($lookupQueryParam) && $lookupQueryParam !== '' ? $lookupQueryParam : null,
                    'source_field' => is_string($lookupSourceField) && $lookupSourceField !== '' ? $lookupSourceField : null,
                    'trigger' => is_string($lookupTrigger) && $lookupTrigger !== '' ? $lookupTrigger : null,
                    'debounce_ms' => is_int($lookupDebounceMs) && $lookupDebounceMs > 0 ? $lookupDebounceMs : null,
                    'min_length' => is_int($lookupMinLength) && $lookupMinLength > 0 ? $lookupMinLength : null,
                    'class_uri' => $hasLookupClass ? $lookupClass : null,
                ];
            }

            return [
                'label' => $label,
                'property' => $property,
                'type' => $type,
                'volgorde' => $order !== null ? (int) $order : 999,
                'required' => $minCount > 0,
                'field_width' => isset($row['fieldWidth']) && is_string($row['fieldWidth']) ? trim($row['fieldWidth']) : null,
                'lookup' => $lookup,
                'options' => isset($row['enumValue']) && $row['enumValue'] !== '' ? [$row['enumValue']] : [],
            ];
        }, $rows);

        $veldenByProperty = [];
        foreach ($velden as $veld) {
            $property = $veld['property'] ?? null;
            if (! is_string($property) || $property === '') {
                continue;
            }

            if (! isset($veldenByProperty[$property])) {
                $veldenByProperty[$property] = $veld;
                continue;
            }

            $current = $veldenByProperty[$property];
            $currentOrder = (int) ($current['volgorde'] ?? 999);
            $candidateOrder = (int) ($veld['volgorde'] ?? 999);

            // Prefer the most specific shape definition: lowest order first.
            if ($candidateOrder < $currentOrder) {
                $replacement = $veld;

                $replacementOptions = is_array($replacement['options'] ?? null) ? $replacement['options'] : [];
                $currentOptions = is_array($current['options'] ?? null) ? $current['options'] : [];
                $replacement['options'] = array_values(array_unique(array_merge($replacementOptions, $currentOptions)));

                if (
                    (! isset($replacement['field_width']) || $replacement['field_width'] === null || $replacement['field_width'] === '')
                    && isset($current['field_width'])
                    && is_string($current['field_width'])
                    && trim($current['field_width']) !== ''
                ) {
                    $replacement['field_width'] = trim($current['field_width']);
                }

                $replacementLookup = is_array($replacement['lookup'] ?? null) ? $replacement['lookup'] : [];
                $currentLookup = is_array($current['lookup'] ?? null) ? $current['lookup'] : [];
                foreach (['endpoint', 'query_param', 'source_field', 'trigger', 'debounce_ms', 'min_length', 'class_uri'] as $lookupKey) {
                    $hasReplacement = array_key_exists($lookupKey, $replacementLookup) && $replacementLookup[$lookupKey] !== null && $replacementLookup[$lookupKey] !== '';
                    $hasCurrent = array_key_exists($lookupKey, $currentLookup) && $currentLookup[$lookupKey] !== null && $currentLookup[$lookupKey] !== '';
                    if (! $hasReplacement && $hasCurrent) {
                        $replacementLookup[$lookupKey] = $currentLookup[$lookupKey];
                    }
                }
                $replacement['lookup'] = empty(array_filter($replacementLookup, fn ($value) => $value !== null && $value !== '')) ? null : $replacementLookup;

                $veldenByProperty[$property] = $replacement;
                continue;
            }

            // If order is equal, keep the required field definition when present.
            if ($candidateOrder === $currentOrder && ! empty($veld['required']) && empty($current['required'])) {
                $veldenByProperty[$property] = $veld;
            }

            $currentOptions = is_array($veldenByProperty[$property]['options'] ?? null) ? $veldenByProperty[$property]['options'] : [];
            $candidateOptions = is_array($veld['options'] ?? null) ? $veld['options'] : [];
            $mergedOptions = array_values(array_unique(array_merge($currentOptions, $candidateOptions)));
            $veldenByProperty[$property]['options'] = $mergedOptions;
            if (
                (! isset($veldenByProperty[$property]['field_width']) || $veldenByProperty[$property]['field_width'] === null || $veldenByProperty[$property]['field_width'] === '')
                && isset($veld['field_width'])
                && is_string($veld['field_width'])
                && trim($veld['field_width']) !== ''
            ) {
                $veldenByProperty[$property]['field_width'] = trim($veld['field_width']);
            }

            $currentLookup = $veldenByProperty[$property]['lookup'] ?? null;
            $candidateLookup = $veld['lookup'] ?? null;
            if (is_array($candidateLookup)) {
                if (! is_array($currentLookup)) {
                    $currentLookup = [];
                }

                foreach (['endpoint', 'query_param', 'source_field', 'trigger', 'debounce_ms', 'min_length', 'class_uri'] as $lookupKey) {
                    $hasCurrent = array_key_exists($lookupKey, $currentLookup) && $currentLookup[$lookupKey] !== null && $currentLookup[$lookupKey] !== '';
                    $hasCandidate = array_key_exists($lookupKey, $candidateLookup) && $candidateLookup[$lookupKey] !== null && $candidateLookup[$lookupKey] !== '';
                    if (! $hasCurrent && $hasCandidate) {
                        $currentLookup[$lookupKey] = $candidateLookup[$lookupKey];
                    }
                }

                $hasAnyLookup = false;
                foreach ($currentLookup as $value) {
                    if ($value !== null && $value !== '') {
                        $hasAnyLookup = true;
                        break;
                    }
                }
                $veldenByProperty[$property]['lookup'] = $hasAnyLookup ? $currentLookup : null;
            }
        }

        $velden = array_values($veldenByProperty);
        foreach ($velden as &$veld) {
            if (! is_array($veld['options'] ?? null)) {
                continue;
            }
            $veld['options'] = array_values(array_unique(array_filter(array_map(
                fn ($value) => is_string($value) ? trim($value) : '',
                $veld['options']
            ), fn ($value) => $value !== '')));
        }
        unset($veld);

        usort($velden, function ($a, $b) {
            $order = ($a['volgorde'] ?? 999) <=> ($b['volgorde'] ?? 999);
            if ($order !== 0) {
                return $order;
            }

            return strcmp($a['label'] ?? '', $b['label'] ?? '');
        });

        return [
            'sjabloon_label' => $sjabloonLabel,
            'target_class' => $targetClass,
            'velden' => $velden,
        ];
    }

    public function fetchSjabloonButtonLabelsByTbClasses(array $tbClasses): array
    {
        $this->assertShapesPresent();
        $uris = array_values(array_unique(array_filter(
            $tbClasses,
            fn ($uri) => is_string($uri) && $uri !== ''
        )));

        if (empty($uris)) {
            return [];
        }

        $shapeGraphs = $this->shapeGraphValuesClause();
        $values = implode(' ', array_map(fn ($uri) => "<{$uri}>", $uris));
        $query = "
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            PREFIX ui: <http://ontologie.politie.nl/def/ui#>
            SELECT ?tbClass ?buttonLabelRegister ?buttonLabelAttach
            WHERE {
                VALUES ?tbClass { {$values} }
                VALUES ?shapeGraph { {$shapeGraphs} }
                GRAPH ?shapeGraph {
                    ?shape sh:targetClass ?tbClass .
                    OPTIONAL { ?shape ui:buttonLabelRegister ?buttonLabelRegister . }
                    OPTIONAL { ?shape ui:buttonLabelAttach ?buttonLabelAttach . }
                }
            }
        ";

        $rows = $this->graphService->query($query);
        $map = [];
        foreach ($rows as $row) {
            $tbClass = $row['tbClass'] ?? null;
            if (! is_string($tbClass) || $tbClass === '') {
                continue;
            }

            if (! isset($map[$tbClass])) {
                $map[$tbClass] = [
                    'button_label_register' => null,
                    'button_label_attach' => null,
                ];
            }

            $registerLabel = $row['buttonLabelRegister'] ?? null;
            $attachLabel = $row['buttonLabelAttach'] ?? null;

            if (is_string($registerLabel) && trim($registerLabel) !== '') {
                $map[$tbClass]['button_label_register'] = trim($registerLabel);
            }
            if (is_string($attachLabel) && trim($attachLabel) !== '') {
                $map[$tbClass]['button_label_attach'] = trim($attachLabel);
            }
        }

        return $map;
    }

    public function fetchRelatieRegels(): array
    {
        $query = '
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?vanClass ?naarClass ?predicate
            WHERE {
                GRAPH <'.self::ONTOLOGY_GRAPH.'> {
                    ?regel a vwm:RelatieRegel ;
                           vwm:vanClass ?vanClass ;
                           vwm:naarClass ?naarClass ;
                           vwm:predicate ?predicate .
                }
            }
        ';

        return $this->graphService->query($query);
    }

    public function fetchRoleShapeRules(): array
    {
        $shapeGraphs = $this->shapeGraphValuesClause();

        $query = '
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT ?rolType ?rolTbClass ?vanClass ?naarClass ?vanProperty ?naarProperty
            WHERE {
                GRAPH <'.self::ONTOLOGY_GRAPH.'> {
                    ?rolType a vwm:RolType .
                }
                VALUES ?shapeGraph { '.$shapeGraphs.' }
                GRAPH ?shapeGraph {
                    ?shape a sh:NodeShape ;
                           sh:targetNode ?rolType ;
                           vwm:rolTbClass ?rolTbClass ;
                           vwm:vanClass ?vanClass ;
                           vwm:naarClass ?naarClass ;
                           vwm:vanProperty ?vanProperty ;
                           vwm:naarProperty ?naarProperty .
                }
            }
        ';

        $rows = $this->graphService->query($query);
        $regels = [];
        foreach ($rows as $row) {
            $regels[$row['rolType']] = $row;
        }

        return $regels;
    }

    public function fetchAutoRoleRules(): array
    {
        $shapeGraphs = $this->shapeGraphValuesClause();
        $query = '
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT ?triggerTbClass ?rolType
            WHERE {
                VALUES ?shapeGraph { '.$shapeGraphs.' }
                GRAPH ?shapeGraph {
                    ?shape a sh:NodeShape ;
                           vwm:autoRoleForTbClass ?triggerTbClass ;
                           vwm:autoRoleType ?rolType .
                }
            }
        ';

        $rows = $this->graphService->query($query);
        $rules = [];
        foreach ($rows as $row) {
            $triggerTbClass = $row['triggerTbClass'] ?? null;
            $rolType = $row['rolType'] ?? null;
            if (! is_string($triggerTbClass) || $triggerTbClass === '' || ! is_string($rolType) || $rolType === '') {
                continue;
            }
            $rules[] = [
                'triggerTbClass' => $triggerTbClass,
                'rolType' => $rolType,
            ];
        }

        return $rules;
    }

    public function fetchAutoRoleInvalidationRules(): array
    {
        $shapeGraphs = $this->shapeGraphValuesClause();
        $query = '
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT ?triggerTbClass ?rolType
            WHERE {
                VALUES ?shapeGraph { '.$shapeGraphs.' }
                GRAPH ?shapeGraph {
                    ?shape a sh:NodeShape ;
                           vwm:autoInvalidateRoleForTbClass ?triggerTbClass ;
                           vwm:autoInvalidateRoleType ?rolType .
                }
            }
        ';

        $rows = $this->graphService->query($query);
        $rules = [];
        foreach ($rows as $row) {
            $triggerTbClass = $row['triggerTbClass'] ?? null;
            $rolType = $row['rolType'] ?? null;
            if (! is_string($triggerTbClass) || $triggerTbClass === '' || ! is_string($rolType) || $rolType === '') {
                continue;
            }
            $rules[] = [
                'triggerTbClass' => $triggerTbClass,
                'rolType' => $rolType,
            ];
        }

        return $rules;
    }

    public function fetchRolTbMetaByClasses(array $tbClasses): array
    {
        $ontologyGraph = self::ONTOLOGY_GRAPH;
        $values = array_filter(array_unique(array_values($tbClasses)));
        if (empty($values)) {
            return [];
        }

        $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", $values));
        $shapeGraphs = $this->shapeGraphValuesClause();
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT DISTINCT ?tbClass ?vanClass ?naarClass ?vanProperty ?naarProperty ?label
            WHERE {
                VALUES ?tbClass { {$iriList} }
                OPTIONAL {
                    GRAPH <{$ontologyGraph}> {
                        ?tbClass rdfs:label ?label .
                    }
                }
                {
                    GRAPH <{$ontologyGraph}> {
                        ?tbClass vwm:vanClass ?vanClass ;
                                 vwm:naarClass ?naarClass ;
                                 vwm:vanProperty ?vanProperty ;
                                 vwm:naarProperty ?naarProperty .
                    }
                }
                UNION
                {
                    VALUES ?shapeGraph { {$shapeGraphs} }
                    GRAPH ?shapeGraph {
                        ?shape a sh:NodeShape ;
                               vwm:rolTbClass ?tbClass ;
                               vwm:vanClass ?vanClass ;
                               vwm:naarClass ?naarClass ;
                               vwm:vanProperty ?vanProperty ;
                               vwm:naarProperty ?naarProperty .
                    }
                }
            }
        ";

        $rows = $this->graphService->query($query);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['tbClass']] = [
                'rolTbClass' => $row['tbClass'],
                'vanClass' => $row['vanClass'],
                'naarClass' => $row['naarClass'],
                'vanProperty' => $row['vanProperty'],
                'naarProperty' => $row['naarProperty'],
                'label' => $row['label'] ?? null,
            ];
        }

        return $map;
    }

    public function fetchDescribedClassByTbClasses(array $tbClasses): array
    {
        $ontologyGraph = self::ONTOLOGY_GRAPH;
        $values = array_filter(array_unique(array_values($tbClasses)));
        if (empty($values)) {
            return [];
        }

        $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", $values));
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?tbClass ?describedClass
            WHERE {
                GRAPH <{$ontologyGraph}> {
                    VALUES ?tbClass { {$iriList} }
                    ?tbClass vwm:beschrijftClass ?describedClass .
                }
            }
        ";

        $rows = $this->graphService->query($query);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['tbClass']] = $row['describedClass'];
        }

        return $map;
    }

    public function fetchPropertyValueHintsByTbClasses(array $tbClasses): array
    {
        $values = array_filter(array_unique(array_values($tbClasses)));
        if (empty($values)) {
            return [];
        }

        $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", $values));
        $shapeGraphs = $this->shapeGraphValuesClause();
        $query = "
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT ?tbClass ?property ?datatype ?nodeKind
            WHERE {
                VALUES ?shapeGraph { {$shapeGraphs} }
                GRAPH ?shapeGraph {
                    VALUES ?tbClass { {$iriList} }
                    ?shape a sh:NodeShape ;
                           sh:targetClass ?tbClass ;
                           sh:property ?propShape .
                    ?propShape sh:path ?property .
                    OPTIONAL { ?propShape sh:datatype ?datatype . }
                    OPTIONAL { ?propShape sh:nodeKind ?nodeKind . }
                }
            }
        ";

        $rows = $this->graphService->query($query);
        $map = [];
        foreach ($rows as $row) {
            $tbClass = $row['tbClass'] ?? null;
            $property = $row['property'] ?? null;
            if (! $tbClass || ! $property) {
                continue;
            }

            $hint = 'literal';
            if (($row['nodeKind'] ?? null) === 'http://www.w3.org/ns/shacl#IRI') {
                $hint = 'uri';
            } else {
                $hint = match ($row['datatype'] ?? null) {
                    'http://www.w3.org/2001/XMLSchema#integer' => 'integer',
                    'http://www.w3.org/2001/XMLSchema#decimal' => 'decimal',
                    'http://www.w3.org/2001/XMLSchema#date' => 'date',
                    'http://www.w3.org/2001/XMLSchema#dateTime' => 'dateTime',
                    default => 'literal',
                };
            }

            $current = $map[$tbClass][$property] ?? null;
            $map[$tbClass][$property] = $this->preferValueHint($current, $hint);
        }

        return $map;
    }

    private function preferValueHint(?string $current, string $candidate): string
    {
        $rank = static function (?string $hint): int {
            return match ($hint) {
                'uri' => 4,
                'dateTime', 'date', 'decimal', 'integer' => 3,
                'literal' => 1,
                default => 0,
            };
        };

        return $rank($candidate) > $rank($current) ? $candidate : (string) $current;
    }

    public function fetchIdentityRulesByTbClasses(array $tbClasses): array
    {
        $values = array_filter(array_unique(array_values($tbClasses)));
        if (empty($values)) {
            return [];
        }

        $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", $values));
        $shapeGraphs = $this->shapeGraphValuesClause();
        $query = "
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?tbClass ?property ?normalizer ?order
            WHERE {
                VALUES ?shapeGraph { {$shapeGraphs} }
                GRAPH ?shapeGraph {
                    VALUES ?tbClass { {$iriList} }
                    ?shape a sh:NodeShape ;
                           sh:targetClass ?tbClass ;
                           sh:property ?propShape .
                    ?propShape sh:path ?property ;
                               vwm:isIdentityKey true .
                    OPTIONAL { ?propShape vwm:identityNormalizer ?normalizer . }
                    OPTIONAL { ?propShape sh:order ?order . }
                }
            }
            ORDER BY ?tbClass ?order ?property
        ";

        $rows = $this->graphService->query($query);
        $map = [];
        foreach ($rows as $row) {
            $tbClass = $row['tbClass'] ?? null;
            $property = $row['property'] ?? null;
            if (! is_string($tbClass) || $tbClass === '' || ! is_string($property) || $property === '') {
                continue;
            }

            $map[$tbClass][] = [
                'property' => $property,
                'normalizer' => is_string($row['normalizer'] ?? null) && $row['normalizer'] !== ''
                    ? $row['normalizer']
                    : 'NONE',
                'order' => isset($row['order']) ? (int) $row['order'] : 999,
            ];
        }

        return $map;
    }

    public function fetchRolTypesByKey(): array
    {
        $query = '
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?roleKey ?rolType
            WHERE {
                GRAPH <'.self::ONTOLOGY_GRAPH.'> {
                    ?rolType a vwm:RolType ;
                             vwm:roleKey ?roleKey .
                }
            }
        ';

        $rows = $this->graphService->query($query);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['roleKey']] = $row['rolType'];
        }

        return $map;
    }

    public function fetchTbClassesByDescribedClass(string $describedClassUri): array
    {
        if ($describedClassUri === '') {
            return [];
        }

        $ontologyGraph = self::ONTOLOGY_GRAPH;
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?tbClass
            WHERE {
                GRAPH <{$ontologyGraph}> {
                    ?tbClass vwm:beschrijftClass <{$describedClassUri}> .
                }
            }
        ";

        $rows = $this->graphService->query($query);
        $classes = [];
        foreach ($rows as $row) {
            if (! empty($row['tbClass'])) {
                $classes[] = $row['tbClass'];
            }
        }

        return array_values(array_unique($classes));
    }

    public function fetchAllowedRoleTbClasses(int $transactieSoortId, array $roleShapeRulesByType = []): array
    {
        $selectors = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieSoortId)
            ->where('type', 'rol')
            ->orderBy('volgorde')
            ->pluck('sjabloon_uri')
            ->all();

        $classes = [];
        foreach ($selectors as $selectorUri) {
            if (! is_string($selectorUri) || $selectorUri === '') {
                continue;
            }

            $classes[] = $selectorUri;

            if (isset($roleShapeRulesByType[$selectorUri]['rolTbClass'])) {
                $classes[] = $roleShapeRulesByType[$selectorUri]['rolTbClass'];

                continue;
            }

            $resolved = $this->resolveRoleShapeRuleFromSelector($selectorUri, $roleShapeRulesByType);
            if (! empty($resolved['rolTbClass'])) {
                $classes[] = $resolved['rolTbClass'];
            }
        }

        return array_values(array_unique($classes));
    }

    public function resolveRoleShapeRuleFromSelector(?string $selectorUri, array $roleShapeRulesByType): ?array
    {
        if (! is_string($selectorUri) || $selectorUri === '') {
            return null;
        }

        if (isset($roleShapeRulesByType[$selectorUri])) {
            return $roleShapeRulesByType[$selectorUri];
        }

        $candidates = [
            str_replace('#Rol_', '#RolType_', $selectorUri),
            str_replace('#RolRegel_', '#RolType_', $selectorUri),
        ];

        foreach (array_unique($candidates) as $candidate) {
            if (isset($roleShapeRulesByType[$candidate])) {
                return $roleShapeRulesByType[$candidate];
            }
        }

        $selectorSuffix = $this->roleSuffix($selectorUri);
        if ($selectorSuffix === '') {
            return null;
        }

        $match = null;
        foreach ($roleShapeRulesByType as $roleTypeUri => $regel) {
            if ($this->roleSuffix((string) $roleTypeUri) !== $selectorSuffix) {
                continue;
            }

            if ($match !== null) {
                return null;
            }
            $match = $regel;
        }

        return $match;
    }

    public function shortId(string $uri): string
    {
        if ($uri === '') {
            return '';
        }

        $trimmed = rtrim($uri, '/');
        if (str_contains($trimmed, '#')) {
            $parts = explode('#', $trimmed);

            return $parts[count($parts) - 1] ?? $uri;
        }

        $parts = explode('/', $trimmed);

        return $parts[count($parts) - 1] ?? $uri;
    }

    private function mapVeldType(string $property, ?string $datatype, ?string $nodeKind): string
    {
        if ($property === 'http://ontologie.politie.nl/def/vwm#heeftBestand') {
            return 'file';
        }

        if ($datatype === 'http://www.w3.org/2001/XMLSchema#date') {
            return 'date';
        }

        if ($datatype === 'http://www.w3.org/2001/XMLSchema#dateTime') {
            return 'datetime-local';
        }

        if (in_array($datatype, [
            'http://www.w3.org/2001/XMLSchema#integer',
            'http://www.w3.org/2001/XMLSchema#decimal',
        ], true)) {
            return 'number';
        }

        if ($nodeKind === 'http://www.w3.org/ns/shacl#IRI') {
            return 'url';
        }

        return 'text';
    }

    private function assertShapesPresent(): void
    {
        $shapeGraphs = $this->shapeGraphValuesClause();
        $query = '
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT (COUNT(?shape) AS ?shapeCount)
            WHERE {
                VALUES ?shapeGraph { '.$shapeGraphs.' }
                GRAPH ?shapeGraph {
                    ?shape a sh:NodeShape .
                }
            }
        ';

        $rows = $this->graphService->query($query);
        $count = (int) ($rows[0]['shapeCount'] ?? 0);
        if ($count === 0) {
            throw new \RuntimeException('Geen SHACL shapes gevonden in GraphDB. Laad shapes in de named graphs /model/shapes/*.');
        }
    }

    private function shapeGraphValuesClause(): string
    {
        return implode(' ', array_map(fn (string $graph) => "<{$graph}>", self::SHAPE_GRAPHS));
    }

    private function roleSuffix(string $uri): string
    {
        $local = strtolower($this->shortId($uri));
        if ($local === '') {
            return '';
        }

        $parts = explode('_', $local);
        $suffix = end($parts);

        return is_string($suffix) ? $suffix : $local;
    }
}
