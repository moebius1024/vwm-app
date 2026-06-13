<?php

use App\Services\MutationTargetResolver;
use App\Services\ObjectMutationPreparationService;
use App\Services\RoleMutationService;

function makeObjectMutationPreparationService(
    ?MutationTargetResolver $mutationTargetResolver = null,
    ?RoleMutationService $roleMutationService = null,
): ObjectMutationPreparationService {
    return new ObjectMutationPreparationService(
        $mutationTargetResolver ?? Mockery::mock(MutationTargetResolver::class),
        $roleMutationService ?? Mockery::mock(RoleMutationService::class),
    );
}

test('it marks attach only templates as attach intent', function () {
    $tbClass = 'http://example.test/ContactGegevens';
    $targetClass = 'http://example.test/Party';
    $resolver = Mockery::mock(MutationTargetResolver::class);
    $roleMutationService = Mockery::mock(RoleMutationService::class);
    $service = makeObjectMutationPreparationService($resolver, $roleMutationService);

    $resolver
        ->shouldReceive('tbClassCapabilityEnabled')
        ->once()
        ->with($tbClass, [$tbClass => []], 'is_state_projection')
        ->andReturn(false);

    $roleMutationService
        ->shouldReceive('hasCrud')
        ->with('A', 'A')
        ->andReturn(true);
    $roleMutationService
        ->shouldReceive('hasCrud')
        ->with('A', 'C')
        ->andReturn(false);

    $result = $service->prepare(
        [[
            'sjabloon_uri' => $tbClass,
            'target_class' => $targetClass,
            'data' => [],
        ]],
        'register',
        null,
        [$tbClass => $targetClass],
        [$tbClass => []],
        [$tbClass => 'A'],
    );

    expect($result['error'])->toBeNull()
        ->and($result['objects'][0]['attach_to_existing'])->toBeTrue()
        ->and($result['objects'][0]['target_class'])->toBe($targetClass);
});

test('it rejects mismatching target class', function () {
    $tbClass = 'http://example.test/PersoonBeschrijving';
    $service = makeObjectMutationPreparationService();

    $result = $service->prepare(
        [[
            'sjabloon_uri' => $tbClass,
            'target_class' => 'http://example.test/Vehicle',
            'data' => [],
        ]],
        'register',
        null,
        [$tbClass => 'http://example.test/Person'],
        [$tbClass => []],
        [$tbClass => 'CRUD'],
    );

    expect($result['error'])->toBe("target_class komt niet overeen met sjabloon {$tbClass}.");
});

test('it rejects mutation when update is not allowed', function () {
    $tbClass = 'http://example.test/PersoonBeschrijving';
    $targetClass = 'http://example.test/Person';
    $resolver = Mockery::mock(MutationTargetResolver::class);
    $roleMutationService = Mockery::mock(RoleMutationService::class);
    $service = makeObjectMutationPreparationService($resolver, $roleMutationService);
    $mutationTargetMeta = (object) ['tb_class' => $tbClass];

    $resolver
        ->shouldReceive('tbClassCapabilityEnabled')
        ->once()
        ->with($tbClass, [$tbClass => []], 'is_state_projection')
        ->andReturn(false);

    $roleMutationService
        ->shouldReceive('hasCrud')
        ->once()
        ->with('R', 'A')
        ->andReturn(false);
    $roleMutationService
        ->shouldReceive('hasCrud')
        ->once()
        ->with('R', 'U')
        ->andReturn(false);

    $result = $service->prepare(
        [[
            'sjabloon_uri' => $tbClass,
            'target_class' => $targetClass,
            'data' => [],
        ]],
        'mutate',
        $mutationTargetMeta,
        [$tbClass => $targetClass],
        [$tbClass => []],
        [$tbClass => 'R'],
    );

    expect($result['error'])->toBe("Muteren niet toegestaan voor sjabloon {$tbClass} in deze transactie.");
});
