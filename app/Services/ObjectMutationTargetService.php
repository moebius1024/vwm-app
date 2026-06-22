<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ObjectMutationTargetService
{
    public function __construct(
        private readonly MutationTargetResolver $mutationTargetResolver,
        private readonly SjabloonMetadataService $metadataService,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $objects
     * @param  array<string, array<string, mixed>>  $tbClassCapabilities
     * @return array{objects: array<int, array<string, mixed>>, error: string|null, goic_target_class_map: array<int, string>}
     */
    public function resolve(
        array $objects,
        string $mode,
        ?object $mutationTargetMeta,
        int $caseId,
        array $tbClassCapabilities
    ): array {
        $goicTargetClassMap = $this->mutationTargetResolver->getGoicTargetClassMapForCase($caseId);
        $classHierarchy = $this->metadataService->fetchSubclassClosureMap();
        $goicIdsByClass = $this->groupGoicIdsByClass($goicTargetClassMap);

        foreach ($objects as &$object) {
            $tbClass = (string) ($object['sjabloon_uri'] ?? '');
            $targetClass = (string) ($object['target_class'] ?? '');
            $existingGoicId = isset($object['existing_goic_id']) ? (int) $object['existing_goic_id'] : null;
            $attachToExisting = ! empty($object['attach_to_existing']);
            $isToestandsWeergave = $this->mutationTargetResolver->tbClassCapabilityEnabled($tbClass, $tbClassCapabilities, 'is_state_projection');
            $isBeschrijving = $this->mutationTargetResolver->tbClassCapabilityEnabled($tbClass, $tbClassCapabilities, 'is_beschrijving');
            $candidateGoicIds = $this->mutationTargetResolver->resolveGoicIdsForTargetClass($targetClass, $goicIdsByClass, $classHierarchy);

            if ($mode === 'mutate' && $mutationTargetMeta) {
                $targetGoicId = (int) $mutationTargetMeta->goic_id;
                $targetGoicClass = $goicTargetClassMap[$targetGoicId] ?? null;
                if (
                    ! is_string($targetGoicClass)
                    || ! $this->mutationTargetResolver->isClassAssignable($targetClass, $targetGoicClass, $classHierarchy)
                ) {
                    return $this->error($objects, $goicTargetClassMap, "Mutatiedoel hoort niet bij target_class {$targetClass}.");
                }

                $object['existing_goic_id'] = $targetGoicId;

                continue;
            }

            if ($existingGoicId !== null && $existingGoicId > 0) {
                $existingClass = $goicTargetClassMap[$existingGoicId] ?? null;
                if (! is_string($existingClass)) {
                    return $this->error($objects, $goicTargetClassMap, 'Geselecteerd bestaand object hoort niet bij deze case.');
                }

                if (! $this->mutationTargetResolver->isClassAssignable($targetClass, $existingClass, $classHierarchy)) {
                    return $this->error($objects, $goicTargetClassMap, "Geselecteerd object heeft class {$existingClass}, verwacht {$targetClass}.");
                }

                if (! $isToestandsWeergave && ! $attachToExisting) {
                    return $this->error($objects, $goicTargetClassMap, "Bestaand object koppelen is niet toegestaan voor sjabloon {$tbClass}.");
                }

                if ($isBeschrijving) {
                    $goicUriById = $this->fetchGoicUrisById([$existingGoicId]);
                    $existingGoicUri = $goicUriById[$existingGoicId] ?? null;
                    if (! is_string($existingGoicUri) || $existingGoicUri === '') {
                        return $this->error($objects, $goicTargetClassMap, 'Kon bestaand GOIC niet resolven.');
                    }

                    $attachCheck = $this->mutationTargetResolver->evaluateBeschrijvingAttachEligibility($existingGoicUri, $targetClass);
                    if (! $attachCheck['has_signalement']) {
                        return $this->error($objects, $goicTargetClassMap, 'Beschrijving toevoegen kan alleen op een object met actief signalement.');
                    }

                    if ($attachCheck['has_beschrijving']) {
                        return $this->error($objects, $goicTargetClassMap, 'Dit object heeft al een actieve beschrijving.');
                    }
                }

                $object['existing_goic_id'] = $existingGoicId;

                continue;
            }

            if ($attachToExisting) {
                if ($isBeschrijving) {
                    return $this->error($objects, $goicTargetClassMap, "Kies eerst op welk bestaand object ({$targetClass}) je deze beschrijving wilt registreren.");
                }

                if (count($candidateGoicIds) === 1) {
                    $object['existing_goic_id'] = $candidateGoicIds[0];

                    continue;
                }

                if (count($candidateGoicIds) > 1) {
                    return $this->error($objects, $goicTargetClassMap, "Kies eerst op welk bestaand object ({$targetClass}) je deze registratie wilt uitvoeren.");
                }

                return $this->error($objects, $goicTargetClassMap, "Geen bestaand object ({$targetClass}) gevonden in dit dossier voor deze registratie.");
            }

            if ($isToestandsWeergave) {
                if (count($candidateGoicIds) === 1) {
                    $object['existing_goic_id'] = $candidateGoicIds[0];

                    continue;
                }

                if (count($candidateGoicIds) > 1) {
                    return $this->error($objects, $goicTargetClassMap, "Kies eerst op welk bestaand object ({$targetClass}) je deze toestandsweergave wilt registreren.");
                }

                return $this->error($objects, $goicTargetClassMap, "Geen bestaand object ({$targetClass}) gevonden in dit dossier voor deze toestandsweergave.");
            }

            $object['existing_goic_id'] = null;
        }
        unset($object);

        return [
            'objects' => $objects,
            'error' => null,
            'goic_target_class_map' => $goicTargetClassMap,
        ];
    }

    /**
     * @param  array<int>  $goicIds
     * @return array<int, string>
     */
    private function fetchGoicUrisById(array $goicIds): array
    {
        if (empty($goicIds)) {
            return [];
        }

        return DB::table('gegevens_objecten_in_context')
            ->whereIn('id', $goicIds)
            ->pluck('rdf_uri', 'id')
            ->all();
    }

    /**
     * @param  array<int, string>  $goicTargetClassMap
     * @return array<string, array<int>>
     */
    private function groupGoicIdsByClass(array $goicTargetClassMap): array
    {
        $goicIdsByClass = [];

        foreach ($goicTargetClassMap as $goicId => $classUri) {
            if (! isset($goicIdsByClass[$classUri])) {
                $goicIdsByClass[$classUri] = [];
            }

            $goicIdsByClass[$classUri][] = $goicId;
        }

        return $goicIdsByClass;
    }

    /**
     * @param  array<int, array<string, mixed>>  $objects
     * @param  array<int, string>  $goicTargetClassMap
     * @return array{objects: array<int, array<string, mixed>>, error: string, goic_target_class_map: array<int, string>}
     */
    private function error(array $objects, array $goicTargetClassMap, string $message): array
    {
        return [
            'objects' => $objects,
            'error' => $message,
            'goic_target_class_map' => $goicTargetClassMap,
        ];
    }
}
