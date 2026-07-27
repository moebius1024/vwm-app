<?php

use App\Models\User;
use App\Services\GraphService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('returns top-level reference concepts for the goods tree', function () {
    Http::fake([
        '*' => Http::response([
            'results' => [
                'bindings' => [
                    [
                        'concept' => ['value' => 'http://ontologie.politie.nl/ref/gpc/concept/10000000'],
                        'label' => ['value' => 'Voedingsmiddelen'],
                        'code' => ['value' => '10000000'],
                        'hasChildren' => ['value' => 'true'],
                    ],
                ],
            ],
        ]),
    ]);

    $this->app->forgetInstance(GraphService::class);

    $this->getJson('/api/referentieconcepten')
        ->assertSuccessful()
        ->assertJsonPath('concepten.0.uri', 'http://ontologie.politie.nl/ref/gpc/concept/10000000')
        ->assertJsonPath('concepten.0.label', 'Voedingsmiddelen')
        ->assertJsonPath('concepten.0.has_children', true)
        ->assertJsonPath('concepten.0.selectable', false);
});

it('returns children for an expanded goods tree branch', function () {
    $parent = 'http://ontologie.politie.nl/ref/gpc/concept/10000000';

    Http::fake([
        '*' => Http::response([
            'results' => [
                'bindings' => [
                    [
                        'concept' => ['value' => 'http://ontologie.politie.nl/ref/gpc/concept/10000100'],
                        'label' => ['value' => 'Koffie en thee'],
                        'code' => ['value' => '10000100'],
                        'hasChildren' => ['value' => 'false'],
                    ],
                ],
            ],
        ]),
    ]);

    $this->app->forgetInstance(GraphService::class);

    $this->getJson('/api/referentieconcepten?parent='.urlencode($parent))
        ->assertSuccessful()
        ->assertJsonPath('concepten.0.uri', 'http://ontologie.politie.nl/ref/gpc/concept/10000100')
        ->assertJsonPath('concepten.0.has_children', false)
        ->assertJsonPath('concepten.0.selectable', true);

    Http::assertSent(fn ($request) => str_contains($request['query'], "skos:broader <{$parent}>"));
});

it('resolves labels from the goods terminology for the consult view', function () {
    $concept = 'http://ontologie.politie.nl/ref/gpc/concept/10000100';

    Http::fake([
        '*' => Http::response([
            'results' => [
                'bindings' => [
                    [
                        'concept' => ['value' => $concept],
                        'label' => ['value' => 'Koffie en thee'],
                    ],
                ],
            ],
        ]),
    ]);

    $this->app->forgetInstance(GraphService::class);

    $this->postJson('/api/referentieconcepten/labels', ['uris' => [$concept]])
        ->assertSuccessful()
        ->assertJsonFragment([$concept => 'Koffie en thee']);

    Http::assertSent(fn ($request) => str_contains($request['query'], "VALUES ?concept { <{$concept}> }"));
});

it('rejects reference concepts outside the configured goods scheme', function () {
    $this->getJson('/api/referentieconcepten?parent=https%3A%2F%2Fexample.com%2Fconcept')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parent');
});

it('rejects labels outside the configured goods scheme', function () {
    $this->postJson('/api/referentieconcepten/labels', ['uris' => ['https://example.com/concept']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('uris');
});
