<?php

function extractNodeShapeSubjects(string $ttl): array
{
    preg_match_all('/^\s*([A-Za-z_][A-Za-z0-9_-]*:[A-Za-z_][A-Za-z0-9_-]*)\s+a\s+sh:NodeShape\b/m', $ttl, $matches);

    $subjects = array_values(array_unique($matches[1] ?? []));
    sort($subjects);

    return $subjects;
}

it('keeps ui metadata out of domain and process shape files', function () {
    $domain = file_get_contents(base_path('ontology/shapes-domain.ttl'));
    $process = file_get_contents(base_path('ontology/shapes-process.ttl'));

    expect($domain)->not->toMatch('/^\s*ui:[A-Za-z]/m');
    expect($process)->not->toMatch('/^\s*ui:[A-Za-z]/m');
});

it('keeps combined node shapes equal to split shape union', function () {
    $combined = file_get_contents(base_path('ontology/shapes.ttl'));
    $domain = file_get_contents(base_path('ontology/shapes-domain.ttl'));
    $process = file_get_contents(base_path('ontology/shapes-process.ttl'));
    $ui = file_get_contents(base_path('ontology/shapes-ui.ttl'));

    $combinedShapes = extractNodeShapeSubjects($combined);
    $splitUnionShapes = array_values(array_unique(array_merge(
        extractNodeShapeSubjects($domain),
        extractNodeShapeSubjects($process),
        extractNodeShapeSubjects($ui),
    )));
    sort($splitUnionShapes);

    expect($combinedShapes)->toBe($splitUnionShapes);
});
