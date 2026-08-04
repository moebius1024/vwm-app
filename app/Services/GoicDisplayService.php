<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class GoicDisplayService
{
    public function __construct(
        private readonly GraphService $graphService,
        private readonly SjabloonMetadataService $metadataService,
    ) {}

    /**
     * @param  array<int, string>  $uris
     * @return array<string, string>
     */
    public function resolveLabels(array $uris, int $userId): array
    {
        $uris = array_values(array_unique(array_filter($uris, function ($uri) {
            return is_string($uri) && str_contains($uri, '/data/goic/');
        })));

        if (empty($uris)) {
            return [];
        }

        $goics = DB::table('gegevens_objecten_in_context')
            ->join('dossiers', 'dossiers.id', '=', 'gegevens_objecten_in_context.dossier_id')
            ->join('cases', 'cases.id', '=', 'dossiers.case_id')
            ->where('cases.user_id', $userId)
            ->whereIn('gegevens_objecten_in_context.rdf_uri', $uris)
            ->get([
                'gegevens_objecten_in_context.id as goic_id',
                'gegevens_objecten_in_context.rdf_uri as goic_uri',
            ]);

        $goicByUri = [];
        foreach ($goics as $row) {
            $goicByUri[$row->goic_uri] = (int) $row->goic_id;
        }

        $identifierMetadata = $this->identifierMetadataByTbClass();
        $labels = [];
        foreach ($uris as $uri) {
            $goicId = $goicByUri[$uri] ?? null;
            if (! is_int($goicId) || $goicId <= 0) {
                $labels[$uri] = $this->resolveGoicLabelFromGraph($uri, $identifierMetadata) ?? "GOIC {$this->shortId($uri)}";

                continue;
            }

            $label = "GOIC {$this->shortId($uri)}";
            $rows = DB::table('object_mutaties')
                ->where('gegevens_object_in_context_id', $goicId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get(['sjabloon_uri', 'data']);

            foreach ($rows as $row) {
                $data = json_decode((string) ($row->data ?? '{}'), true);
                if (! is_array($data)) {
                    continue;
                }

                $display = $this->displayForStateData(
                    (string) ($row->sjabloon_uri ?? ''),
                    $data,
                    $identifierMetadata,
                );
                if ($display !== null) {
                    $labels[$uri] = $display;

                    continue 2;
                }
            }

            $labels[$uri] = $this->resolveGoicLabelFromGraph($uri, $identifierMetadata) ?? $label;
        }

        return $labels;
    }

    /**
     * @return array<string, array{class_label:string,properties:array<int, string>,primary_display_properties:array<int, array{property:string,label:?string}>}>
     */
    private function identifierMetadataByTbClass(): array
    {
        try {
            $metadata = $this->metadataService->listIdentifiers();
        } catch (Throwable) {
            return [];
        }

        $describedClasses = array_values(array_unique(array_filter(array_column($metadata, 'described_class'), 'is_string')));
        try {
            $classLabels = $this->metadataService->listLabels($describedClasses);
        } catch (Throwable) {
            $classLabels = [];
        }

        $byTbClass = [];
        foreach ($metadata as $entry) {
            $tbClass = $entry['tb_class'] ?? null;
            $describedClass = $entry['described_class'] ?? null;
            $properties = $entry['properties'] ?? null;
            if (! is_string($tbClass) || ! is_string($describedClass) || ! is_array($properties)) {
                continue;
            }

            $primaryDisplayProperties = $entry['primary_display_properties'] ?? [];
            if ($primaryDisplayProperties === [] && is_string($entry['primary_display_property'] ?? null)) {
                $primaryDisplayProperties = [[
                    'property' => $entry['primary_display_property'],
                    'label' => $entry['primary_display_label'] ?? null,
                ]];
            }

            $byTbClass[$tbClass] = [
                'class_label' => is_string($classLabels[$describedClass] ?? null)
                    ? $classLabels[$describedClass]
                    : $this->shortId($describedClass),
                'properties' => array_values(array_filter($properties, 'is_string')),
                'primary_display_properties' => array_values(array_filter(
                    $primaryDisplayProperties,
                    fn (mixed $property): bool => is_array($property) && is_string($property['property'] ?? null),
                )),
            ];
        }

        return $byTbClass;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{class_label:string,properties:array<int, string>,primary_display_properties:array<int, array{property:string,label:?string}>}>  $identifierMetadata
     */
    private function displayForStateData(string $tbClass, array $data, array $identifierMetadata, ?string $targetClassLabel = null): ?string
    {
        $identifier = $identifierMetadata[$tbClass] ?? null;
        if (! is_array($identifier)) {
            return null;
        }

        $primaryDisplayParts = [];
        foreach ($identifier['primary_display_properties'] as $primaryDisplayProperty) {
            $property = $primaryDisplayProperty['property'];
            $value = $data[$property] ?? null;
            if (! is_scalar($value) || trim((string) $value) === '') {
                $primaryDisplayParts = [];

                break;
            }

            $label = $primaryDisplayProperty['label'] ?? $this->shortId($property);
            $primaryDisplayParts[] = "{$label} ".trim((string) $value);
        }
        if ($primaryDisplayParts !== []) {
            return implode(' ', $primaryDisplayParts);
        }

        $values = [];
        foreach ($identifier['properties'] as $property) {
            $value = $data[$property] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $values[] = trim($value);
        }

        $values = array_values(array_unique($values));

        if ($values === []) {
            return null;
        }

        $classLabel = $targetClassLabel ?: $identifier['class_label'];

        return "{$classLabel}: ".implode(', ', $values);
    }

    private function shortId(string $uri): string
    {
        $trimmed = str_ends_with($uri, '/') ? substr($uri, 0, -1) : $uri;
        if (str_contains($trimmed, '#')) {
            $parts = explode('#', $trimmed);

            return (string) end($parts);
        }

        $parts = explode('/', $trimmed);

        return (string) end($parts);
    }

    /**
     * @param  array<string, array{class_label:string,properties:array<int, string>,primary_display_properties:array<int, array{property:string,label:?string}>}>  $identifierMetadata
     */
    private function resolveGoicLabelFromGraph(string $goicUri, array $identifierMetadata): ?string
    {
        if (! str_contains($goicUri, '/data/goic/')) {
            return null;
        }

        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            SELECT ?tb ?tbClass ?targetClass ?targetClassLabel ?property ?value
            WHERE {
                <{$goicUri}> vwm:heeftDoelClass ?targetClass .
                OPTIONAL { ?targetClass rdfs:label ?targetClassLabel . }
                ?tb vwm:beschrijftGOIC <{$goicUri}> ;
                    a ?tbClass ;
                    ?property ?value .
                FILTER(isLiteral(?value))
                FILTER NOT EXISTS { ?tb dpm:invalidatedAtTime ?invalidatedAt . }
            }
            ORDER BY ?tb ?property
        ";

        try {
            $rows = $this->graphService->query($query);
        } catch (Throwable) {
            return null;
        }

        $states = [];
        foreach ($rows as $row) {
            $tbUri = $row['tb'] ?? null;
            $tbClass = $row['tbClass'] ?? null;
            $property = $row['property'] ?? null;
            $value = $row['value'] ?? null;
            if (! is_string($tbUri) || ! is_string($tbClass) || ! is_string($property) || ! is_string($value)) {
                continue;
            }

            $states[$tbUri] ??= [
                'tb_class' => $tbClass,
                'target_class_label' => is_string($row['targetClassLabel'] ?? null) ? $row['targetClassLabel'] : null,
                'data' => [],
            ];
            $states[$tbUri]['data'][$property] = $value;
        }

        foreach ($states as $state) {
            $display = $this->displayForStateData(
                $state['tb_class'],
                $state['data'],
                $identifierMetadata,
                $state['target_class_label'],
            );
            if ($display !== null) {
                return $display;
            }
        }

        return null;
    }
}
