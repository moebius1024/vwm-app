<?php

use App\Services\GraphService;
use App\Services\SjabloonMetadataService;

it('discovers concrete templates from SHACL across the class hierarchy', function () {
    $graphService = Mockery::mock(GraphService::class);
    $graphService->shouldReceive('query')
        ->once()
        ->withArgs(fn (string $query): bool => str_contains($query, 'sh:targetClass ?sjabloon')
            && str_contains($query, 'rdfs:subClassOf+ vwm:ToestandsBeschrijving'))
        ->andReturn([
            [
                'sjabloon' => 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving',
                'label' => 'Persoon',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Person',
            ],
        ]);

    $sjablonen = (new SjabloonMetadataService($graphService))->listSjablonen();

    expect($sjablonen)->toBe([
        [
            'sjabloon_uri' => 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving',
            'label' => 'Persoon',
            'target_class' => 'http://ontologie.politie.nl/def/dpm#Person',
        ],
    ]);
});

it('returns enum options and conditional field metadata from SHACL', function () {
    $graphService = Mockery::mock(GraphService::class);
    $graphService->shouldReceive('query')
        ->once()
        ->withArgs(fn (string $query): bool => str_contains($query, 'COUNT(?shape)'))
        ->andReturn([['shapeCount' => 1]]);
    $graphService->shouldReceive('query')
        ->once()
        ->withArgs(fn (string $query): bool => str_contains($query, 'ui:enabledWhenProperty'))
        ->andReturn([
            [
                'sjabloonLabel' => 'Onderneming',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Onderneming',
                'label' => 'Rechtsvorm',
                'property' => 'http://ontologie.politie.nl/def/dpm#legalForm',
                'datatype' => 'http://www.w3.org/2001/XMLSchema#string',
                'order' => 2,
                'minCount' => 1,
                'enumValue' => 'NV',
            ],
            [
                'sjabloonLabel' => 'Onderneming',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Onderneming',
                'label' => 'Rechtsvorm',
                'property' => 'http://ontologie.politie.nl/def/dpm#legalForm',
                'datatype' => 'http://www.w3.org/2001/XMLSchema#string',
                'order' => 2,
                'minCount' => 1,
                'enumValue' => 'BV',
            ],
            [
                'sjabloonLabel' => 'Onderneming',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Onderneming',
                'label' => 'Rechtsvorm',
                'property' => 'http://ontologie.politie.nl/def/dpm#legalForm',
                'datatype' => 'http://www.w3.org/2001/XMLSchema#string',
                'order' => 2,
                'minCount' => 1,
                'enumValue' => 'anders',
            ],
            [
                'sjabloonLabel' => 'Onderneming',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Onderneming',
                'label' => 'AndereRechtsvorm',
                'property' => 'http://ontologie.politie.nl/def/dpm#otherLegalForm',
                'datatype' => 'http://www.w3.org/2001/XMLSchema#string',
                'order' => 3,
            ],
            [
                'sjabloonLabel' => 'Onderneming',
                'targetClass' => 'http://ontologie.politie.nl/def/dpm#Onderneming',
                'label' => 'AndereRechtsvorm',
                'property' => 'http://ontologie.politie.nl/def/dpm#otherLegalForm',
                'enabledWhenProperty' => 'http://ontologie.politie.nl/def/dpm#legalForm',
                'enabledWhenValue' => 'anders',
                'requiredWhenEnabled' => true,
            ],
        ]);

    $fields = (new SjabloonMetadataService($graphService))
        ->fetchSjabloon('http://ontologie.politie.nl/def/vwm#OndernemingsBeschrijving')['velden'];

    expect($fields[0]['options'])->toBe(['NV', 'BV', 'anders'])
        ->and($fields[1]['enabled_when'])->toBe([
            'property' => 'http://ontologie.politie.nl/def/dpm#legalForm',
            'value' => 'anders',
        ])
        ->and($fields[1]['required_when_enabled'])->toBeTrue();
});
