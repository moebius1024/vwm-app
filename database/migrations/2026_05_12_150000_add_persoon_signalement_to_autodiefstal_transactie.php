<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $transactieId = DB::table('transactie_soorten')
            ->where('naam', 'Autodiefstal')
            ->value('id');

        if (! $transactieId) {
            return;
        }

        $sjabloonUri = 'http://ontologie.politie.nl/def/vwm#PersoonSignalement';

        $existing = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieId)
            ->where('sjabloon_uri', $sjabloonUri)
            ->first();

        $nextVolgorde = (int) DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieId)
            ->where('type', 'sjabloon')
            ->max('volgorde') + 1;

        if ($existing) {
            DB::table('transactie_soort_sjabloon')
                ->where('id', $existing->id)
                ->update([
                    'type' => 'sjabloon',
                    'crud_flags' => 'CRUD',
                    'volgorde' => $existing->volgorde ?? $nextVolgorde,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('transactie_soort_sjabloon')->insert([
            'transactie_soort_id' => $transactieId,
            'sjabloon_uri' => $sjabloonUri,
            'type' => 'sjabloon',
            'volgorde' => $nextVolgorde,
            'crud_flags' => 'CRUD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $transactieId = DB::table('transactie_soorten')
            ->where('naam', 'Autodiefstal')
            ->value('id');

        if (! $transactieId) {
            return;
        }

        DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieId)
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#PersoonSignalement')
            ->delete();
    }
};
