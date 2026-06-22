<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class GoicDisplayService
{
    public function __construct(
        private readonly GraphService $graphService,
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

        $labels = [];
        foreach ($uris as $uri) {
            $goicId = $goicByUri[$uri] ?? null;
            if (! is_int($goicId) || $goicId <= 0) {
                $labels[$uri] = $this->resolveGoicLabelFromGraph($uri) ?? "GOIC {$this->shortId($uri)}";

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

                $kenteken = $this->extractValueBySuffix($data, '#licensePlate')
                    ?? $this->extractValueBySuffix($data, 'licensePlate')
                    ?? $this->extractValueBySuffix($data, '#kenteken')
                    ?? $this->extractValueBySuffix($data, 'kenteken');

                if (is_string($kenteken) && trim($kenteken) !== '') {
                    $labels[$uri] = 'Voertuig: '.trim($kenteken);

                    continue 2;
                }
            }

            $labels[$uri] = $label;
        }

        return $labels;
    }

    private function extractValueBySuffix(array $data, string $suffix): ?string
    {
        foreach ($data as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, $suffix)) {
                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
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

    private function resolveGoicLabelFromGraph(string $goicUri): ?string
    {
        if (! str_contains($goicUri, '/data/goic/')) {
            return null;
        }

        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
            SELECT ?plate ?brand ?model
            WHERE {
                ?tb vwm:beschrijftGOIC <{$goicUri}> .
                OPTIONAL { ?tb dpm:licensePlate ?plate . }
                OPTIONAL { ?tb dpm:brand ?brand . }
                OPTIONAL { ?tb dpm:model ?model . }
                OPTIONAL { ?tb vwm:geregistreerdOp ?at . }
            }
            ORDER BY DESC(?at)
            LIMIT 1
        ";

        try {
            $rows = $this->graphService->query($query);
        } catch (Throwable) {
            return null;
        }

        $plate = $rows[0]['plate'] ?? null;
        if (is_string($plate) && trim($plate) !== '') {
            return 'Voertuig: '.trim($plate);
        }

        $brand = is_string($rows[0]['brand'] ?? null) ? trim((string) $rows[0]['brand']) : '';
        $model = is_string($rows[0]['model'] ?? null) ? trim((string) $rows[0]['model']) : '';
        if ($brand !== '' || $model !== '') {
            return 'Voertuig: '.trim("{$brand} {$model}");
        }

        return null;
    }
}
