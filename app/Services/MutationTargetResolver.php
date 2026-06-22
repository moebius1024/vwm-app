<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class MutationTargetResolver
{
    public function __construct(
        private readonly GraphService $graphService,
        private readonly SjabloonMetadataService $metadataService,
    ) {}

    /**
     * @return array{has_signalement:bool,has_beschrijving:bool}
     */
    public function evaluateBeschrijvingAttachEligibility(string $goicUri, string $targetClass): array
    {
        $activeRows = $this->fetchActiveTbRowsForGoic($goicUri);
        $tbClasses = [];
        foreach ($activeRows as $row) {
            $tbClass = (string) ($row['tb_class'] ?? '');
            if ($tbClass !== '') {
                $tbClasses[$tbClass] = true;
            }
        }

        $classUris = array_keys($tbClasses);
        if (empty($classUris)) {
            return ['has_signalement' => false, 'has_beschrijving' => false];
        }

        $describedByTb = $this->metadataService->fetchDescribedClassByTbClasses($classUris);
        $tbClassCapabilities = $this->metadataService->fetchTbClassCapabilitiesByTbClasses($classUris);
        $hasSignalement = false;
        $hasBeschrijving = false;

        foreach ($classUris as $tbClass) {
            $describedClass = $describedByTb[$tbClass] ?? null;
            if (! is_string($describedClass) || $describedClass !== $targetClass) {
                continue;
            }

            if ($this->tbClassCapabilityEnabled($tbClass, $tbClassCapabilities, 'is_signalement')) {
                $hasSignalement = true;
            }

            if ($this->tbClassCapabilityEnabled($tbClass, $tbClassCapabilities, 'is_beschrijving')) {
                $hasBeschrijving = true;
            }
        }

        return [
            'has_signalement' => $hasSignalement,
            'has_beschrijving' => $hasBeschrijving,
        ];
    }

    public function tbClassCapabilityEnabled(string $tbClassUri, array $capabilitiesByClass, string $capability): bool
    {
        if ($tbClassUri === '') {
            return false;
        }

        return (bool) ($capabilitiesByClass[$tbClassUri][$capability] ?? false);
    }

    public function resolveMutationTargetMeta(array $target, int $dossierId): ?object
    {
        $targetMeta = DB::table('object_mutaties')
            ->join('gegevens_objecten_in_context', 'gegevens_objecten_in_context.id', '=', 'object_mutaties.gegevens_object_in_context_id')
            ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
            ->where('object_mutaties.id', (int) ($target['mutatie_id'] ?? 0))
            ->where('gegevens_objecten_in_context.id', (int) ($target['goic_id'] ?? 0))
            ->where('gegevens_objecten_in_context.dossier_id', $dossierId)
            ->first([
                'object_mutaties.id as mutatie_id',
                'object_mutaties.gegevens_object_in_context_id as goic_id',
                'gegevens_objecten_in_context.rdf_uri as goic_uri',
                'object_mutaties.sjabloon_uri as tb_class',
                'toestands_beschrijvingen.id as tb_id',
                'toestands_beschrijvingen.rdf_uri as tb_uri',
            ]);

        if (! $targetMeta || ! is_string($targetMeta->tb_uri) || $targetMeta->tb_uri === '') {
            return null;
        }

        return $targetMeta;
    }

    /**
     * @return array<int,string>
     */
    public function getGoicTargetClassMapForCase(int $caseId): array
    {
        $dossierIds = DB::table('dossiers')
            ->where('case_id', $caseId)
            ->pluck('id')
            ->all();

        if (empty($dossierIds)) {
            return [];
        }

        $goics = DB::table('gegevens_objecten_in_context')
            ->whereIn('dossier_id', $dossierIds)
            ->get(['id', 'rdf_uri'])
            ->all();

        if (empty($goics)) {
            return [];
        }

        $goicIds = array_map(fn ($row) => (int) $row->id, $goics);
        $goicUrisById = [];
        foreach ($goics as $goic) {
            if (is_string($goic->rdf_uri) && $goic->rdf_uri !== '') {
                $goicUrisById[(int) $goic->id] = $goic->rdf_uri;
            }
        }

        $map = $this->fetchExplicitGoicTargetClassMap($goicUrisById);
        $fallbackGoicIds = array_values(array_diff($goicIds, array_keys($map)));

        if (empty($fallbackGoicIds)) {
            return $map;
        }

        $tbRows = DB::table('object_mutaties')
            ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
            ->whereIn('object_mutaties.gegevens_object_in_context_id', $fallbackGoicIds)
            ->orderBy('object_mutaties.created_at')
            ->orderBy('object_mutaties.id')
            ->get([
                'object_mutaties.gegevens_object_in_context_id as goic_id',
                'toestands_beschrijvingen.beschrijving as tb_class',
            ]);

        $tbHistoryByGoic = [];
        $tbClassesInUse = [];
        foreach ($tbRows as $row) {
            if (! empty($row->tb_class)) {
                $tbHistoryByGoic[(int) $row->goic_id][] = (string) $row->tb_class;
                $tbClassesInUse[(string) $row->tb_class] = true;
            }
        }

        if (empty($tbClassesInUse)) {
            return $map;
        }

        $describedByTb = $this->metadataService->fetchDescribedClassByTbClasses(array_keys($tbClassesInUse));
        $classHierarchy = $this->metadataService->fetchSubclassClosureMap();

        foreach ($fallbackGoicIds as $goicId) {
            $tbHistory = $tbHistoryByGoic[$goicId] ?? [];
            $targetClass = $this->resolveMostSpecificTargetClassFromTbHistory($tbHistory, $describedByTb, $classHierarchy);
            if ($targetClass !== null) {
                $map[$goicId] = $targetClass;
            }
        }

        return $map;
    }

    /**
     * @param  array<int,string>  $goicUrisById
     * @return array<int,string>
     */
    public function fetchExplicitGoicTargetClassMap(array $goicUrisById): array
    {
        $goicUrisById = array_filter(
            $goicUrisById,
            fn ($uri, $id): bool => is_int($id) && $id > 0 && is_string($uri) && $uri !== '',
            ARRAY_FILTER_USE_BOTH
        );

        if (empty($goicUrisById)) {
            return [];
        }

        $iriList = implode(' ', array_map(fn (string $uri): string => "<{$uri}>", array_values(array_unique($goicUrisById))));
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?goic ?targetClass
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                    VALUES ?goic { {$iriList} }
                    ?goic vwm:heeftDoelClass ?targetClass .
                }
            }
        ";

        try {
            $rows = $this->graphService->query($query);
        } catch (Throwable $e) {
            $this->logWarning('Kon expliciete GOIC doelclass niet uit GraphDB lezen', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $idsByUri = array_flip($goicUrisById);
        $map = [];
        foreach ($rows as $row) {
            $goicUri = $row['goic'] ?? null;
            $targetClass = $row['targetClass'] ?? null;
            if (! is_string($goicUri) || $goicUri === '' || ! is_string($targetClass) || $targetClass === '') {
                continue;
            }

            $goicId = $idsByUri[$goicUri] ?? null;
            if (is_int($goicId) && $goicId > 0) {
                $map[$goicId] = $targetClass;
            }
        }

        return $map;
    }

    public function resolveMostSpecificTargetClassFromTbHistory(array $tbHistory, array $describedByTb, array $classHierarchy): ?string
    {
        $targetClass = null;

        foreach ($tbHistory as $tbClass) {
            $candidateTargetClass = is_string($tbClass) ? ($describedByTb[$tbClass] ?? null) : null;
            if (! is_string($candidateTargetClass) || $candidateTargetClass === '') {
                continue;
            }

            if ($targetClass === null) {
                $targetClass = $candidateTargetClass;

                continue;
            }

            if ($this->isClassAssignable($targetClass, $candidateTargetClass, $classHierarchy)) {
                $targetClass = $candidateTargetClass;
            }
        }

        return $targetClass;
    }

    public function isClassAssignable(string $expectedClass, string $actualClass, array $classHierarchy): bool
    {
        if ($expectedClass === $actualClass) {
            return true;
        }

        $children = $classHierarchy[$expectedClass] ?? [];

        return in_array($actualClass, $children, true);
    }

    /**
     * @return array<int>
     */
    public function resolveGoicIdsForTargetClass(string $targetClass, array $goicIdsByClass, array $classHierarchy): array
    {
        if ($targetClass === '') {
            return [];
        }

        $targets = [$targetClass];
        $children = $classHierarchy[$targetClass] ?? [];
        foreach ($children as $child) {
            if (is_string($child) && $child !== '') {
                $targets[] = $child;
            }
        }

        $ids = [];
        foreach (array_values(array_unique($targets)) as $classUri) {
            foreach (($goicIdsByClass[$classUri] ?? []) as $goicId) {
                $ids[] = $goicId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int,array{tb_uri:string,tb_class:string|null}>
     */
    private function fetchActiveTbRowsForGoic(string $goicUri): array
    {
        if ($goicUri === '') {
            return [];
        }

        $query = "
            PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
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
                FILTER (?tbClass != vwm:ToestandsBeschrijving)
                FILTER NOT EXISTS { ?tb dpm:invalidatedAtTime ?invalidatedAt . }
            }
            ORDER BY ?tb
        ";

        try {
            $rows = $this->graphService->query($query);
        } catch (Throwable $e) {
            $this->logWarning('Kon actieve TB-rows voor beschrijving-resolutie niet uit GraphDB lezen', [
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

    private function logWarning(string $message, array $context = []): void
    {
        try {
            logger()->warning($message, $context);
        } catch (Throwable) {
            // Pure unit tests may run without Laravel's log binding.
        }
    }
}
