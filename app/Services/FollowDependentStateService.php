<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FollowDependentStateService
{
    private const DATA_GRAPH = 'http://vwm.voorbeeld.nl/data/onderzoek';

    private const DEPENDENT_TB_CLASS = 'http://ontologie.politie.nl/def/vwm#AfhankelijkeTB';

    private const REFERENCE_PROPERTY = 'http://ontologie.politie.nl/def/vwm#verwijstNaar';

    public function __construct(
        private readonly GraphService $graphService,
        private readonly SjabloonMetadataService $metadataService,
        private readonly CaseMutationContextService $caseMutationContextService,
    ) {}

    /**
     * @param  array{case_id:int,target_goic_id:int,source_tb_uri:string}  $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function follow(array $input, int $userId): array
    {
        $context = $this->caseMutationContextService->resolveFollowContext((int) $input['case_id'], $userId);
        if (($context['reason'] ?? null) === 'case_not_accessible') {
            return $this->error('Geen toegang tot deze case.', 403);
        }
        if (($context['reason'] ?? null) === 'transactie_soort_missing') {
            return $this->error('Geen transactie-soort beschikbaar.');
        }

        $target = $this->targetGoic((int) $input['target_goic_id'], (int) $input['case_id'], $userId);
        if ($target === null) {
            return $this->error('Doelregistratie niet gevonden.', 404);
        }

        $sourceTbUri = trim($input['source_tb_uri']);
        if (! $this->isHttpUri($sourceTbUri)) {
            return $this->error('Ongeldige bronbeschrijving.');
        }

        $source = $this->sourceMetadata($sourceTbUri, (string) $target->rdf_uri);
        if ($source === null || ! $this->sourceIsAccessible((string) $source['goic_uri'], $userId)) {
            return $this->error('Bronbeschrijving niet gevonden.', 404);
        }

        $capabilities = $this->metadataService->fetchTbClassCapabilitiesByTbClasses([$source['tb_class']]);
        $sourceCapabilities = $capabilities[$source['tb_class']] ?? [];
        if (($sourceCapabilities['is_kern_tb'] ?? false) || ($sourceCapabilities['is_role_beschrijving'] ?? false)) {
            return $this->error('Deze beschrijving kan niet worden gevolgd.');
        }

        if ($this->hasActiveDependentState((string) $target->rdf_uri, $sourceTbUri)) {
            return [
                'status' => 200,
                'payload' => [
                    'message' => 'Deze beschrijving wordt al gevolgd.',
                    'already_exists' => true,
                ],
            ];
        }

        $now = now();
        $nowIso = $now->toAtomString();
        $tbUuid = (string) Str::uuid();
        $tbUri = "http://vwm.voorbeeld.nl/data/tb/{$tbUuid}";
        $mutationUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.((string) Str::uuid());
        $triples = $this->triples($tbUri, (string) $target->rdf_uri, $sourceTbUri, $mutationUri, $nowIso);
        $graphUpdated = false;

        DB::beginTransaction();
        try {
            $transactionId = DB::table('transacties')->insertGetId([
                'case_id' => (int) $input['case_id'],
                'transactie_soort_id' => (int) $context['transactie_soort_id'],
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $tbId = DB::table('toestands_beschrijvingen')->insertGetId([
                'uuid' => $tbUuid,
                'rdf_uri' => $tbUri,
                'beschrijving' => self::DEPENDENT_TB_CLASS,
                'toestand_data' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('object_mutaties')->insert([
                'transactie_id' => $transactionId,
                'sjabloon_uri' => self::DEPENDENT_TB_CLASS,
                'object_uri' => $tbUri,
                'rdf_uri' => $mutationUri,
                'gegevens_object_in_context_id' => (int) $target->id,
                'geproduceerde_toestand_id' => $tbId,
                'datum_tijd' => $now,
                'data' => json_encode([
                    'actie' => 'volg_toestand',
                    self::REFERENCE_PROPERTY => $sourceTbUri,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->graphService->update('INSERT DATA { GRAPH <'.self::DATA_GRAPH."> { {$triples} } }");
            $graphUpdated = true;
            $validation = $this->graphService->validateShacl();
            if (! $validation['conforms']) {
                $this->deleteTriples($triples);
                DB::rollBack();

                return $this->error('De afhankelijke beschrijving voldoet niet aan de validatieregels.');
            }

            DB::commit();
        } catch (\Throwable $exception) {
            if ($graphUpdated) {
                $this->deleteTriples($triples);
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            logger()->error('Volgen van toestand mislukt', [
                'case_id' => $input['case_id'],
                'target_goic_id' => $input['target_goic_id'],
                'source_tb_uri' => $sourceTbUri,
                'message' => $exception->getMessage(),
            ]);

            return $this->error('Volgen van deze beschrijving is mislukt.', 500);
        }

        return [
            'status' => 200,
            'payload' => [
                'message' => 'De beschrijving wordt nu gevolgd.',
                'tb_uri' => $tbUri,
            ],
        ];
    }

    /** @return array{id:int,rdf_uri:string}|null */
    private function targetGoic(int $targetGoicId, int $caseId, int $userId): ?object
    {
        return DB::table('gegevens_objecten_in_context')
            ->join('dossiers', 'dossiers.id', '=', 'gegevens_objecten_in_context.dossier_id')
            ->join('cases', 'cases.id', '=', 'dossiers.case_id')
            ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
            ->where('gegevens_objecten_in_context.id', $targetGoicId)
            ->where('dossiers.case_id', $caseId)
            ->where('cases.user_id', $userId)
            ->whereIn('case_soorten.rechtsgrond_id', $this->allowedRechtsgrondIdsForUser($userId))
            ->first(['gegevens_objecten_in_context.id', 'gegevens_objecten_in_context.rdf_uri']);
    }

    /** @return array{goic_uri:string,tb_class:string}|null */
    private function sourceMetadata(string $sourceTbUri, string $targetGoicUri): ?array
    {
        $rows = $this->graphService->query('
            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?sourceGoic ?tbClass
            WHERE {
                GRAPH <'.self::DATA_GRAPH."> {
                    <{$sourceTbUri}> a ?tbClass ; vwm:beschrijftGOIC ?sourceGoic .
                    ?sourceGoic vwm:beschrijftGO ?go .
                    <{$targetGoicUri}> vwm:beschrijftGO ?go .
                    FILTER NOT EXISTS { <{$sourceTbUri}> dpm:invalidatedAtTime ?invalidatedAt . }
                }
            }
            LIMIT 1
        ");
        $row = $rows[0] ?? [];
        $sourceGoicUri = $row['sourceGoic'] ?? null;
        $tbClass = $row['tbClass'] ?? null;

        if (! is_string($sourceGoicUri) || $sourceGoicUri === '' || ! is_string($tbClass) || $tbClass === '') {
            return null;
        }

        return ['goic_uri' => $sourceGoicUri, 'tb_class' => $tbClass];
    }

    private function sourceIsAccessible(string $sourceGoicUri, int $userId): bool
    {
        return DB::table('gegevens_objecten_in_context')
            ->join('dossiers', 'dossiers.id', '=', 'gegevens_objecten_in_context.dossier_id')
            ->join('cases', 'cases.id', '=', 'dossiers.case_id')
            ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
            ->where('gegevens_objecten_in_context.rdf_uri', $sourceGoicUri)
            ->where('cases.user_id', $userId)
            ->whereIn('case_soorten.rechtsgrond_id', $this->allowedRechtsgrondIdsForUser($userId))
            ->exists();
    }

    /** @return array<int, int> */
    private function allowedRechtsgrondIdsForUser(int $userId): array
    {
        $ids = DB::table('medewerkers')
            ->join('functies', 'functies.medewerker_id', '=', 'medewerkers.id')
            ->join('autorisatie_rollen', 'autorisatie_rollen.functie_soort_id', '=', 'functies.functie_soort_id')
            ->where('medewerkers.user_id', $userId)
            ->whereNotNull('autorisatie_rollen.rechtsgrond_id')
            ->distinct()
            ->pluck('autorisatie_rollen.rechtsgrond_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $ids !== [] ? $ids : [-1];
    }

    private function hasActiveDependentState(string $targetGoicUri, string $sourceTbUri): bool
    {
        $rows = $this->graphService->query('
            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?dependent
            WHERE {
                GRAPH <'.self::DATA_GRAPH."> {
                    ?dependent a vwm:AfhankelijkeTB ;
                        vwm:beschrijftGOIC <{$targetGoicUri}> ;
                        vwm:verwijstNaar <{$sourceTbUri}> .
                    FILTER NOT EXISTS { ?dependent dpm:invalidatedAtTime ?invalidatedAt . }
                }
            }
            LIMIT 1
        ");

        return isset($rows[0]['dependent']);
    }

    private function triples(string $tbUri, string $targetGoicUri, string $sourceTbUri, string $mutationUri, string $nowIso): string
    {
        $vwm = 'http://ontologie.politie.nl/def/vwm#';

        return "
            <{$tbUri}> a <".self::DEPENDENT_TB_CLASS."> ;
                <{$vwm}beschrijftGOIC> <{$targetGoicUri}> ;
                <".self::REFERENCE_PROPERTY."> <{$sourceTbUri}> ;
                <{$vwm}geregistreerdOp> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .
            <{$mutationUri}> a <{$vwm}ObjectMutatie> ;
                <{$vwm}heeftBetrekkingOp> <{$targetGoicUri}> ;
                <{$vwm}produceert> <{$tbUri}> ;
                <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .
        ";
    }

    private function deleteTriples(string $triples): void
    {
        $this->graphService->update('DELETE DATA { GRAPH <'.self::DATA_GRAPH."> { {$triples} } }");
    }

    /** @return array{status:int,payload:array{error:string}} */
    private function error(string $message, int $status = 422): array
    {
        return ['status' => $status, 'payload' => ['error' => $message]];
    }

    private function isHttpUri(string $uri): bool
    {
        return preg_match('/^https?:\/\/[^\s<>"\']+$/', $uri) === 1;
    }
}
