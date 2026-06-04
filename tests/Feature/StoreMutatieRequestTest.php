<?php

use App\Http\Requests\StoreMutatieRequest;

function makeStoreMutatieRequest(array $payload): StoreMutatieRequest
{
    $request = StoreMutatieRequest::create('/api/mutatie', 'POST', $payload);
    $request->setContainer(app());
    $request->validateResolved();

    return $request;
}

test('it normalizes legacy single object payloads', function () {
    $request = makeStoreMutatieRequest([
        'transactie_soort_id' => 1,
        'case_id' => 42,
        'sjabloon_uri' => 'http://example.test/Persoon',
        'target_class' => 'http://example.test/Person',
        'data' => [
            'achternaam' => 'Stolk',
        ],
    ]);

    expect($request->base())->toBe([
        'transactie_soort_id' => 1,
        'case_id' => 42,
    ])
        ->and($request->mode())->toBe('register')
        ->and($request->normalizedObjects())->toBe([[
            'client_id' => 'obj_legacy',
            'sjabloon_uri' => 'http://example.test/Persoon',
            'target_class' => 'http://example.test/Person',
            'data' => [
                'achternaam' => 'Stolk',
            ],
        ]]);
});

test('it accepts roles only payloads without objects', function () {
    $request = makeStoreMutatieRequest([
        'transactie_soort_id' => 1,
        'case_id' => 42,
        'roles' => [
            'items' => [[
                'roleType' => 'Bestuurder',
                'fromGoicId' => 10,
                'toGoicId' => 11,
            ]],
        ],
    ]);

    expect($request->normalizedObjects())->toBe([]);
});

test('it exposes mutation target payloads', function () {
    $request = makeStoreMutatieRequest([
        'transactie_soort_id' => 1,
        'case_id' => 42,
        'mode' => 'mutate',
        'target' => [
            'goic_id' => 10,
            'mutatie_id' => 20,
            'tb_rdf_uri' => 'http://example.test/tb/20',
            'sjabloon_uri' => 'http://example.test/Persoon',
        ],
        'objects' => [[
            'client_id' => 'obj_1',
            'sjabloon_uri' => 'http://example.test/Persoon',
            'target_class' => 'http://example.test/Person',
            'data' => [
                'achternaam' => 'Stolk',
            ],
        ]],
    ]);

    expect($request->normalizedObjects())->toBe([[
        'client_id' => 'obj_1',
        'sjabloon_uri' => 'http://example.test/Persoon',
        'target_class' => 'http://example.test/Person',
        'data' => [
            'achternaam' => 'Stolk',
        ],
    ]])
        ->and($request->mutationTarget())->toBe([
            'goic_id' => 10,
            'mutatie_id' => 20,
            'tb_rdf_uri' => 'http://example.test/tb/20',
            'sjabloon_uri' => 'http://example.test/Persoon',
        ]);
});
