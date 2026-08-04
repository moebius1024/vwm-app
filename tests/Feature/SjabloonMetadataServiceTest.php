<?php

use App\Services\GraphService;
use App\Services\SjabloonMetadataService;

test('it exposes the UI-configured primary display identifier with its label', function () {
    $graph = Mockery::mock(GraphService::class);
    $graph->shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn (string $query): bool => str_contains($query, 'COUNT(?shape)')))
        ->andReturn([['shapeCount' => '1']]);
    $graph->shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn (string $query): bool => str_contains($query, 'ui:primaryDisplayIdentifier')))
        ->andReturn([
            [
                'tbClass' => 'http://ontologie.politie.nl/def/vwm#VoertuigBeschrijving',
                'describedClass' => 'http://ontologie.politie.nl/def/dpm#Vehicle',
                'property' => 'http://ontologie.politie.nl/def/dpm#licensePlate',
                'primaryDisplayLabel' => 'Kenteken',
                'primaryDisplayOrder' => '1',
                'isPrimaryDisplayIdentifier' => 'true',
            ],
            [
                'tbClass' => 'http://ontologie.politie.nl/def/vwm#VoertuigBeschrijving',
                'describedClass' => 'http://ontologie.politie.nl/def/dpm#Vehicle',
                'property' => 'http://ontologie.politie.nl/def/dpm#licensePlate',
                'primaryDisplayLabel' => 'Kenteken',
                'primaryDisplayOrder' => '1',
                'isPrimaryDisplayIdentifier' => 'true',
            ],
        ]);

    expect((new SjabloonMetadataService($graph))->listIdentifiers())->toBe([
        [
            'tb_class' => 'http://ontologie.politie.nl/def/vwm#VoertuigBeschrijving',
            'described_class' => 'http://ontologie.politie.nl/def/dpm#Vehicle',
            'properties' => ['http://ontologie.politie.nl/def/dpm#licensePlate'],
            'primary_display_properties' => [[
                'property' => 'http://ontologie.politie.nl/def/dpm#licensePlate',
                'label' => 'Kenteken',
            ]],
        ],
    ]);
});
