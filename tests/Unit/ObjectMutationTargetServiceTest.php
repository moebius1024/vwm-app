<?php

use App\Services\MutationTargetResolver;
use App\Services\ObjectMutationTargetService;
use App\Services\SjabloonMetadataService;

function makeObjectMutationTargetService(
    MutationTargetResolver $mutationTargetResolver,
    SjabloonMetadataService $metadataService,
): ObjectMutationTargetService {
    return new ObjectMutationTargetService($mutationTargetResolver, $metadataService);
}

test('it uses the selected goic in mutation mode', function () {
    $caseId = 19;
    $goicId = 90;
    $targetClass = 'http://example.test/Person';
    $tbClass = 'http://example.test/Persoon';
    $resolver = Mockery::mock(MutationTargetResolver::class);
    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $service = makeObjectMutationTargetService($resolver, $metadataService);

    $resolver
        ->shouldReceive('getGoicTargetClassMapForCase')
        ->once()
        ->with($caseId)
        ->andReturn([$goicId => $targetClass]);
    $metadataService
        ->shouldReceive('fetchSubclassClosureMap')
        ->once()
        ->andReturn([]);
    $resolver
        ->shouldReceive('tbClassCapabilityEnabled')
        ->twice()
        ->andReturn(false);
    $resolver
        ->shouldReceive('resolveGoicIdsForTargetClass')
        ->once()
        ->with($targetClass, [$targetClass => [$goicId]], [])
        ->andReturn([$goicId]);
    $resolver
        ->shouldReceive('isClassAssignable')
        ->once()
        ->with($targetClass, $targetClass, [])
        ->andReturn(true);

    $result = $service->resolve(
        [[
            'sjabloon_uri' => $tbClass,
            'target_class' => $targetClass,
            'data' => [],
        ]],
        'mutate',
        (object) ['goic_id' => $goicId],
        $caseId,
        [$tbClass => []],
    );

    expect($result['error'])->toBeNull()
        ->and($result['objects'][0]['existing_goic_id'])->toBe($goicId)
        ->and($result['goic_target_class_map'])->toBe([$goicId => $targetClass]);
});

test('it accepts a subclass goic for mutation mode targets', function () {
    $caseId = 23;
    $goicId = 106;
    $targetClass = 'http://example.test/Party';
    $actualGoicClass = 'http://example.test/Person';
    $tbClass = 'http://example.test/ContactGegevens';
    $classHierarchy = [
        $targetClass => [$actualGoicClass],
    ];
    $resolver = Mockery::mock(MutationTargetResolver::class);
    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $service = makeObjectMutationTargetService($resolver, $metadataService);

    $resolver
        ->shouldReceive('getGoicTargetClassMapForCase')
        ->once()
        ->with($caseId)
        ->andReturn([$goicId => $actualGoicClass]);
    $metadataService
        ->shouldReceive('fetchSubclassClosureMap')
        ->once()
        ->andReturn($classHierarchy);
    $resolver
        ->shouldReceive('tbClassCapabilityEnabled')
        ->twice()
        ->andReturn(false);
    $resolver
        ->shouldReceive('resolveGoicIdsForTargetClass')
        ->once()
        ->with($targetClass, [$actualGoicClass => [$goicId]], $classHierarchy)
        ->andReturn([$goicId]);
    $resolver
        ->shouldReceive('isClassAssignable')
        ->once()
        ->with($targetClass, $actualGoicClass, $classHierarchy)
        ->andReturn(true);

    $result = $service->resolve(
        [[
            'sjabloon_uri' => $tbClass,
            'target_class' => $targetClass,
            'data' => [],
        ]],
        'mutate',
        (object) ['goic_id' => $goicId],
        $caseId,
        [$tbClass => []],
    );

    expect($result['error'])->toBeNull()
        ->and($result['objects'][0]['existing_goic_id'])->toBe($goicId);
});

test('it selects the only candidate for attach to existing registrations', function () {
    $caseId = 19;
    $goicId = 93;
    $targetClass = 'http://example.test/Vehicle';
    $tbClass = 'http://example.test/ContactGegevens';
    $resolver = Mockery::mock(MutationTargetResolver::class);
    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $service = makeObjectMutationTargetService($resolver, $metadataService);

    $resolver
        ->shouldReceive('getGoicTargetClassMapForCase')
        ->once()
        ->with($caseId)
        ->andReturn([$goicId => $targetClass]);
    $metadataService
        ->shouldReceive('fetchSubclassClosureMap')
        ->once()
        ->andReturn([]);
    $resolver
        ->shouldReceive('tbClassCapabilityEnabled')
        ->twice()
        ->andReturn(false);
    $resolver
        ->shouldReceive('resolveGoicIdsForTargetClass')
        ->once()
        ->with($targetClass, [$targetClass => [$goicId]], [])
        ->andReturn([$goicId]);

    $result = $service->resolve(
        [[
            'sjabloon_uri' => $tbClass,
            'target_class' => $targetClass,
            'attach_to_existing' => true,
            'data' => [],
        ]],
        'register',
        null,
        $caseId,
        [$tbClass => []],
    );

    expect($result['error'])->toBeNull()
        ->and($result['objects'][0]['existing_goic_id'])->toBe($goicId);
});

test('it asks the user to choose when attach to existing has multiple candidates', function () {
    $caseId = 19;
    $targetClass = 'http://example.test/Person';
    $tbClass = 'http://example.test/ContactGegevens';
    $resolver = Mockery::mock(MutationTargetResolver::class);
    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $service = makeObjectMutationTargetService($resolver, $metadataService);

    $resolver
        ->shouldReceive('getGoicTargetClassMapForCase')
        ->once()
        ->with($caseId)
        ->andReturn([
            90 => $targetClass,
            94 => $targetClass,
        ]);
    $metadataService
        ->shouldReceive('fetchSubclassClosureMap')
        ->once()
        ->andReturn([]);
    $resolver
        ->shouldReceive('tbClassCapabilityEnabled')
        ->twice()
        ->andReturn(false);
    $resolver
        ->shouldReceive('resolveGoicIdsForTargetClass')
        ->once()
        ->with($targetClass, [$targetClass => [90, 94]], [])
        ->andReturn([90, 94]);

    $result = $service->resolve(
        [[
            'sjabloon_uri' => $tbClass,
            'target_class' => $targetClass,
            'attach_to_existing' => true,
            'data' => [],
        ]],
        'register',
        null,
        $caseId,
        [$tbClass => []],
    );

    expect($result['error'])->toBe("Kies eerst op welk bestaand object ({$targetClass}) je deze registratie wilt uitvoeren.");
});
