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

        $sjabloonUri = 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving';

        $existing = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieId)
            ->where('sjabloon_uri', $sjabloonUri)
            ->where('type', 'sjabloon')
            ->first(['id']);

        if ($existing) {
            DB::table('transactie_soort_sjabloon')
                ->where('id', $existing->id)
                ->update([
                    'type' => 'sjabloon',
                    'volgorde' => 3,
                    'crud_flags' => 'CRUD',
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('transactie_soort_sjabloon')->insert([
                'transactie_soort_id' => $transactieId,
                'sjabloon_uri' => $sjabloonUri,
                'type' => 'sjabloon',
                'volgorde' => 3,
                'crud_flags' => 'CRUD',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieId)
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#PersoonSignalement')
            ->where('type', 'sjabloon')
            ->update([
                'volgorde' => 4,
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
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving')
            ->where('type', 'sjabloon')
            ->delete();

        DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieId)
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#PersoonSignalement')
            ->where('type', 'sjabloon')
            ->update([
                'volgorde' => 3,
                'updated_at' => now(),
            ]);
    }
};
