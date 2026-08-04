<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StateDeletionService
{
    public function __construct(
        private readonly GraphService $graphService,
        private readonly SjabloonMetadataService $metadataService,
        private readonly RoleMutationService $roleMutationService,
        private readonly AutoRoleMutationService $autoRoleMutationService,
    ) {}

    /**
     * @param  array{delete_type:string,target:array{goic_id:int,mutatie_id:int,tb_rdf_uri:?string,sjabloon_uri:?string}}  $deletePayload
     * @param  array<string, mixed>  $base
     * @param  array<string, array<string, mixed>>  $roleShapeRules
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function delete(array $deletePayload, array $base, int $dossierId, int $userId, array $roleShapeRules): array
    {
        $target = $deletePayload['target'];
        $targetRow = DB::table('object_mutaties')
            ->join('gegevens_objecten_in_context', 'gegevens_objecten_in_context.id', '=', 'object_mutaties.gegevens_object_in_context_id')
            ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
            ->where('object_mutaties.id', (int) $target['mutatie_id'])
            ->where('gegevens_objecten_in_context.id', (int) $target['goic_id'])
            ->where('gegevens_objecten_in_context.dossier_id', $dossierId)
            ->first([
                'object_mutaties.id as mutatie_id',
                'object_mutaties.sjabloon_uri as tb_class',
                'object_mutaties.data as tb_data',
                'toestands_beschrijvingen.id as tb_id',
                'toestands_beschrijvingen.rdf_uri as tb_uri',
                'gegevens_objecten_in_context.id as goic_id',
                'gegevens_objecten_in_context.rdf_uri as goic_uri',
            ]);

        if (! $targetRow || ! is_string($targetRow->tb_uri) || $targetRow->tb_uri === '') {
            return $this->result(['error' => 'Doel voor verwijderen niet gevonden.'], 422);
        }

        $deleteType = (string) ($deletePayload['delete_type'] ?? '');
        if ($deleteType === 'role') {
            if (! $this->roleMutationService->isRoleDeleteAllowed((int) $base['transactie_soort_id'], (string) ($targetRow->tb_class ?? ''), $roleShapeRules)) {
                return $this->result(['error' => 'Verwijderen niet toegestaan voor deze rol in deze transactie.'], 422);
            }
        } else {
            if ($this->roleMutationService->isRoleTbClass((string) ($targetRow->tb_class ?? ''), $roleShapeRules)) {
                return $this->result(['error' => 'Gebruik rol-verwijderen voor roltoestanden.'], 422);
            }
            if (
                ! $this->isDependentStateData((string) ($targetRow->tb_data ?? ''))
                && ! $this->isClassDeleteAllowed((int) $base['transactie_soort_id'], (string) ($targetRow->tb_class ?? ''))
            ) {
                return $this->result(['error' => 'Verwijderen niet toegestaan voor dit sjabloon in deze transactie.'], 422);
            }
        }

        $now = now();
        $nowIso = $now->toAtomString();
        $vwm = 'http://ontologie.politie.nl/def/vwm#';
        $dpm = 'http://ontologie.politie.nl/def/dpm#';

        DB::beginTransaction();
        try {
            $transactieId = DB::table('transacties')->insertGetId([
                'case_id' => $base['case_id'],
                'transactie_soort_id' => $base['transactie_soort_id'],
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $toInvalidate = [[
                'tb_uri' => (string) $targetRow->tb_uri,
                'tb_class' => (string) ($targetRow->tb_class ?? ''),
                'tb_id' => isset($targetRow->tb_id) ? (int) $targetRow->tb_id : null,
            ]];

            if ($deleteType === 'toestand') {
                $activeTbRows = $this->fetchActiveTbRowsForGoic((string) $targetRow->goic_uri);
                $activeTbClasses = array_values(array_filter(array_map(
                    fn (array $row) => (string) ($row['tb_class'] ?? ''),
                    $activeTbRows
                )));
                $activeTbCapabilities = $this->metadataService->fetchTbClassCapabilitiesByTbClasses($activeTbClasses);
                $remainingAfterDelete = array_values(array_filter($activeTbRows, function (array $row) use ($targetRow): bool {
                    return ($row['tb_uri'] ?? '') !== (string) $targetRow->tb_uri;
                }));

                $extraRoleUris = $this->autoRoleMutationService->collectRuleBasedInvalidationUris(
                    (string) ($targetRow->tb_class ?? ''),
                    (string) $targetRow->goic_uri,
                    $roleShapeRules,
                    fn (string $sourceGoicUri, string $roleTbClass, string $rolType, string $fromProperty): array => $this->fetchActiveRoleTbUrisByRoleTypeAndSourceGoic(
                        $sourceGoicUri,
                        $roleTbClass,
                        $rolType,
                        $fromProperty
                    )
                );

                if (! empty($extraRoleUris)) {
                    $tbIdByUri = $this->fetchTbIdsByUris(array_keys($extraRoleUris));
                    foreach ($extraRoleUris as $uri => $tbClass) {
                        $toInvalidate[] = [
                            'tb_uri' => (string) $uri,
                            'tb_class' => (string) $tbClass,
                            'tb_id' => $tbIdByUri[$uri] ?? null,
                        ];
                    }
                }

                $cascadeRows = $this->autoRoleMutationService->collectCascadeRowsWhenNoKernelRemains(
                    $remainingAfterDelete,
                    $roleShapeRules,
                    $activeTbCapabilities
                );

                if (! empty($cascadeRows)) {
                    $tbUris = array_values(array_unique(array_map(fn ($row) => (string) ($row['tb_uri'] ?? ''), $cascadeRows)));
                    $tbIdByUri = $this->fetchTbIdsByUris($tbUris);
                    foreach ($cascadeRows as $row) {
                        $uri = (string) ($row['tb_uri'] ?? '');
                        if ($uri === '') {
                            continue;
                        }
                        $toInvalidate[] = [
                            'tb_uri' => $uri,
                            'tb_class' => (string) ($row['tb_class'] ?? ''),
                            'tb_id' => $tbIdByUri[$uri] ?? null,
                        ];
                    }
                }
            }

            $triples = '';
            $seenTbUris = [];
            foreach ($toInvalidate as $item) {
                $tbUri = (string) ($item['tb_uri'] ?? '');
                if ($tbUri === '' || isset($seenTbUris[$tbUri])) {
                    continue;
                }
                $seenTbUris[$tbUri] = true;
                $mutatieUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.((string) Str::uuid());

                DB::table('object_mutaties')->insert([
                    'transactie_id' => $transactieId,
                    'sjabloon_uri' => (string) ($item['tb_class'] ?? ''),
                    'object_uri' => $tbUri,
                    'rdf_uri' => $mutatieUri,
                    'gegevens_object_in_context_id' => (int) $targetRow->goic_id,
                    'geproduceerde_toestand_id' => null,
                    'verwijderde_toestand_id' => isset($item['tb_id']) ? (int) $item['tb_id'] : null,
                    'datum_tijd' => $now,
                    'data' => json_encode([
                        'actie' => 'beeindig_toestand',
                        'tb_uri' => $tbUri,
                        'invalidatedAtTime' => $nowIso,
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $triples .= "<{$tbUri}> <{$dpm}invalidatedAtTime> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .\n";
                $triples .= "<{$mutatieUri}> a <{$vwm}ObjectMutatie> .\n";
                $triples .= "<{$mutatieUri}> <{$vwm}heeftBetrekkingOp> <{$targetRow->goic_uri}> .\n";
                $triples .= "<{$mutatieUri}> <{$vwm}verwijdertLogisch> <{$tbUri}> .\n";
                $triples .= "<{$mutatieUri}> <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .\n";
            }

            $this->graphService->update("
                INSERT DATA {
                    GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                        {$triples}
                    }
                }
            ");

            DB::commit();

            return $this->result([
                'ok' => true,
                'mode' => 'delete',
                'message' => $deleteType === 'role' ? 'Rol verwijderd.' : 'Toestand verwijderd.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->result([
                'error' => 'Verwijderen mislukt.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function result(array $payload, int $status = 200): array
    {
        return [
            'status' => $status,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function fetchActiveRoleTbUrisByRoleTypeAndSourceGoic(
        string $sourceGoicUri,
        string $roleTbClass,
        string $roleType,
        string $fromProperty
    ): array {
        if ($sourceGoicUri === '' || $roleTbClass === '' || $roleType === '' || $fromProperty === '') {
            return [];
        }

        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
            SELECT DISTINCT ?tb
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                    ?tb a <{$roleTbClass}> ;
                        <{$fromProperty}> <{$sourceGoicUri}> ;
                        vwm:rolType <{$roleType}> .
                    FILTER NOT EXISTS { ?tb dpm:invalidatedAtTime ?invalidatedAt . }
                }
            }
        ";

        $rows = $this->graphService->query($query);
        $uris = [];
        foreach ($rows as $row) {
            $uri = $row['tb'] ?? null;
            if (is_string($uri) && $uri !== '') {
                $uris[] = $uri;
            }
        }

        return array_values(array_unique($uris));
    }

    /**
     * @return array<int, array{tb_uri:string,tb_class:string|null}>
     */
    private function fetchActiveTbRowsForGoic(string $goicUri): array
    {
        if ($goicUri === '') {
            return [];
        }

        $query = "
            PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
            PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
            SELECT DISTINCT ?tb ?tbClass
            WHERE {
                {
                    ?tb vwm:beschrijftGOIC <{$goicUri}> .
                }
                UNION
                {
                    ?mutatie a vwm:ObjectMutatie ;
                             vwm:heeftBetrekkingOp <{$goicUri}> ;
                             vwm:produceert ?tb .
                }
                ?tb rdf:type ?tbClass .
                ?tbClass rdfs:subClassOf+ vwm:ToestandsBeschrijving .
                FILTER (?tbClass != vwm:ToestandsBeschrijving)
                FILTER NOT EXISTS { ?tb dpm:invalidatedAtTime ?invalidatedAt . }
            }
            ORDER BY ?tb
        ";

        try {
            $rows = $this->graphService->query($query);
        } catch (\Throwable $e) {
            logger()->warning('Kon actieve TB-rows voor cascade niet uit GraphDB lezen', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $tbUri = $row['tb'] ?? null;
            if (! is_string($tbUri) || $tbUri === '') {
                continue;
            }
            $result[] = [
                'tb_uri' => $tbUri,
                'tb_class' => is_string($row['tbClass'] ?? null) ? $row['tbClass'] : null,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $tbUris
     * @return array<string, int>
     */
    private function fetchTbIdsByUris(array $tbUris): array
    {
        $uris = array_values(array_unique(array_filter($tbUris, fn ($uri) => is_string($uri) && $uri !== '')));
        if (empty($uris)) {
            return [];
        }

        $rows = DB::table('toestands_beschrijvingen')
            ->whereIn('rdf_uri', $uris)
            ->get(['id', 'rdf_uri']);

        $result = [];
        foreach ($rows as $row) {
            if (is_string($row->rdf_uri) && $row->rdf_uri !== '') {
                $result[$row->rdf_uri] = (int) $row->id;
            }
        }

        return $result;
    }

    private function isClassDeleteAllowed(int $transactieSoortId, string $tbClass): bool
    {
        if ($tbClass === '') {
            return false;
        }
        $allowed = $this->roleMutationService->fetchAllowedSjabloonCrudByTbClass($transactieSoortId);

        return $this->roleMutationService->hasCrud($allowed[$tbClass] ?? null, 'D');
    }

    private function isDependentStateData(string $data): bool
    {
        $properties = json_decode($data, true);
        if (! is_array($properties)) {
            return false;
        }

        foreach ($properties as $property => $value) {
            if (
                is_string($property)
                && str_ends_with($property, '#verwijstNaar')
                && is_string($value)
                && filter_var($value, FILTER_VALIDATE_URL) !== false
            ) {
                return true;
            }
        }

        return false;
    }
}
