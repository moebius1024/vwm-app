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

it('defines a goods description template with a required description', function () {
    $ontology = file_get_contents(base_path('ontology/statements.ttl'));
    $shapes = file_get_contents(base_path('ontology/shapes-domain.ttl'));

    expect($ontology)->toContain('<http://ontologie.politie.nl/def/dpm#Goed> a <http://www.w3.org/2002/07/owl#Class>')
        ->and($ontology)->toContain('<http://ontologie.politie.nl/def/vwm#GoedBeschrijving> a <http://www.w3.org/2002/07/owl#Class>')
        ->and($ontology)->toContain('<http://ontologie.politie.nl/def/vwm#beschrijftClass> <http://ontologie.politie.nl/def/dpm#Goed>')
        ->and($shapes)->toContain('vwm:GoedBeschrijvingShape')
        ->and($shapes)->toContain('sh:path dpm:omschrijving')
        ->and($shapes)->toContain('sh:minCount 1');
});

it('defines the reporter role as a person to incident role', function () {
    $ontology = file_get_contents(base_path('ontology/statements.ttl'));
    $processShapes = file_get_contents(base_path('ontology/shapes-process.ttl'));

    expect($ontology)->toContain('<http://ontologie.politie.nl/def/vwm#RolType_Aangever> a <http://ontologie.politie.nl/def/vwm#RolType>')
        ->and($ontology)->toContain('<http://ontologie.politie.nl/def/vwm#Rol_Aangever> <http://www.w3.org/2000/01/rdf-schema#label> "Aangever"')
        ->and($processShapes)->toContain('vwm:RolTypeAangeverRegelShape')
        ->and($processShapes)->toContain('sh:targetNode vwm:RolType_Aangever')
        ->and($processShapes)->toContain('vwm:rolTbClass vwm:PersoonIncidentRol')
        ->and($processShapes)->toContain('vwm:vanClass dpm:Person')
        ->and($processShapes)->toContain('vwm:naarClass dpm:Incident');
});

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
