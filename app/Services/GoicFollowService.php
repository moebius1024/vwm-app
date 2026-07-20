<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoicFollowService
{
    public function __construct(
        private readonly GraphService $graphService,
    ) {}

    /**
     * @return array{goic_id:int,goic_uri:string,association_uri:string,target_class:string}
     */
    public function follow(
        object $targetCase,
        object $targetDossier,
        int $transactieSoortId,
        string $bronGoicUri,
        string $goUri,
        string $sourceTargetClass,
        int $userId
    ): array {
        $vwm = 'http://ontologie.politie.nl/def/vwm#';
        $dpm = 'http://ontologie.politie.nl/def/dpm#';
        $now = now();
        $nowIso = $now->toAtomString();

        return DB::transaction(function () use ($targetCase, $targetDossier, $transactieSoortId, $bronGoicUri, $goUri, $sourceTargetClass, $vwm, $dpm, $now, $nowIso, $userId): array {
            $transactieId = DB::table('transacties')->insertGetId([
                'case_id' => (int) $targetCase->id,
                'transactie_soort_id' => $transactieSoortId,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $newGoicUuid = (string) Str::uuid();
            $newGoicUri = "http://vwm.voorbeeld.nl/data/goic/{$newGoicUuid}";
            $associationUri = 'http://vwm.voorbeeld.nl/data/association/'.((string) Str::uuid());
            $goicMutatieUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.((string) Str::uuid());
            $associationMutatieUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.((string) Str::uuid());

            $goicId = DB::table('gegevens_objecten_in_context')->insertGetId([
                'uuid' => $newGoicUuid,
                'rdf_uri' => $newGoicUri,
                'dossier_id' => (int) $targetDossier->id,
                'context_data' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('object_mutaties')->insert([
                'transactie_id' => $transactieId,
                'sjabloon_uri' => "{$vwm}GegevensObjectInContext",
                'object_uri' => $newGoicUri,
                'rdf_uri' => $goicMutatieUri,
                'gegevens_object_in_context_id' => $goicId,
                'geproduceerde_toestand_id' => null,
                'datum_tijd' => $now,
                'data' => json_encode([
                    'actie' => 'volg_goic',
                    'bronGoic' => $bronGoicUri,
                    'goicUri' => $newGoicUri,
                    'doelClass' => $sourceTargetClass,
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $associationMutatieId = DB::table('object_mutaties')->insertGetId([
                'transactie_id' => $transactieId,
                'sjabloon_uri' => "{$dpm}DataObjectAssociation",
                'object_uri' => $associationUri,
                'rdf_uri' => $associationMutatieUri,
                'gegevens_object_in_context_id' => $goicId,
                'geproduceerde_toestand_id' => null,
                'datum_tijd' => $now,
                'data' => json_encode([
                    'ownedObject' => $newGoicUri,
                    'targetObject' => $bronGoicUri,
                    'producedAtTime' => $nowIso,
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('data_object_associations')->insert([
                'uuid' => (string) Str::uuid(),
                'rdf_uri' => $associationUri,
                'object_mutatie_id' => $associationMutatieId,
                'owned_goic_uri' => $newGoicUri,
                'target_goic_uri' => $bronGoicUri,
                'produced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $triples = '';
            $triples .= "<{$newGoicUri}> a <{$vwm}GegevensObjectInContext> .\n";
            $triples .= "<{$newGoicUri}> <{$vwm}beschrijftGO> <{$goUri}> .\n";
            $triples .= "<{$newGoicUri}> <{$vwm}heeftDoelClass> <{$sourceTargetClass}> .\n";
            $triples .= "<{$newGoicUri}> <{$vwm}hoortBijDossier> <{$targetDossier->rdf_uri}> .\n";

            $triples .= "<{$associationUri}> a <{$dpm}DataObjectAssociation> .\n";
            $triples .= "<{$associationUri}> <{$dpm}ownedObject> <{$newGoicUri}> .\n";
            $triples .= "<{$associationUri}> <{$dpm}targetObject> <{$bronGoicUri}> .\n";
            $triples .= "<{$associationUri}> <{$dpm}producedAtTime> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .\n";

            $triples .= "<{$goicMutatieUri}> a <{$vwm}ObjectMutatie> .\n";
            $triples .= "<{$goicMutatieUri}> <{$vwm}heeftBetrekkingOp> <{$newGoicUri}> .\n";
            $triples .= "<{$goicMutatieUri}> <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .\n";

            $triples .= "<{$associationMutatieUri}> a <{$vwm}ObjectMutatie> .\n";
            $triples .= "<{$associationMutatieUri}> <{$vwm}heeftBetrekkingOp> <{$newGoicUri}> .\n";
            $triples .= "<{$associationMutatieUri}> <{$vwm}produceert> <{$associationUri}> .\n";
            $triples .= "<{$associationMutatieUri}> <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .\n";

            $this->graphService->update("
                INSERT DATA {
                    GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                        {$triples}
                    }
                }
            ");

            return [
                'goic_id' => (int) $goicId,
                'goic_uri' => $newGoicUri,
                'association_uri' => $associationUri,
                'target_class' => $sourceTargetClass,
            ];
        });
    }

    /**
     * @return array{association_uri:string,goic_id:int,goic_uri:string,target_goic_uri:string}|null
     */
    public function unfollow(int $caseId, int $transactieSoortId, string $associationUri, int $userId): ?array
    {
        $dpm = 'http://ontologie.politie.nl/def/dpm#';
        $vwm = 'http://ontologie.politie.nl/def/vwm#';
        $now = now();
        $nowIso = $now->toAtomString();

        return DB::transaction(function () use ($associationUri, $caseId, $transactieSoortId, $userId, $dpm, $vwm, $now, $nowIso): ?array {
            $association = DB::table('data_object_associations')
                ->join('gegevens_objecten_in_context', 'gegevens_objecten_in_context.rdf_uri', '=', 'data_object_associations.owned_goic_uri')
                ->join('dossiers', 'dossiers.id', '=', 'gegevens_objecten_in_context.dossier_id')
                ->where('dossiers.case_id', $caseId)
                ->where('data_object_associations.rdf_uri', $associationUri)
                ->whereNull('data_object_associations.invalidated_at')
                ->first([
                    'data_object_associations.id as association_id',
                    'data_object_associations.rdf_uri as association_uri',
                    'data_object_associations.owned_goic_uri',
                    'data_object_associations.target_goic_uri',
                    'gegevens_objecten_in_context.id as goic_id',
                    'gegevens_objecten_in_context.rdf_uri as goic_uri',
                ]);

            if (! $association) {
                return null;
            }

            $transactieId = DB::table('transacties')->insertGetId([
                'case_id' => $caseId,
                'transactie_soort_id' => $transactieSoortId,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $mutatieUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.((string) Str::uuid());

            DB::table('object_mutaties')->insert([
                'transactie_id' => $transactieId,
                'sjabloon_uri' => "{$dpm}DataObjectAssociation",
                'object_uri' => (string) $association->association_uri,
                'rdf_uri' => $mutatieUri,
                'gegevens_object_in_context_id' => (int) $association->goic_id,
                'geproduceerde_toestand_id' => null,
                'datum_tijd' => $now,
                'data' => json_encode([
                    'actie' => 'beeindig_volg_goic',
                    'association' => (string) $association->association_uri,
                    'ownedObject' => (string) $association->owned_goic_uri,
                    'targetObject' => (string) $association->target_goic_uri,
                    'invalidatedAtTime' => $nowIso,
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('data_object_associations')
                ->where('id', (int) $association->association_id)
                ->update([
                    'invalidated_at' => $now,
                    'updated_at' => $now,
                ]);

            $triples = '';
            $triples .= "<{$association->association_uri}> <{$dpm}invalidatedAtTime> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .\n";
            $triples .= "<{$mutatieUri}> a <{$vwm}ObjectMutatie> .\n";
            $triples .= "<{$mutatieUri}> <{$vwm}heeftBetrekkingOp> <{$association->goic_uri}> .\n";
            $triples .= "<{$mutatieUri}> <{$vwm}verwijdertLogisch> <{$association->association_uri}> .\n";
            $triples .= "<{$mutatieUri}> <{$vwm}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .\n";

            $this->graphService->update("
                INSERT DATA {
                    GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                        {$triples}
                    }
                }
            ");

            return [
                'association_uri' => (string) $association->association_uri,
                'goic_id' => (int) $association->goic_id,
                'goic_uri' => (string) $association->goic_uri,
                'target_goic_uri' => (string) $association->target_goic_uri,
            ];
        });
    }

    /**
     * @return array{go_uri?:string,target_class?:string,already_followed?:array{goic_id:int,goic_uri:string}|null,reason?:string}
     */
    public function resolveSourceForFollow(int $caseId, string $bronGoicUri): array
    {
        $sourceMeta = $this->fetchSourceGoicMeta($bronGoicUri);
        if (! $sourceMeta) {
            return ['reason' => 'source_meta_missing'];
        }

        $goUri = $sourceMeta['go_uri'] ?? null;
        if (! is_string($goUri) || $goUri === '') {
            return ['reason' => 'source_go_missing'];
        }

        $sourceTargetClass = $sourceMeta['target_class'] ?? null;
        if (! is_string($sourceTargetClass) || $sourceTargetClass === '') {
            return ['reason' => 'source_target_class_missing'];
        }

        return [
            'go_uri' => $goUri,
            'target_class' => $sourceTargetClass,
            'already_followed' => $this->findExistingFollowedGoicForCase($caseId, $bronGoicUri),
        ];
    }

    /**
     * @return array{go_uri:mixed,target_class:mixed}|null
     */
    public function fetchSourceGoicMeta(string $goicUri): ?array
    {
        $goic = DB::table('gegevens_objecten_in_context')
            ->where('rdf_uri', $goicUri)
            ->first(['id']);
        if (! $goic) {
            return null;
        }

        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?go ?targetClass
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                    <{$goicUri}> vwm:beschrijftGO ?go .
                    OPTIONAL { <{$goicUri}> vwm:heeftDoelClass ?targetClass . }
                }
            }
            LIMIT 1
        ";
        $rows = $this->graphService->query($query);
        if (empty($rows[0]['go'])) {
            return null;
        }

        return [
            'go_uri' => $rows[0]['go'] ?? null,
            'target_class' => $rows[0]['targetClass'] ?? null,
        ];
    }

    /**
     * @return array{goic_id:int,goic_uri:string}|null
     */
    public function findExistingFollowedGoicForCase(int $caseId, string $bronGoicUri): ?array
    {
        $dossierIds = DB::table('dossiers')
            ->where('case_id', $caseId)
            ->pluck('id')
            ->all();

        if (empty($dossierIds)) {
            return null;
        }

        $existingLocalFollow = DB::table('data_object_associations')
            ->join('gegevens_objecten_in_context', 'gegevens_objecten_in_context.rdf_uri', '=', 'data_object_associations.owned_goic_uri')
            ->whereIn('gegevens_objecten_in_context.dossier_id', $dossierIds)
            ->where('data_object_associations.target_goic_uri', $bronGoicUri)
            ->whereNull('data_object_associations.invalidated_at')
            ->orderBy('gegevens_objecten_in_context.id')
            ->first([
                'gegevens_objecten_in_context.id as goic_id',
                'gegevens_objecten_in_context.rdf_uri as goic_uri',
            ]);

        if ($existingLocalFollow) {
            return [
                'goic_id' => (int) $existingLocalFollow->goic_id,
                'goic_uri' => (string) $existingLocalFollow->goic_uri,
            ];
        }

        $caseGoicUris = DB::table('gegevens_objecten_in_context')
            ->whereIn('dossier_id', $dossierIds)
            ->pluck('rdf_uri')
            ->all();

        if (empty($caseGoicUris)) {
            return null;
        }

        $values = implode(' ', array_map(fn ($uri) => "<{$uri}>", $caseGoicUris));
        $query = "
            PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
            SELECT ?owned
            WHERE {
                GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                    ?assoc a dpm:DataObjectAssociation ;
                           dpm:ownedObject ?owned ;
                           dpm:targetObject <{$bronGoicUri}> .
                    FILTER NOT EXISTS { ?assoc dpm:invalidatedAtTime ?invalidatedAtTime . }
                    VALUES ?owned { {$values} }
                }
            }
            LIMIT 1
        ";

        try {
            $rows = $this->graphService->query($query);
        } catch (\Throwable) {
            return null;
        }

        $ownedUri = $rows[0]['owned'] ?? null;
        if (! is_string($ownedUri) || $ownedUri === '') {
            return null;
        }

        $goic = DB::table('gegevens_objecten_in_context')
            ->where('rdf_uri', $ownedUri)
            ->first(['id', 'rdf_uri']);

        if (! $goic) {
            return null;
        }

        return [
            'goic_id' => (int) $goic->id,
            'goic_uri' => (string) $goic->rdf_uri,
        ];
    }
}
