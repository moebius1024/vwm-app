<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ObjectMutationCommitService
{
    public function __construct(
        private readonly GraphService $graphService,
        private readonly SjabloonMetadataService $metadataService,
        private readonly RoleMutationService $roleMutationService,
        private readonly RoleMutationWriter $roleMutationWriter,
        private readonly AutoRoleMutationService $autoRoleMutationService,
    ) {}

    /**
     * @param  array<string, mixed>  $base
     * @param  array<int, array<string, mixed>>  $objects
     * @param  array<string, array<string, mixed>>  $roleShapeRules
     * @param  array<string, string>  $rolTypesByKey
     * @param  array<int, string>  $allowedRoleSelectors
     * @param  array<string, string>  $roleCrudBySelector
     * @param  array<int, string>  $tbClasses
     * @param  array<int, string>  $goicTargetClassMap
     * @return array{status:int,payload:array<string,mixed>,options?:int}
     */
    public function commit(
        array $base,
        array $objects,
        array $roleShapeRules,
        array $rolTypesByKey,
        array $allowedRoleSelectors,
        array $roleCrudBySelector,
        bool $enforceAllowedRole,
        object $dossier,
        int $userId,
        string $mode,
        ?object $mutationTargetMeta,
        array $goicTargetClassMap,
        array $tbClasses,
        array $roles
    ): array {
        $objectUris = [];
        $objectMeta = [];
        $allTriples = '';
        $now = now();
        $nowIso = $now->toAtomString();
        $vwm = 'http://ontologie.politie.nl/def/vwm#';
        $graphUpdated = false;
        $valueHintsByTbClass = $this->metadataService->fetchPropertyValueHintsByTbClasses($tbClasses);
        $identityRulesByTbClass = $this->metadataService->fetchIdentityRulesByTbClasses($tbClasses);
        $identityEntries = $this->collectIdentityEntriesForObjects($objects, $identityRulesByTbClass);
        $existingGoByIdentityKey = $this->fetchExistingGoByIdentityEntries($identityEntries);

        DB::beginTransaction();

        try {
            $transactieId = DB::transaction(function () use ($base, $objects, &$objectUris, &$allTriples, &$objectMeta, $dossier, $nowIso, $vwm, $valueHintsByTbClass, $identityRulesByTbClass, $existingGoByIdentityKey, $userId, $mode, $mutationTargetMeta) {
                $transactieId = DB::table('transacties')->insertGetId([
                    'case_id' => $base['case_id'],
                    'transactie_soort_id' => $base['transactie_soort_id'],
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $goByIdentityKey = $existingGoByIdentityKey;

                if ($mode === 'mutate' && $mutationTargetMeta) {
                    $invalidateMutatieUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.((string) Str::uuid());
                    DB::table('object_mutaties')->insert([
                        'transactie_id' => $transactieId,
                        'sjabloon_uri' => (string) ($mutationTargetMeta->tb_class ?? ''),
                        'object_uri' => (string) $mutationTargetMeta->tb_uri,
                        'gegevens_object_in_context_id' => (int) $mutationTargetMeta->goic_id,
                        'geproduceerde_toestand_id' => null,
                        'verwijderde_toestand_id' => isset($mutationTargetMeta->tb_id) ? (int) $mutationTargetMeta->tb_id : null,
                        'datum_tijd' => now(),
                        'data' => json_encode([
                            'actie' => 'beeindig_toestand',
                            'tb_uri' => (string) $mutationTargetMeta->tb_uri,
                            'invalidatedAtTime' => $nowIso,
                        ], JSON_UNESCAPED_SLASHES),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $allTriples .= "<{$mutationTargetMeta->tb_uri}> <http://ontologie.politie.nl/def/dpm#invalidatedAtTime> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> . \n";
                    $allTriples .= "<{$invalidateMutatieUri}> a <{$vwm}ObjectMutatie> . \n";
                    $allTriples .= "<{$invalidateMutatieUri}> <{$vwm}heeftBetrekkingOp> <{$mutationTargetMeta->goic_uri}> . \n";
                    $allTriples .= "<{$invalidateMutatieUri}> <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> . \n";
                }

                foreach ($objects as $object) {
                    $tbClass = $object['sjabloon_uri'];
                    $describedClass = $object['target_class'];
                    $existingGoicId = isset($object['existing_goic_id']) ? (int) $object['existing_goic_id'] : null;

                    $identityEntry = $this->resolveObjectIdentityEntry($object, $identityRulesByTbClass);
                    $identityKey = is_array($identityEntry) ? ($identityEntry['key'] ?? null) : null;
                    $goUri = is_string($identityKey)
                        ? ($goByIdentityKey[$identityKey] ?? null)
                        : null;

                    if (! is_string($goUri) || $goUri === '') {
                        $goUri = 'http://vwm.voorbeeld.nl/data/go/'.((string) Str::uuid());
                    }

                    if (is_string($identityKey) && $identityKey !== '') {
                        $goByIdentityKey[$identityKey] = $goUri;
                    }

                    $tbUuid = (string) Str::uuid();
                    $mutatieUuid = (string) Str::uuid();

                    $goicUri = null;
                    $tbUri = 'http://vwm.voorbeeld.nl/data/tb/'.$tbUuid;
                    $mutatieUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.$mutatieUuid;

                    $objectUris[] = $tbUri;

                    if ($existingGoicId !== null && $existingGoicId > 0) {
                        $existingGoic = DB::table('gegevens_objecten_in_context')
                            ->where('id', $existingGoicId)
                            ->where('dossier_id', $dossier->id)
                            ->first(['id', 'rdf_uri']);

                        if (! $existingGoic) {
                            throw new \RuntimeException('Bestaand GOIC niet gevonden in dit dossier.');
                        }

                        $goicId = (int) $existingGoic->id;
                        $goicUri = (string) $existingGoic->rdf_uri;
                    } else {
                        $goicUuid = (string) Str::uuid();
                        $goicUri = 'http://vwm.voorbeeld.nl/data/goic/'.$goicUuid;

                        $goicId = DB::table('gegevens_objecten_in_context')->insertGetId([
                            'uuid' => $goicUuid,
                            'rdf_uri' => $goicUri,
                            'dossier_id' => $dossier->id,
                            'context_data' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $tbId = DB::table('toestands_beschrijvingen')->insertGetId([
                        'uuid' => $tbUuid,
                        'rdf_uri' => $tbUri,
                        'beschrijving' => $tbClass,
                        'toestand_data' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $objectMeta[] = [
                        'tb_uri' => $tbUri,
                        'goic_uri' => $goicUri,
                        'goic_id' => $goicId,
                        'tb_id' => $tbId,
                        'target_class' => $describedClass,
                        'client_id' => $object['client_id'] ?? null,
                    ];

                    DB::table('object_mutaties')->insert([
                        'transactie_id' => $transactieId,
                        'sjabloon_uri' => $tbClass,
                        'object_uri' => $tbUri,
                        'gegevens_object_in_context_id' => $goicId,
                        'geproduceerde_toestand_id' => $tbId,
                        'datum_tijd' => now(),
                        'data' => json_encode($object['data']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($existingGoicId === null || $existingGoicId <= 0) {
                        $allTriples .= "<{$goUri}> a <{$vwm}GegevensObject> . \n";
                        $allTriples .= "<{$goicUri}> a <{$vwm}GegevensObjectInContext> . \n";
                        $allTriples .= "<{$goicUri}> <{$vwm}beschrijftGO> <{$goUri}> . \n";
                        $allTriples .= "<{$goicUri}> <{$vwm}heeftDoelClass> <{$describedClass}> . \n";
                        $allTriples .= "<{$goicUri}> <{$vwm}hoortBijDossier> <{$dossier->rdf_uri}> . \n";
                    }
                    $allTriples .= "<{$tbUri}> a <{$tbClass}> . \n";
                    $allTriples .= "<{$tbUri}> <{$vwm}beschrijftGOIC> <{$goicUri}> . \n";
                    $allTriples .= "<{$tbUri}> <{$vwm}geregistreerdOp> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> . \n";

                    $allTriples .= "<{$mutatieUri}> a <{$vwm}ObjectMutatie> . \n";
                    $allTriples .= "<{$mutatieUri}> <{$vwm}heeftBetrekkingOp> <{$goicUri}> . \n";
                    $allTriples .= "<{$mutatieUri}> <{$vwm}produceert> <{$tbUri}> . \n";
                    $allTriples .= "<{$mutatieUri}> <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> . \n";

                    $dataTypes = $object['data_types'] ?? [];
                    $valueHints = $valueHintsByTbClass[$tbClass] ?? [];

                    foreach ($object['data'] as $propertyUri => $value) {
                        $valueType = $this->resolveValueType(
                            $dataTypes[$propertyUri] ?? null,
                            $valueHints[$propertyUri] ?? null
                        );

                        if (is_array($value)) {
                            foreach ($value as $entry) {
                                if ($entry === null || $entry === '') {
                                    continue;
                                }
                                $sparqlValue = $this->toSparqlValue($entry, $valueType);
                                $allTriples .= "<{$tbUri}> <{$propertyUri}> {$sparqlValue} . \n";
                            }

                            continue;
                        }

                        if ($value === null || $value === '') {
                            continue;
                        }

                        $sparqlValue = $this->toSparqlValue($value, $valueType);
                        $allTriples .= "<{$tbUri}> <{$propertyUri}> {$sparqlValue} . \n";
                    }
                }

                return $transactieId;
            });

            $goicByClass = [];
            foreach ($objectMeta as $meta) {
                $goicByClass[$meta['target_class']][] = $meta['goic_uri'];
            }

            $caseDossierIds = DB::table('dossiers')
                ->where('case_id', $base['case_id'])
                ->pluck('id')
                ->all();

            $existingGoics = [];
            if (! empty($caseDossierIds)) {
                $existingGoics = DB::table('gegevens_objecten_in_context')
                    ->whereIn('dossier_id', $caseDossierIds)
                    ->get(['id', 'rdf_uri'])
                    ->all();
            }

            $goicMetaById = [];
            foreach ($existingGoics as $goic) {
                $targetClass = $goicTargetClassMap[(int) $goic->id] ?? null;

                $goicMetaById[$goic->id] = [
                    'goic_id' => $goic->id,
                    'goic_uri' => $goic->rdf_uri,
                    'target_class' => $targetClass,
                ];
                if ($targetClass) {
                    $goicByClass[$targetClass][] = $goic->rdf_uri;
                }
            }

            $roleItems = $this->roleMutationService->normalizeRoleItems($roles, $rolTypesByKey);
            $roleTbClassesFromItems = $this->roleMutationService->collectRoleTbClasses($roleItems);
            $rolTbMetaByClass = $this->metadataService->fetchRolTbMetaByClasses($roleTbClassesFromItems);

            $clientMap = $this->roleMutationService->buildClientMap($objectMeta);

            $roleItems = $this->autoRoleMutationService->appendAutoRoleItems(
                $roleItems,
                $objects,
                $objectMeta,
                $goicByClass,
                $roleShapeRules
            );

            $roleMutationPlans = $this->roleMutationService->buildRoleMutationPlans(
                $roleItems,
                $rolTbMetaByClass,
                $roleShapeRules,
                $allowedRoleSelectors,
                $roleCrudBySelector,
                $enforceAllowedRole,
                $goicMetaById,
                $clientMap,
                $goicByClass
            );

            $roleWriteResult = $this->roleMutationWriter->writeRoleMutations(
                $transactieId,
                $roleMutationPlans,
                $now,
                $nowIso,
                $vwm
            );
            $allTriples .= $roleWriteResult['triples'];

            $producedMutationCount = DB::table('object_mutaties')
                ->where('transactie_id', $transactieId)
                ->count();

            if ($producedMutationCount === 0 || trim($allTriples) === '') {
                $this->rejectNoOpMutatie();
            }

            $sparqlUpdate = "
            INSERT DATA {
                GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                    {$allTriples}
                }
            }
        ";

            $this->graphService->update($sparqlUpdate);
            $graphUpdated = true;

            $validation = $this->graphService->validateShacl();
            if (! $validation['conforms']) {
                $this->rollbackGraphTriples($allTriples);
                DB::rollBack();

                $safeReport = $this->sanitizeForJson((string) ($validation['report'] ?? ''));

                return $this->result([
                    'error' => 'SHACL validatie faalde. Mutatie is teruggedraaid.',
                    'report' => $safeReport,
                ], 422, JSON_INVALID_UTF8_SUBSTITUTE);
            }

            DB::commit();
        } catch (ValidationException $e) {
            if ($graphUpdated) {
                $this->rollbackGraphTriples($allTriples);
            }

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return $this->result([
                'error' => $this->validationExceptionMessage($e),
                'errors' => $e->errors(),
            ], 422, JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Throwable $e) {
            if ($graphUpdated) {
                $this->rollbackGraphTriples($allTriples);
            }

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $safeMessage = $this->sanitizeForJson($e->getMessage());
            logger()->error('GraphDB update exception', [
                'message' => $safeMessage,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->result([
                'error' => 'GraphDB Update mislukt: '.$safeMessage,
            ], 500, JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return $this->result([
            'status' => 'success',
            'message' => 'Objecten opgeslagen en gesynchroniseerd met GraphDB',
            'transactie_id' => $transactieId,
            'object_uris' => $objectUris,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status:int,payload:array<string,mixed>,options?:int}
     */
    private function result(array $payload, int $status = 200, int $options = 0): array
    {
        $result = [
            'status' => $status,
            'payload' => $payload,
        ];

        if ($options !== 0) {
            $result['options'] = $options;
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $objects
     * @param  array<string, array<int, array<string, string>>>  $identityRulesByTbClass
     * @return array<int, array<string, string>>
     */
    private function collectIdentityEntriesForObjects(array $objects, array $identityRulesByTbClass): array
    {
        $entries = [];
        foreach ($objects as $object) {
            $entry = $this->resolveObjectIdentityEntry($object, $identityRulesByTbClass);
            if (! is_array($entry)) {
                continue;
            }

            $key = $entry['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $entries[$key] = $entry;
        }

        return array_values($entries);
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, array<int, array<string, string>>>  $identityRulesByTbClass
     * @return array<string, string>|null
     */
    private function resolveObjectIdentityEntry(array $object, array $identityRulesByTbClass): ?array
    {
        $tbClass = $object['sjabloon_uri'] ?? null;
        if (! is_string($tbClass) || $tbClass === '') {
            return null;
        }

        $rules = $identityRulesByTbClass[$tbClass] ?? [];
        if (! is_array($rules) || empty($rules)) {
            return null;
        }

        $data = $object['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        foreach ($rules as $rule) {
            $property = $rule['property'] ?? null;
            if (! is_string($property) || $property === '') {
                continue;
            }

            $rawValue = $data[$property] ?? null;
            if (! is_scalar($rawValue)) {
                continue;
            }

            $normalizer = strtoupper(trim((string) ($rule['normalizer'] ?? 'NONE')));
            $normalizedValue = $this->normalizeIdentityValue((string) $rawValue, $normalizer);
            if (! is_string($normalizedValue) || $normalizedValue === '') {
                continue;
            }

            return [
                'key' => $this->buildIdentityCacheKey($tbClass, $property, $normalizer, $normalizedValue),
                'tb_class' => $tbClass,
                'property' => $property,
                'normalizer' => $normalizer,
                'normalized_value' => $normalizedValue,
            ];
        }

        return null;
    }

    /**
     * @param  array<int, array<string, string>>  $identityEntries
     * @return array<string, string>
     */
    private function fetchExistingGoByIdentityEntries(array $identityEntries): array
    {
        if (empty($identityEntries)) {
            return [];
        }

        $entriesByRule = [];
        foreach ($identityEntries as $entry) {
            $tbClass = $entry['tb_class'] ?? null;
            $property = $entry['property'] ?? null;
            $normalizer = $entry['normalizer'] ?? 'NONE';
            $normalizedValue = $entry['normalized_value'] ?? null;
            if (! is_string($tbClass) || $tbClass === '' || ! is_string($property) || $property === '') {
                continue;
            }
            if (! is_string($normalizedValue) || $normalizedValue === '') {
                continue;
            }

            $ruleKey = $tbClass.'|'.$property.'|'.$normalizer;
            if (! isset($entriesByRule[$ruleKey])) {
                $entriesByRule[$ruleKey] = [
                    'tb_class' => $tbClass,
                    'property' => $property,
                    'normalizer' => $normalizer,
                    'values' => [],
                ];
            }
            $entriesByRule[$ruleKey]['values'][$normalizedValue] = true;
        }

        if (empty($entriesByRule)) {
            return [];
        }

        $goByIdentityKey = [];

        foreach ($entriesByRule as $rule) {
            $tbClass = $rule['tb_class'];
            $property = $rule['property'];
            $normalizer = $rule['normalizer'];
            $wantedValues = $rule['values'];

            $query = "
                PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
                SELECT ?rawValue ?go ?goic
                WHERE {
                    GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                        ?tb a <{$tbClass}> ;
                            <{$property}> ?rawValue ;
                            vwm:beschrijftGOIC ?goic .
                        ?goic vwm:beschrijftGO ?go .
                    }
                }
                ORDER BY ?goic
            ";

            $rows = $this->graphService->query($query);
            foreach ($rows as $row) {
                $rawValue = $row['rawValue'] ?? null;
                $goUri = $row['go'] ?? null;
                if (! is_string($rawValue) || $rawValue === '' || ! is_string($goUri) || $goUri === '') {
                    continue;
                }

                $normalizedValue = $this->normalizeIdentityValue($rawValue, $normalizer);
                if (! is_string($normalizedValue) || $normalizedValue === '' || ! isset($wantedValues[$normalizedValue])) {
                    continue;
                }

                $cacheKey = $this->buildIdentityCacheKey($tbClass, $property, $normalizer, $normalizedValue);
                if (! isset($goByIdentityKey[$cacheKey])) {
                    $goByIdentityKey[$cacheKey] = $goUri;
                }
            }
        }

        return $goByIdentityKey;
    }

    private function normalizeIdentityValue(string $value, string $normalizer): ?string
    {
        $strategy = strtoupper(trim($normalizer));
        $source = trim($value);
        if ($source === '') {
            return null;
        }

        return match ($strategy) {
            'ALNUM_UPPER' => ($normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $source) ?? '')) !== '' ? $normalized : null,
            'DIGITS_ONLY' => ($normalized = preg_replace('/\D+/', '', $source) ?? '') !== '' ? $normalized : null,
            'UPPER_TRIM' => strtoupper($source),
            'LOWER_TRIM' => strtolower($source),
            'NONE', 'TRIM', '' => $source,
            default => $source,
        };
    }

    private function buildIdentityCacheKey(string $tbClass, string $property, string $normalizer, string $normalizedValue): string
    {
        return $tbClass.'|'.$property.'|'.$normalizer.'|'.$normalizedValue;
    }

    private function rollbackGraphTriples(string $triples): void
    {
        if (trim($triples) === '') {
            return;
        }

        $rollback = "
            DELETE DATA {
                GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                    {$triples}
                }
            }
        ";

        try {
            $this->graphService->update($rollback);
        } catch (\Throwable $e) {
            logger()->warning('GraphDB rollback exception', [
                'message' => $this->sanitizeForJson($e->getMessage()),
            ]);
        }
    }

    private function toSparqlValue(mixed $value, string $type): string
    {
        if ($type === 'uri' && is_string($value)) {
            $trimmed = trim($value);
            if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
                return "<{$trimmed}>";
            }
        }

        if ($type === 'integer') {
            $lexical = trim((string) $value);
            if (preg_match('/^-?\d+$/', $lexical)) {
                return $this->toSparqlTypedLiteral($lexical, 'http://www.w3.org/2001/XMLSchema#integer');
            }
        }

        if ($type === 'decimal') {
            $lexical = trim((string) $value);
            if (preg_match('/^-?\d+(?:\.\d+)?$/', $lexical)) {
                return $this->toSparqlTypedLiteral($lexical, 'http://www.w3.org/2001/XMLSchema#decimal');
            }
        }

        if ($type === 'date') {
            $lexical = trim((string) $value);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $lexical)) {
                return $this->toSparqlTypedLiteral($lexical, 'http://www.w3.org/2001/XMLSchema#date');
            }
        }

        if ($type === 'dateTime') {
            $lexical = $this->normalizeDateTimeLexical((string) $value);
            if ($lexical !== null) {
                return $this->toSparqlTypedLiteral($lexical, 'http://www.w3.org/2001/XMLSchema#dateTime');
            }
        }

        return $this->toSparqlLiteral($value);
    }

    private function toSparqlTypedLiteral(string $value, string $datatypeIri): string
    {
        $escaped = str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value
        );

        return "\"{$escaped}\"^^<{$datatypeIri}>";
    }

    private function toSparqlLiteral(mixed $value): string
    {
        $string = is_string($value) ? $value : json_encode($value);
        $escaped = str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $string ?? ''
        );

        return "\"{$escaped}\"";
    }

    private function normalizeDateTimeLexical(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = str_replace(' ', 'T', $trimmed);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized)) {
            return $normalized.'T00:00:00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $normalized)) {
            return $normalized.':00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}Z$/', $normalized)) {
            return substr($normalized, 0, 16).':00Z';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $normalized)) {
            return substr($normalized, 0, 16).':00'.substr($normalized, 16);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/', $normalized)) {
            return $normalized;
        }

        return null;
    }

    private function resolveValueType(?string $explicitType, ?string $hintType): string
    {
        if ($explicitType === 'uri') {
            return $explicitType;
        }

        if (is_string($explicitType) && in_array($explicitType, ['integer', 'decimal', 'date', 'dateTime'], true)) {
            return $explicitType;
        }

        if ($explicitType === 'literal' && is_string($hintType) && in_array($hintType, ['integer', 'decimal', 'date', 'dateTime'], true)) {
            return $hintType;
        }

        if (is_string($hintType) && in_array($hintType, ['uri', 'integer', 'decimal', 'date', 'dateTime'], true)) {
            return $hintType;
        }

        if ($explicitType === 'literal') {
            return 'literal';
        }

        return 'literal';
    }

    private function rejectNoOpMutatie(): never
    {
        throw ValidationException::withMessages([
            'mutatie' => ['Mutatie heeft geen inhoud opgeleverd.'],
        ]);
    }

    private function validationExceptionMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            $first = $messages[0] ?? null;
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return 'Rol kan niet worden verwerkt.';
    }

    private function sanitizeForJson(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            $value = is_string($converted) ? $converted : '';
        }

        return mb_substr($value, 0, 20000);
    }
}
