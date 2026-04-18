<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GraphService
{
    // Deze declaraties halen de waarschuwingen in VSCode weg
    protected $baseUrl;
    protected $repository;

    public function __construct()
    {
        // We halen de waarden nu uit de zojuist gemaakte config
        $this->baseUrl = config('services.graphdb.url');
        $this->repository = config('services.graphdb.repository');
    }

    public function query(string $sparql)
    {
        $endpoint = "{$this->baseUrl}/repositories/{$this->repository}";

        $response = Http::withHeaders([
            'Accept' => 'application/sparql-results+json',
        ])->get($endpoint, [
            'query' => $sparql
        ]);

        if ($response->failed()) {
            throw new \Exception("GraphDB Error: " . $response->body());
        }

        return $this->simplifyResults($response->json());
    }

    protected function simplifyResults(array $data)
    {
        $results = [];
        if (!isset($data['results']['bindings'])) return [];

        foreach ($data['results']['bindings'] as $binding) {
            $row = [];
            foreach ($binding as $key => $value) {
                $row[$key] = $value['value'];
            }
            $results[] = $row;
        }
        return $results;
    }

    /**
 * Voert een SPARQL UPDATE (INSERT/DELETE) uit op GraphDB.
 */
    public function update(string $sparql)
    {
    // Let op: Bij updates gebruiken we het /statements endpoint
    $endpoint = "{$this->baseUrl}/repositories/{$this->repository}/statements";

    if (!preg_match('//u', $sparql)) {
        $sparql = iconv('UTF-8', 'UTF-8//IGNORE', $sparql);
    }

    $response = Http::withHeaders([
        'Content-Type' => 'application/sparql-update',
    ])->withBody($sparql, 'application/sparql-update')
      ->post($endpoint);

    if ($response->failed()) {
        throw new \Exception("GraphDB Update Fout: " . $response->body());
    }

    return true;
    }

    /**
     * Voert SHACL-validatie uit in GraphDB en geeft de conformiteit + rapport terug.
     * Vereist dat GraphDB de /rest/repositories/{repo}/validate/* endpoints ondersteunt.
     */
    public function validateShacl(?string $shapesRepository = null): array
    {
        $dataRepo = $this->repository;
        $shapeRepo = $shapesRepository ?? $this->repository;
        $endpoint = "{$this->baseUrl}/rest/repositories/{$dataRepo}/validate/repository/{$shapeRepo}";

        $response = Http::withHeaders([
            'Accept' => 'text/turtle',
        ])->post($endpoint);

        if ($response->failed()) {
            throw new \Exception("GraphDB SHACL Validate Fout: " . $response->body());
        }

        $report = $response->body();
        $conforms = !str_contains($report, 'sh:conforms false') && !str_contains($report, 'conforms false');

        return [
            'conforms' => $conforms,
            'report' => $report,
        ];
    }
}
