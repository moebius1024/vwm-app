<?php

use App\Services\GoicFollowInputService;

test('it resolves a trimmed source goic uri', function () {
    $service = new GoicFollowInputService;

    $result = $service->resolveSourceGoicUri([
        'bron_goic_uri' => '  http://example.test/goic/123  ',
    ]);

    expect($result)->toBe(['uri' => 'http://example.test/goic/123']);
});

test('it rejects multiple source goic input fields', function () {
    $service = new GoicFollowInputService;

    $result = $service->resolveSourceGoicUri([
        'bron_goic_uri' => 'http://example.test/goic/123',
        'bron_goic_uris' => ['http://example.test/goic/123'],
    ]);

    expect($result)->toMatchArray([
        'reason' => 'multiple_input_field',
    ]);
});

test('it rejects source goic uri arrays', function () {
    $service = new GoicFollowInputService;

    $result = $service->resolveSourceGoicUri([
        'bron_goic_uri' => ['http://example.test/goic/123'],
    ]);

    expect($result)->toMatchArray([
        'reason' => 'bron_goic_uri_array',
    ]);
});

test('it rejects source goic uri values with separators', function (string $uri) {
    $service = new GoicFollowInputService;

    $result = $service->resolveSourceGoicUri(['bron_goic_uri' => $uri]);

    expect($result)->toMatchArray([
        'reason' => 'invalid_single_uri_syntax',
    ]);
})->with([
    'empty' => '',
    'space' => 'http://example.test/goic/1 http://example.test/goic/2',
    'comma' => 'http://example.test/goic/1,http://example.test/goic/2',
    'semicolon' => 'http://example.test/goic/1;http://example.test/goic/2',
]);

test('it rejects non-http source goic uri values', function () {
    $service = new GoicFollowInputService;

    $result = $service->resolveSourceGoicUri(['bron_goic_uri' => 'urn:goic:123']);

    expect($result)->toMatchArray([
        'reason' => 'invalid_uri_format',
    ]);
});

test('it resolves a trimmed association uri', function () {
    $service = new GoicFollowInputService;

    $result = $service->resolveAssociationUri([
        'association_uri' => '  http://example.test/association/123  ',
    ]);

    expect($result)->toBe(['uri' => 'http://example.test/association/123']);
});

test('it rejects invalid association uri values', function (string $uri) {
    $service = new GoicFollowInputService;

    $result = $service->resolveAssociationUri(['association_uri' => $uri]);

    expect($result)->toMatchArray([
        'reason' => 'invalid_uri_format',
    ]);
})->with([
    'empty' => '',
    'urn' => 'urn:association:123',
    'space' => 'http://example.test/association/1 http://example.test/association/2',
]);
