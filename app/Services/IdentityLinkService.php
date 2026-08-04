<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IdentityLinkService
{
    private const DATA_GRAPH = 'http://vwm.voorbeeld.nl/data/onderzoek';

    private const IDENTITY_LINK_AUDIT_URI = 'http://ontologie.politie.nl/def/vwm#KoppelAanBestaandeIdentiteit';

    public function __construct(
        private readonly GraphService $graphService,
        private readonly CaseMutationContextService $caseMutationContextService,
    ) {}

    /**
     * @param  array{source_case_id:int,source_goic_id:int,candidate_goic_id:int,confirmed:bool}  $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function link(array $input, int $userId): array
    {
        $source = $this->findAccessibleGoic((int) $input['source_goic_id'], (int) $input['source_case_id'], $userId);
        $candidate = $this->findAccessibleCandidate((int) $input['candidate_goic_id'], $userId);

        if ($source === null || $candidate === null || (int) $candidate->case_id === (int) $source->case_id) {
            return $this->error('De geselecteerde registraties zijn niet beschikbaar.', 404);
        }

        $sourceMeta = $this->goicMeta((string) $source->rdf_uri);
        $candidateMeta = $this->goicMeta((string) $candidate->rdf_uri);
        if ($sourceMeta === null || $candidateMeta === null) {
            return $this->error('De identiteit van een registratie kon niet worden vastgesteld.');
        }

        if ($sourceMeta['target_class'] !== $candidateMeta['target_class']) {
            return $this->error('Deze registraties hebben niet hetzelfde type.');
        }

        if ($sourceMeta['go_uri'] === $candidateMeta['go_uri']) {
            return [
                'status' => 200,
                'payload' => [
                    'message' => 'Deze registraties zijn al aan dezelfde identiteit gekoppeld.',
                    'already_linked' => true,
                ],
            ];
        }

        $movedGoicUris = $this->goicsForGo($sourceMeta['go_uri']);
        if ($movedGoicUris === []) {
            return $this->error('Er zijn geen registraties gevonden voor de oorspronkelijke identiteit.');
        }

        $context = $this->caseMutationContextService->resolveTransactionContext((int) $source->case_id, $userId);
        if (($context['reason'] ?? null) === 'transactie_soort_missing') {
            return $this->error('Geen transactie-soort beschikbaar voor deze case.');
        }

        $now = now();
        $nowIso = $now->toAtomString();
        $mutationUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.((string) Str::uuid());

        DB::transaction(function () use ($source, $candidate, $sourceMeta, $candidateMeta, $movedGoicUris, $context, $userId, $now, $nowIso, $mutationUri): void {
            $transactionId = DB::table('transacties')->insertGetId([
                'case_id' => (int) $source->case_id,
                'transactie_soort_id' => (int) $context['transactie_soort_id'],
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('object_mutaties')->insert([
                'transactie_id' => $transactionId,
                'sjabloon_uri' => self::IDENTITY_LINK_AUDIT_URI,
                'object_uri' => (string) $source->rdf_uri,
                'rdf_uri' => $mutationUri,
                'gegevens_object_in_context_id' => (int) $source->id,
                'geproduceerde_toestand_id' => null,
                'datum_tijd' => $now,
                'data' => json_encode([
                    'actie' => 'koppel_aan_bestaande_identiteit',
                    'bronGoic' => (string) $source->rdf_uri,
                    'gevondenGoic' => (string) $candidate->rdf_uri,
                    'oudeGo' => $sourceMeta['go_uri'],
                    'behoudenGo' => $candidateMeta['go_uri'],
                    'verplaatsteGoics' => $movedGoicUris,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->graphService->update($this->mergeUpdate(
                $movedGoicUris,
                $sourceMeta['go_uri'],
                $candidateMeta['go_uri'],
                $mutationUri,
                (string) $source->rdf_uri,
                $nowIso,
            ));
        });

        return [
            'status' => 200,
            'payload' => [
                'message' => 'De registraties zijn aan dezelfde identiteit gekoppeld.',
                'moved_goic_count' => count($movedGoicUris),
            ],
        ];
    }

    /** @return array{go_uri:string,target_class:string}|null */
    private function goicMeta(string $goicUri): ?array
    {
        $rows = $this->graphService->query('
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?go ?targetClass
            WHERE {
                GRAPH <'.self::DATA_GRAPH."> {
                    <{$goicUri}> vwm:beschrijftGO ?go ;
                                vwm:heeftDoelClass ?targetClass .
                }
            }
            LIMIT 1
        ");
        $row = $rows[0] ?? [];
        $goUri = $row['go'] ?? null;
        $targetClass = $row['targetClass'] ?? null;

        if (! is_string($goUri) || $goUri === '' || ! is_string($targetClass) || $targetClass === '') {
            return null;
        }

        return ['go_uri' => $goUri, 'target_class' => $targetClass];
    }

    /** @return array<int, string> */
    private function goicsForGo(string $goUri): array
    {
        $rows = $this->graphService->query('
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?goic
            WHERE {
                GRAPH <'.self::DATA_GRAPH."> {
                    ?goic vwm:beschrijftGO <{$goUri}> .
                }
            }
        ");

        return collect($rows)
            ->pluck('goic')
            ->filter(fn (mixed $uri): bool => is_string($uri) && $this->isHttpUri($uri))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $movedGoicUris
     */
    private function mergeUpdate(array $movedGoicUris, string $sourceGoUri, string $candidateGoUri, string $mutationUri, string $sourceGoicUri, string $nowIso): string
    {
        $goicValues = implode(' ', array_map(fn (string $uri): string => "<{$uri}>", $movedGoicUris));
        $vwm = 'http://ontologie.politie.nl/def/vwm#';

        return '
            DELETE {
                GRAPH <'.self::DATA_GRAPH."> {
                    ?goic <{$vwm}beschrijftGO> <{$sourceGoUri}> .
                }
            }
            INSERT {
                GRAPH <".self::DATA_GRAPH."> {
                    ?goic <{$vwm}beschrijftGO> <{$candidateGoUri}> .
                    <{$mutationUri}> a <{$vwm}ObjectMutatie> ;
                        <{$vwm}heeftBetrekkingOp> <{$sourceGoicUri}> ;
                        <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .
                }
            }
            WHERE {
                VALUES ?goic { {$goicValues} }
                GRAPH <".self::DATA_GRAPH."> {
                    ?goic <{$vwm}beschrijftGO> <{$sourceGoUri}> .
                }
            }
        ";
    }

    private function findAccessibleGoic(int $goicId, int $caseId, int $userId): ?object
    {
        return $this->accessibleGoicsForUser($userId)
            ->where('gegevens_objecten_in_context.id', $goicId)
            ->where('cases.id', $caseId)
            ->first([
                'gegevens_objecten_in_context.id',
                'gegevens_objecten_in_context.rdf_uri',
                'cases.id as case_id',
            ]);
    }

    private function findAccessibleCandidate(int $goicId, int $userId): ?object
    {
        return $this->accessibleGoicsForUser($userId)
            ->where('gegevens_objecten_in_context.id', $goicId)
            ->first([
                'gegevens_objecten_in_context.id',
                'gegevens_objecten_in_context.rdf_uri',
                'cases.id as case_id',
            ]);
    }

    private function accessibleGoicsForUser(int $userId): Builder
    {
        return DB::table('gegevens_objecten_in_context')
            ->join('dossiers', 'dossiers.id', '=', 'gegevens_objecten_in_context.dossier_id')
            ->join('cases', 'cases.id', '=', 'dossiers.case_id')
            ->join('case_soorten', 'case_soorten.id', '=', 'cases.case_soort_id')
            ->where('cases.user_id', $userId)
            ->whereIn('case_soorten.rechtsgrond_id', $this->allowedRechtsgrondIdsForUser($userId));
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
