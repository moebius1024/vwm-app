<?php

use App\Models\User;
use App\Services\RoleMutationWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('it writes role mutation audit records and returns role triples', function () {
    $user = User::factory()->create();
    $now = Carbon::parse('2026-06-08 10:15:00');
    $nowIso = $now->toAtomString();

    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'Test rechtsgrond',
        'code' => 'ROLE-WRITE',
        'omschrijving' => 'Test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => 'Verkeersincident',
        'code' => 'VI-ROLE-WRITE',
        'rechtsgrond_id' => $rechtsgrondId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $transactieSoortId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Registreren',
        'rdf_uri' => 'http://example.test/transactie/role-write',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $caseId = DB::table('cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_soort_id' => $caseSoortId,
        'user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $transactieId = DB::table('transacties')->insertGetId([
        'case_id' => $caseId,
        'transactie_soort_id' => $transactieSoortId,
        'user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $dossierId = DB::table('dossiers')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/dossier/'.((string) Str::uuid()),
        'case_id' => $caseId,
        'naam' => 'Dossier',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $fromGoicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/goic/person',
        'dossier_id' => $dossierId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $result = app(RoleMutationWriter::class)->writeRoleMutations(
        transactieId: $transactieId,
        roleMutationPlans: [[
            'role_type' => 'http://example.test/RolType_Eigenaar',
            'role_tb_class' => 'http://example.test/PersoonVoertuigRol',
            'from_goic_id' => $fromGoicId,
            'from_goic_uri' => 'http://example.test/goic/person',
            'to_goic_uri' => 'http://example.test/goic/vehicle',
            'van_property' => 'http://example.test/heeftPersoon',
            'naar_property' => 'http://example.test/heeftVoertuig',
        ]],
        now: $now,
        nowIso: $nowIso,
        vwmNamespace: 'http://ontologie.politie.nl/def/vwm#',
    );

    expect($result['mutation_count'])->toBe(1)
        ->and($result['triples'])->toContain(' a <http://example.test/PersoonVoertuigRol> .')
        ->and($result['triples'])->toContain('<http://example.test/heeftPersoon> <http://example.test/goic/person>')
        ->and($result['triples'])->toContain('<http://example.test/heeftVoertuig> <http://example.test/goic/vehicle>')
        ->and($result['triples'])->toContain('<http://ontologie.politie.nl/def/vwm#rolType> <http://example.test/RolType_Eigenaar>')
        ->and($result['triples'])->toContain('<http://ontologie.politie.nl/def/vwm#geregistreerdOp> "'.$nowIso.'"^^<http://www.w3.org/2001/XMLSchema#dateTime>');

    $toestand = DB::table('toestands_beschrijvingen')
        ->where('beschrijving', 'http://example.test/PersoonVoertuigRol')
        ->first();
    expect($toestand)->not->toBeNull()
        ->and($toestand->beschrijving)->toBe('http://example.test/PersoonVoertuigRol');

    $mutatie = DB::table('object_mutaties')
        ->where('transactie_id', $transactieId)
        ->where('sjabloon_uri', 'http://example.test/PersoonVoertuigRol')
        ->first();
    expect($mutatie)->not->toBeNull()
        ->and((int) $mutatie->transactie_id)->toBe($transactieId)
        ->and((int) $mutatie->gegevens_object_in_context_id)->toBe($fromGoicId)
        ->and((int) $mutatie->geproduceerde_toestand_id)->toBe((int) $toestand->id)
        ->and($mutatie->rdf_uri)->toBeString()
        ->and($result['triples'])->toContain('<'.$mutatie->rdf_uri.'> a <http://ontologie.politie.nl/def/vwm#ObjectMutatie>')
        ->and(json_decode($mutatie->data, true))->toBe([
            'van' => 'http://example.test/goic/person',
            'naar' => 'http://example.test/goic/vehicle',
            'rolType' => 'http://example.test/RolType_Eigenaar',
        ]);
});
