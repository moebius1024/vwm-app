<?php

namespace App\Services;

class ObjectMutationPreparationService
{
    public function __construct(
        private readonly MutationTargetResolver $mutationTargetResolver,
        private readonly RoleMutationService $roleMutationService,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $objects
     * @param  array<string, string>  $describedClassByTbClass
     * @param  array<string, array<string, mixed>>  $tbClassCapabilities
     * @param  array<string, string>  $allowedSjabloonCrud
     * @return array{objects: array<int, array<string, mixed>>, error: string|null}
     */
    public function prepare(
        array $objects,
        string $mode,
        ?object $mutationTargetMeta,
        array $describedClassByTbClass,
        array $tbClassCapabilities,
        array $allowedSjabloonCrud
    ): array {
        foreach ($objects as &$object) {
            $tbClass = $object['sjabloon_uri'] ?? null;
            $expectedTargetClass = is_string($tbClass) ? ($describedClassByTbClass[$tbClass] ?? null) : null;

            if (! is_string($expectedTargetClass) || $expectedTargetClass === '') {
                return [
                    'objects' => $objects,
                    'error' => "Onbekende of onvolledige sjabloondefinitie: {$tbClass}",
                ];
            }

            if (($object['target_class'] ?? null) !== $expectedTargetClass) {
                return [
                    'objects' => $objects,
                    'error' => "target_class komt niet overeen met sjabloon {$tbClass}.",
                ];
            }

            $object['target_class'] = $expectedTargetClass;

            $crudFlags = $allowedSjabloonCrud[$tbClass] ?? null;
            $isToestandsWeergave = $this->mutationTargetResolver->tbClassCapabilityEnabled((string) $tbClass, $tbClassCapabilities, 'is_state_projection');
            $existingGoicId = isset($object['existing_goic_id']) ? (int) $object['existing_goic_id'] : null;
            $attachRequested = ! empty($object['attach_to_existing']);
            $isAttachOnlySjabloon = $this->roleMutationService->hasCrud($crudFlags, 'A') && ! $this->roleMutationService->hasCrud($crudFlags, 'C');
            $isAttachIntent = $attachRequested || ($existingGoicId !== null && $existingGoicId > 0) || $isToestandsWeergave || $isAttachOnlySjabloon;

            $object['attach_to_existing'] = $isAttachIntent;

            if ($mode !== 'mutate') {
                $attachAllowed = $this->roleMutationService->hasCrud($crudFlags, 'A') || ($isToestandsWeergave && $this->roleMutationService->hasCrud($crudFlags, 'C'));

                if ($isAttachIntent && ! $attachAllowed) {
                    return [
                        'objects' => $objects,
                        'error' => "Toevoegen op bestaand object niet toegestaan voor sjabloon {$tbClass} in deze transactie.",
                    ];
                }

                if (! $isAttachIntent && ! $this->roleMutationService->hasCrud($crudFlags, 'C')) {
                    return [
                        'objects' => $objects,
                        'error' => "Aanmaken niet toegestaan voor sjabloon {$tbClass} in deze transactie.",
                    ];
                }
            }
        }
        unset($object);

        if ($mode === 'mutate' && $mutationTargetMeta) {
            $tbClass = (string) ($mutationTargetMeta->tb_class ?? '');
            if ($tbClass === '') {
                return [
                    'objects' => $objects,
                    'error' => 'Mutatiedoel heeft een onbekende class.',
                ];
            }

            if (! $this->roleMutationService->hasCrud($allowedSjabloonCrud[$tbClass] ?? null, 'U')) {
                return [
                    'objects' => $objects,
                    'error' => "Muteren niet toegestaan voor sjabloon {$tbClass} in deze transactie.",
                ];
            }
        }

        return [
            'objects' => $objects,
            'error' => null,
        ];
    }
}
