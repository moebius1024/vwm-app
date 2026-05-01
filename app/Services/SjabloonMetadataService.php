<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SjabloonMetadataService
{
    public function __construct(private readonly GraphService $graphService) {}

    public function listSjablonen(): array
    {
        $query = '
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

            SELECT ?sjabloon ?label ?targetClass
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
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
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
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
        $values = array_filter($uris, fn ($uri) => is_string($uri) && $uri !== '');

        if (! empty($values)) {
            $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", array_unique($values)));
            $query = "
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                SELECT ?uri ?label
                WHERE {
                    GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
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
                    GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
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

        $query = '
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT ?tbClass ?describedClass ?property
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    ?shape sh:targetClass ?tbClass ;
                           sh:property ?propShape .
                    ?propShape sh:path ?property .
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

        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            PREFIX sh: <http://www.w3.org/ns/shacl#>

            SELECT ?sjabloonLabel ?label ?property ?datatype ?nodeKind ?order ?minCount ?targetClass
                   ?lookupEndpoint ?lookupQueryParam ?lookupSourceField ?lookupTrigger ?lookupDebounceMs ?lookupMinLength
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    BIND(<{$sjabloonUri}> as ?sjabloon)
                    OPTIONAL { ?sjabloon rdfs:label ?sjabloonLabel . }
                    OPTIONAL { ?sjabloon vwm:beschrijftClass ?targetClass . }
                    ?shape sh:targetClass ?sjabloon ;
                           sh:property ?propShape .
                    ?propShape sh:path ?property .
                    OPTIONAL { ?propShape sh:datatype ?datatype . }
                    OPTIONAL { ?propShape sh:nodeKind ?nodeKind . }
                    OPTIONAL { ?propShape sh:order ?order . }
                    OPTIONAL { ?propShape sh:minCount ?minCount . }
                    OPTIONAL { ?propShape vwm:lookupEndpoint ?lookupEndpoint . }
                    OPTIONAL { ?propShape vwm:lookupQueryParam ?lookupQueryParam . }
                    OPTIONAL { ?propShape vwm:lookupSourceField ?lookupSourceField . }
                    OPTIONAL { ?propShape vwm:lookupTrigger ?lookupTrigger . }
                    OPTIONAL { ?propShape vwm:lookupDebounceMs ?lookupDebounceMs . }
                    OPTIONAL { ?propShape vwm:lookupMinLength ?lookupMinLength . }
                    OPTIONAL { ?property rdfs:label ?label . }
                }
            }
            ORDER BY ?order ?label
        ";

        $rows = $this->graphService->query($query);
        $sjabloonLabel = $rows[0]['sjabloonLabel'] ?? null;
        $targetClass = $rows[0]['targetClass'] ?? null;
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

            $lookup = null;
            if (is_string($lookupEndpoint) && $lookupEndpoint !== '') {
                $lookup = [
                    'endpoint' => $lookupEndpoint,
                    'query_param' => is_string($lookupQueryParam) && $lookupQueryParam !== '' ? $lookupQueryParam : null,
                    'source_field' => is_string($lookupSourceField) && $lookupSourceField !== '' ? $lookupSourceField : null,
                    'trigger' => is_string($lookupTrigger) && $lookupTrigger !== '' ? $lookupTrigger : null,
                    'debounce_ms' => is_int($lookupDebounceMs) && $lookupDebounceMs > 0 ? $lookupDebounceMs : null,
                    'min_length' => is_int($lookupMinLength) && $lookupMinLength > 0 ? $lookupMinLength : null,
                ];
            }

            return [
                'label' => $label,
                'property' => $property,
                'type' => $type,
                'volgorde' => $order !== null ? (int) $order : 999,
                'required' => $minCount > 0,
                'lookup' => $lookup,
            ];
        }, $rows);

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

    public function fetchRelatieRegels(): array
    {
        $query = '
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?vanClass ?naarClass ?predicate
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
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
        $query = '
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT ?rolType ?rolTbClass ?vanClass ?naarClass ?vanProperty ?naarProperty
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    ?rolType a vwm:RolType .
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

    public function fetchRolTbMetaByClasses(array $tbClasses): array
    {
        $values = array_filter(array_unique(array_values($tbClasses)));
        if (empty($values)) {
            return [];
        }

        $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", $values));
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT DISTINCT ?tbClass ?vanClass ?naarClass ?vanProperty ?naarProperty ?label
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    VALUES ?tbClass { {$iriList} }
                    OPTIONAL { ?tbClass rdfs:label ?label . }
                    {
                        ?tbClass vwm:vanClass ?vanClass ;
                                 vwm:naarClass ?naarClass ;
                                 vwm:vanProperty ?vanProperty ;
                                 vwm:naarProperty ?naarProperty .
                    }
                    UNION
                    {
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
        $values = array_filter(array_unique(array_values($tbClasses)));
        if (empty($values)) {
            return [];
        }

        $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", $values));
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?tbClass ?describedClass
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
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
        $query = "
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT ?tbClass ?property ?datatype ?nodeKind
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
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

            $map[$tbClass][$property] = $hint;
        }

        return $map;
    }

    public function fetchIdentityRulesByTbClasses(array $tbClasses): array
    {
        $values = array_filter(array_unique(array_values($tbClasses)));
        if (empty($values)) {
            return [];
        }

        $iriList = implode(' ', array_map(fn ($uri) => "<{$uri}>", $values));
        $query = "
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?tbClass ?property ?normalizer ?order
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
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
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
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

        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?tbClass
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
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
        $query = '
            PREFIX sh: <http://www.w3.org/ns/shacl#>
            SELECT (COUNT(?shape) AS ?shapeCount)
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/model/ontologie> {
                    ?shape a sh:NodeShape .
                }
            }
        ';

        $rows = $this->graphService->query($query);
        $count = (int) ($rows[0]['shapeCount'] ?? 0);
        if ($count === 0) {
            throw new \RuntimeException('Geen SHACL shapes gevonden in GraphDB. Laad Docs/shapes.ttl in de ontologie-graph.');
        }
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
