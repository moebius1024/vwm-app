<?php

use EasyRdf\Collection;
use EasyRdf\Graph;
use EasyRdf\Isomorphic;

/**
 * @param  list<string>  $paths
 */
function loadOntologyGraph(array $paths): Graph
{
    $graph = new Graph;

    foreach ($paths as $path) {
        $graph->parseFile($path, 'turtle');
    }

    return $graph;
}

function removeUiMetadata(Graph $graph): void
{
    foreach ($graph->resources() as $resource) {
        foreach ($resource->propertyUris() as $propertyUri) {
            if (str_starts_with($propertyUri, 'http://ontologie.politie.nl/def/ui#')) {
                $graph->delete($resource, $propertyUri);
            }
        }
    }
}

it('keeps ui metadata out of domain and process shape files', function () {
    $domain = file_get_contents(base_path('ontology/shapes-domain.ttl'));
    $process = file_get_contents(base_path('ontology/shapes-process.ttl'));

    expect($domain)->not->toMatch('/^\s*ui:[A-Za-z]/m');
    expect($process)->not->toMatch('/^\s*ui:[A-Za-z]/m');
});

it('keeps combined validation shapes equal to domain and process shapes', function () {
    $expected = loadOntologyGraph([
        base_path('ontology/shapes-domain.ttl'),
        base_path('ontology/shapes-process.ttl'),
    ]);
    $combined = loadOntologyGraph([
        base_path('ontology/shapes.ttl'),
    ]);

    removeUiMetadata($combined);

    expect(Isomorphic::isomorphic($expected, $combined))->toBeTrue();
});

it('assigns shared contact properties to Party', function (string $propertyUri) {
    $ontology = loadOntologyGraph([
        base_path('ontology/statements.ttl'),
    ]);
    $domains = $ontology
        ->resource($propertyUri)
        ->allResources('rdfs:domain');

    expect(array_map(
        fn ($domain) => $domain->getUri(),
        $domains,
    ))->toBe(['http://ontologie.politie.nl/def/dpm#Party']);
})->with([
    'email address' => 'http://ontologie.politie.nl/def/dpm#emailAddress',
    'telephone number' => 'http://ontologie.politie.nl/def/dpm#telephoneNumber',
]);

it('assigns invalidation time to states and data object associations', function () {
    $ontology = loadOntologyGraph([
        base_path('ontology/statements.ttl'),
    ]);
    $domains = $ontology
        ->resource('http://ontologie.politie.nl/def/dpm#invalidatedAtTime')
        ->allResources('rdfs:domain');

    expect($domains)->toHaveCount(1);

    $union = $domains[0]->getResource('owl:unionOf');

    expect($union)->toBeInstanceOf(Collection::class);

    $members = [];
    foreach ($union as $member) {
        $members[] = $member->getUri();
    }
    sort($members);

    expect($members)->toBe([
        'http://ontologie.politie.nl/def/dpm#DataObjectAssociation',
        'http://ontologie.politie.nl/def/vwm#ToestandsBeschrijving',
    ]);
});

it('allows object mutations to logically remove states and data object associations', function () {
    $ontology = loadOntologyGraph([
        base_path('ontology/statements.ttl'),
    ]);
    $ranges = $ontology
        ->resource('http://ontologie.politie.nl/def/vwm#verwijdertLogisch')
        ->allResources('rdfs:range');

    expect($ranges)->toHaveCount(1);

    $union = $ranges[0]->getResource('owl:unionOf');

    expect($union)->toBeInstanceOf(Collection::class);

    $members = [];
    foreach ($union as $member) {
        $members[] = $member->getUri();
    }
    sort($members);

    expect($members)->toBe([
        'http://ontologie.politie.nl/def/dpm#DataObjectAssociation',
        'http://ontologie.politie.nl/def/vwm#ToestandsBeschrijving',
    ]);
});

it('allows object mutations to produce states and data object associations', function () {
    $ontology = loadOntologyGraph([
        base_path('ontology/statements.ttl'),
    ]);
    $ranges = $ontology
        ->resource('http://ontologie.politie.nl/def/vwm#produceert')
        ->allResources('rdfs:range');

    expect($ranges)->toHaveCount(1);

    $union = $ranges[0]->getResource('owl:unionOf');

    expect($union)->toBeInstanceOf(Collection::class);

    $members = [];
    foreach ($union as $member) {
        $members[] = $member->getUri();
    }
    sort($members);

    expect($members)->toBe([
        'http://ontologie.politie.nl/def/dpm#DataObjectAssociation',
        'http://ontologie.politie.nl/def/vwm#ToestandsBeschrijving',
    ]);
});
