<?php

namespace App\Services;

class FindInOtherCaseService
{
    public function __construct(
        private readonly GraphService $graphService,
        private readonly SjabloonMetadataService $sjabloonMetadataService,
    ) {}

    /** @return array{tb_class:string,target_class:string,search_property:string,search_value:string,result_label:?string,same_identity_action_label:?string,already_linked_label:?string}|null */
    public function searchMetadataForGoic(string $goicUri): ?array
    {
        $rows = $this->query("\n            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>\n            SELECT ?tbClass ?targetClass ?searchProperty ?searchValue\n            WHERE {\n                ?tb vwm:beschrijftGOIC <{$goicUri}> ; a ?tbClass .\n                ?tbClass vwm:isFindableInOtherCase true ;\n                         vwm:beschrijftClass ?targetClass ;\n                         vwm:searchProperty ?searchProperty .\n                ?tb ?searchProperty ?searchValue .\n            }\n            LIMIT 1\n        ");
        $metadata = $rows[0] ?? [];
        $required = ['tbClass' => 'tb_class', 'targetClass' => 'target_class', 'searchProperty' => 'search_property', 'searchValue' => 'search_value'];
        $result = [];

        foreach ($required as $rowKey => $resultKey) {
            $value = $metadata[$rowKey] ?? null;
            if (! is_string($value) || $value === '') {
                return null;
            }
            $result[$resultKey] = $value;
        }

        try {
            $buttonLabels = $this->sjabloonMetadataService->fetchSjabloonButtonLabelsByTbClasses([$result['tb_class']]);
        } catch (\Throwable $exception) {
            logger()->warning('Kon UI-metadata voor zoeken in andere case niet laden', ['message' => $exception->getMessage()]);
            $buttonLabels = [];
        }
        $uiMetadata = $buttonLabels[$result['tb_class']] ?? [];
        $result['result_label'] = is_string($uiMetadata['button_label_register'] ?? null)
            ? $uiMetadata['button_label_register']
            : null;
        $result['same_identity_action_label'] = is_string($uiMetadata['same_identity_action_label'] ?? null)
            ? $uiMetadata['same_identity_action_label']
            : null;
        $result['already_linked_label'] = is_string($uiMetadata['already_linked_label'] ?? null)
            ? $uiMetadata['already_linked_label']
            : null;

        return $result;
    }

    /** @return array<int, string> */
    public function findGoicsByValue(string $targetClass, string $searchProperty, string $query): array
    {
        $literal = addcslashes($query, "\\\\\"\n\r");
        $rows = $this->query("\n            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>\n            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>\n            SELECT DISTINCT ?goic\n            WHERE {\n                ?tb vwm:beschrijftGOIC ?goic ; a ?tbClass ; <{$searchProperty}> ?value .\n                ?tbClass vwm:beschrijftClass <{$targetClass}> .\n                FILTER(isLiteral(?value) && CONTAINS(LCASE(STR(?value)), LCASE(\"{$literal}\")))\n                FILTER NOT EXISTS { ?tb dpm:invalidatedAtTime ?invalidatedAt . }\n            }\n            LIMIT 100\n        ");

        return collect($rows)->pluck('goic')->filter(fn (mixed $uri): bool => is_string($uri) && $uri !== '')->unique()->values()->all();
    }

    /**
     * @param  array<int, string>  $goicUris
     * @return array<string, string>
     */
    public function goUrisByGoicUris(array $goicUris): array
    {
        if ($goicUris === []) {
            return [];
        }

        $iriList = implode(' ', array_map(fn (string $uri): string => "<{$uri}>", $goicUris));
        $rows = $this->query("\n            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>\n            SELECT ?goic ?go\n            WHERE {\n                VALUES ?goic { {$iriList} }\n                ?goic vwm:beschrijftGO ?go .\n            }\n        ");
        $result = [];

        foreach ($rows as $row) {
            $goic = $row['goic'] ?? null;
            $go = $row['go'] ?? null;
            if (is_string($goic) && $goic !== '' && is_string($go) && $go !== '') {
                $result[$goic] = $go;
            }
        }

        return $result;
    }

    /** @return array<int, array<string, string>> */
    private function query(string $sparql): array
    {
        try {
            return $this->graphService->query($sparql);
        } catch (\Throwable $exception) {
            logger()->warning('Kon zoekopdracht in andere case niet uit GraphDB lezen', ['message' => $exception->getMessage()]);

            return [];
        }
    }
}
