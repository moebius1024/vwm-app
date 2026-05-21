<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $uri = 'http://ontologie.politie.nl/def/vwm#ContactGegevens';

    public function up(): void
    {
        $this->upsertTransactieSjabloon(1, 'RUDA', 99);
        $this->upsertTransactieSjabloon(2, 'RUDA', 99);
        $this->upsertTransactieSjabloon(3, 'RUDA', 99);
        $this->upsertTransactieSjabloon(4, 'RUDA', 99);
        $this->upsertTransactieSjabloon(5, 'R', 99);

        $exists = DB::table('toestands_beschrijvingen')
            ->where('beschrijving', $this->uri)
            ->exists();

        if (! $exists) {
            $uuid = (string) Str::uuid();
            DB::table('toestands_beschrijvingen')->insert([
                'uuid' => $uuid,
                'rdf_uri' => "http://vwm.voorbeeld.nl/data/tb/{$uuid}",
                'beschrijving' => $this->uri,
                'toestand_data' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('transactie_soort_sjabloon')
            ->where('sjabloon_uri', $this->uri)
            ->whereIn('transactie_soort_id', [1, 2, 3, 4, 5])
            ->delete();

        DB::table('toestands_beschrijvingen')
            ->where('beschrijving', $this->uri)
            ->delete();
    }

    private function upsertTransactieSjabloon(int $transactieSoortId, string $crudFlags, int $volgorde): void
    {
        $transactieExists = DB::table('transactie_soorten')
            ->where('id', $transactieSoortId)
            ->exists();

        if (! $transactieExists) {
            return;
        }

        $existing = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieSoortId)
            ->where('sjabloon_uri', $this->uri)
            ->where('type', 'sjabloon')
            ->first(['id']);

        if ($existing) {
            DB::table('transactie_soort_sjabloon')
                ->where('id', (int) $existing->id)
                ->update([
                    'crud_flags' => $crudFlags,
                    'volgorde' => $volgorde,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('transactie_soort_sjabloon')->insert([
            'transactie_soort_id' => $transactieSoortId,
            'sjabloon_uri' => $this->uri,
            'type' => 'sjabloon',
            'crud_flags' => $crudFlags,
            'volgorde' => $volgorde,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
