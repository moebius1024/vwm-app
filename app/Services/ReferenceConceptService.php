<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ReferenceConceptService
{
    private const GRAPH = 'http://vwm.voorbeeld.nl/model/skos/gs1-gpc';

    private const SCHEME = 'http://ontologie.politie.nl/ref/gpc/scheme';

    private const CONCEPT_BASE = 'http://ontologie.politie.nl/ref/gpc/concept/';

    public function __construct(private readonly GraphService $graphService) {}

    /** @return list<array{uri:string,label:string,code:string,has_children:bool,selectable:bool}> */
    public function children(?string $parentUri = null): array
    {
        if ($parentUri !== null && ! str_starts_with($parentUri, self::CONCEPT_BASE)) {
            throw ValidationException::withMessages(['parent' => 'Ongeldige referentie-URI.']);
        }

        $parentPattern = $parentUri === null ? '?concept skos:topConceptOf <'.self::SCHEME.'> .' : "?concept skos:broader <{$parentUri}> .";
        $rows = $this->graphService->query("PREFIX skos: <http://www.w3.org/2004/02/skos/core#>\nSELECT ?concept ?label ?code (EXISTS { ?child skos:broader ?concept } AS ?hasChildren) WHERE { GRAPH <".self::GRAPH."> { {$parentPattern} ?concept skos:prefLabel ?label ; skos:notation ?code ; skos:inScheme <".self::SCHEME.'> . } } ORDER BY ?label');

        return array_map(fn (array $row) => [
            'uri' => $row['concept'], 'label' => $row['label'], 'code' => $row['code'],
            'has_children' => filter_var($row['hasChildren'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'selectable' => ! filter_var($row['hasChildren'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], $rows);
    }

    /** @param list<string> $uris
     * @return array<string, string>
     */
    public function labels(array $uris): array
    {
        foreach ($uris as $uri) {
            if (! str_starts_with($uri, self::CONCEPT_BASE)) {
                throw ValidationException::withMessages(['uris' => 'Ongeldige referentie-URI.']);
            }
        }

        $values = implode(' ', array_map(fn (string $uri) => "<{$uri}>", array_unique($uris)));
        $rows = $this->graphService->query("PREFIX skos: <http://www.w3.org/2004/02/skos/core#>\nSELECT ?concept ?label WHERE { GRAPH <".self::GRAPH."> { VALUES ?concept { {$values} } ?concept skos:prefLabel ?label ; skos:inScheme <".self::SCHEME.'> . } }');

        $labels = [];
        foreach ($rows as $row) {
            if (isset($row['concept'], $row['label'])) {
                $labels[$row['concept']] = $row['label'];
            }
        }

        return $labels;
    }
}
