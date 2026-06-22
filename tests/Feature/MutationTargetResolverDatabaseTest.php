<?php

use App\Models\User;
use App\Services\MutationTargetResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('it resolves mutation target metadata within the requested dossier', function () {
    $user = User::factory()->create();
    $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
        'naam' => 'Test rechtsgrond',
        'code' => 'MUTATION-TARGET',
        'omschrijving' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $caseSoortId = DB::table('case_soorten')->insertGetId([
        'naam' => 'Test case soort',
        'code' => 'MT-001',
        'rechtsgrond_id' => $rechtsgrondId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $caseId = DB::table('cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_soort_id' => $caseSoortId,
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $dossierId = DB::table('dossiers')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/dossier/'.((string) Str::uuid()),
        'case_id' => $caseId,
        'naam' => 'Test dossier',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $otherDossierId = DB::table('dossiers')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/dossier/'.((string) Str::uuid()),
        'case_id' => $caseId,
        'naam' => 'Ander dossier',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $goicId = DB::table('gegevens_objecten_in_context')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/goic/'.((string) Str::uuid()),
        'dossier_id' => $dossierId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $tbId = DB::table('toestands_beschrijvingen')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'rdf_uri' => 'http://example.test/tb/'.((string) Str::uuid()),
        'beschrijving' => 'http://example.test/PersoonBeschrijving',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transactieSoortId = DB::table('transactie_soorten')->insertGetId([
        'naam' => 'Registreren',
        'rdf_uri' => 'http://example.test/transactie',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transactieId = DB::table('transacties')->insertGetId([
        'case_id' => $caseId,
        'transactie_soort_id' => $transactieSoortId,
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $mutationId = DB::table('object_mutaties')->insertGetId([
        'transactie_id' => $transactieId,
        'sjabloon_uri' => 'http://example.test/PersoonBeschrijving',
        'object_uri' => 'http://example.test/tb/object',
        'gegevens_object_in_context_id' => $goicId,
        'geproduceerde_toestand_id' => $tbId,
        'datum_tijd' => now(),
        'data' => json_encode(['test' => true]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $target = [
        'goic_id' => $goicId,
        'mutatie_id' => $mutationId,
    ];
    $resolver = app(MutationTargetResolver::class);

    $meta = $resolver->resolveMutationTargetMeta($target, $dossierId);

    expect($meta?->mutatie_id)->toBe($mutationId)
        ->and($meta?->goic_id)->toBe($goicId)
        ->and($meta?->tb_uri)->not->toBeEmpty()
        ->and($resolver->resolveMutationTargetMeta($target, $otherDossierId))->toBeNull();
});
