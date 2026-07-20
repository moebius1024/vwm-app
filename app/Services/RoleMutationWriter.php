<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleMutationWriter
{
    /**
     * @param  array<int,array{role_type:?string,role_tb_class:string,from_goic_id:int|string|null,from_goic_uri:string,to_goic_uri:string,van_property:string,naar_property:string}>  $roleMutationPlans
     * @return array{triples:string,mutation_count:int}
     */
    public function writeRoleMutations(
        int $transactieId,
        array $roleMutationPlans,
        DateTimeInterface $now,
        string $nowIso,
        string $vwmNamespace
    ): array {
        $triples = '';
        $mutationCount = 0;

        foreach ($roleMutationPlans as $rolePlan) {
            $roleTbUuid = (string) Str::uuid();
            $roleTbUri = 'http://vwm.voorbeeld.nl/data/tb/'.$roleTbUuid;
            $roleMutatieUuid = (string) Str::uuid();
            $roleMutatieUri = 'http://vwm.voorbeeld.nl/data/mutatie/'.$roleMutatieUuid;

            $roleData = [
                'van' => $rolePlan['from_goic_uri'],
                'naar' => $rolePlan['to_goic_uri'],
            ];
            if (! empty($rolePlan['role_type'])) {
                $roleData['rolType'] = $rolePlan['role_type'];
            }

            $roleTbId = DB::table('toestands_beschrijvingen')->insertGetId([
                'uuid' => $roleTbUuid,
                'rdf_uri' => $roleTbUri,
                'beschrijving' => $rolePlan['role_tb_class'],
                'toestand_data' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('object_mutaties')->insert([
                'transactie_id' => $transactieId,
                'sjabloon_uri' => $rolePlan['role_tb_class'],
                'object_uri' => $roleTbUri,
                'rdf_uri' => $roleMutatieUri,
                'gegevens_object_in_context_id' => $rolePlan['from_goic_id'],
                'geproduceerde_toestand_id' => $roleTbId,
                'datum_tijd' => $now,
                'data' => json_encode($roleData),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $triples .= "<{$roleTbUri}> a <{$rolePlan['role_tb_class']}> . \n";
            $triples .= "<{$roleTbUri}> <{$rolePlan['van_property']}> <{$rolePlan['from_goic_uri']}> . \n";
            $triples .= "<{$roleTbUri}> <{$rolePlan['naar_property']}> <{$rolePlan['to_goic_uri']}> . \n";
            if (! empty($rolePlan['role_type'])) {
                $triples .= "<{$roleTbUri}> <{$vwmNamespace}rolType> <{$rolePlan['role_type']}> . \n";
            }
            $triples .= "<{$roleTbUri}> <{$vwmNamespace}geregistreerdOp> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> . \n";

            $triples .= "<{$roleMutatieUri}> a <{$vwmNamespace}ObjectMutatie> . \n";
            $triples .= "<{$roleMutatieUri}> <{$vwmNamespace}heeftBetrekkingOp> <{$rolePlan['from_goic_uri']}> . \n";
            $triples .= "<{$roleMutatieUri}> <{$vwmNamespace}produceert> <{$roleTbUri}> . \n";
            $triples .= "<{$roleMutatieUri}> <{$vwmNamespace}datumTijd> \"{$nowIso}\"^^<http://www.w3.org/2001/XMLSchema#dateTime> . \n";

            $mutationCount++;
        }

        return [
            'triples' => $triples,
            'mutation_count' => $mutationCount,
        ];
    }
}
