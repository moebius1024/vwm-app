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
            ->get(['id'])
            ->all();

        if (empty($goics)) {
            return [];
        }

        $goicIds = array_map(fn ($row) => (int) $row->id, $goics);

        $tbRows = DB::table('object_mutaties')
            ->leftJoin('toestands_beschrijvingen', 'toestands_beschrijvingen.id', '=', 'object_mutaties.geproduceerde_toestand_id')
            ->whereIn('object_mutaties.gegevens_object_in_context_id', $goicIds)
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
            return [];
        }

        $describedByTb = $this->metadataService->fetchDescribedClassByTbClasses(array_keys($tbClassesInUse));
        $map = [];

        foreach ($goicIds as $goicId) {
            $tbHistory = $tbHistoryByGoic[$goicId] ?? [];
            for ($index = count($tbHistory) - 1; $index >= 0; $index--) {
                $tbClass = $tbHistory[$index];
                $candidateTargetClass = $describedByTb[$tbClass] ?? null;
                if (is_string($candidateTargetClass) && $candidateTargetClass !== '') {
                    $map[$goicId] = $candidateTargetClass;
                    break;
                }
            }
        }

        return $map;
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
            logger()->warning('Kon actieve TB-rows voor beschrijving-resolutie niet uit GraphDB lezen', [
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
}
