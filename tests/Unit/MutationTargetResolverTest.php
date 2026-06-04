<?php

use App\Services\GraphService;
use App\Services\MutationTargetResolver;
use App\Services\SjabloonMetadataService;

function makeMutationTargetResolver(): MutationTargetResolver
{
    return new MutationTargetResolver(
        Mockery::mock(GraphService::class),
        Mockery::mock(SjabloonMetadataService::class),
    );
}

test('it reads tb class capabilities from metadata maps', function () {
    $resolver = makeMutationTargetResolver();

    $capabilities = [
        'http://example.test/PersoonsBeschrijving' => [
            'is_beschrijving' => true,
            'is_signalement' => false,
        ],
    ];

    expect($resolver->tbClassCapabilityEnabled('http://example.test/PersoonsBeschrijving', $capabilities, 'is_beschrijving'))->toBeTrue()
        ->and($resolver->tbClassCapabilityEnabled('http://example.test/PersoonsBeschrijving', $capabilities, 'is_signalement'))->toBeFalse()
        ->and($resolver->tbClassCapabilityEnabled('', $capabilities, 'is_beschrijving'))->toBeFalse()
        ->and($resolver->tbClassCapabilityEnabled('http://example.test/Onbekend', $capabilities, 'is_beschrijving'))->toBeFalse();
});

test('it accepts exact classes and subclasses as assignable', function () {
    $resolver = makeMutationTargetResolver();

    $classHierarchy = [
        'http://example.test/Party' => [
            'http://example.test/Person',
            'http://example.test/Onderneming',
        ],
    ];

    expect($resolver->isClassAssignable('http://example.test/Party', 'http://example.test/Party', $classHierarchy))->toBeTrue()
        ->and($resolver->isClassAssignable('http://example.test/Party', 'http://example.test/Person', $classHierarchy))->toBeTrue()
        ->and($resolver->isClassAssignable('http://example.test/Person', 'http://example.test/Party', $classHierarchy))->toBeFalse();
});

test('it resolves goic ids for a target class and its subclasses', function () {
    $resolver = makeMutationTargetResolver();

    $goicIdsByClass = [
        'http://example.test/Party' => [1],
        'http://example.test/Person' => [2, 3],
        'http://example.test/Onderneming' => [4],
    ];
    $classHierarchy = [
        'http://example.test/Party' => [
            'http://example.test/Person',
            'http://example.test/Onderneming',
        ],
    ];

    expect($resolver->resolveGoicIdsForTargetClass('http://example.test/Party', $goicIdsByClass, $classHierarchy))
        ->toBe([1, 2, 3, 4])
        ->and($resolver->resolveGoicIdsForTargetClass('', $goicIdsByClass, $classHierarchy))
        ->toBe([]);
});

test('it evaluates beschrijving attach eligibility from active graph rows', function () {
    $goicUri = 'http://example.test/goic/1';
    $targetClass = 'http://example.test/Person';
    $signalementClass = 'http://example.test/PersoonSignalement';
    $beschrijvingClass = 'http://example.test/PersoonsBeschrijving';

    $graphService = Mockery::mock(GraphService::class);
    $metadataService = Mockery::mock(SjabloonMetadataService::class);
    $resolver = new MutationTargetResolver($graphService, $metadataService);

    $graphService
        ->shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn (string $query): bool => str_contains($query, "vwm:beschrijftGOIC <{$goicUri}>")))
        ->andReturn([
            ['tb' => 'http://example.test/tb/1', 'tbClass' => $signalementClass],
            ['tb' => 'http://example.test/tb/2', 'tbClass' => $beschrijvingClass],
        ]);

    $metadataService
        ->shouldReceive('fetchDescribedClassByTbClasses')
        ->once()
        ->with([$signalementClass, $beschrijvingClass])
        ->andReturn([
            $signalementClass => $targetClass,
            $beschrijvingClass => $targetClass,
        ]);

    $metadataService
        ->shouldReceive('fetchTbClassCapabilitiesByTbClasses')
        ->once()
        ->with([$signalementClass, $beschrijvingClass])
        ->andReturn([
            $signalementClass => ['is_signalement' => true, 'is_beschrijving' => false],
            $beschrijvingClass => ['is_signalement' => false, 'is_beschrijving' => true],
        ]);

    expect($resolver->evaluateBeschrijvingAttachEligibility($goicUri, $targetClass))
        ->toBe([
            'has_signalement' => true,
            'has_beschrijving' => true,
        ]);
});
