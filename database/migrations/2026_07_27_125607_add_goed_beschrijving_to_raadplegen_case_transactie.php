<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $transactieId = 5;

        if (! DB::table('transactie_soorten')->where('id', $transactieId)->exists()) {
            return;
        }

        $sjabloonUri = 'http://ontologie.politie.nl/def/vwm#GoedBeschrijving';

        $existing = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieId)
            ->where('sjabloon_uri', $sjabloonUri)
            ->where('type', 'sjabloon')
            ->first(['id']);

        if ($existing) {
            DB::table('transactie_soort_sjabloon')
                ->where('id', $existing->id)
                ->update([
                    'crud_flags' => 'R',
                    'volgorde' => 5,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('transactie_soort_sjabloon')->insert([
            'transactie_soort_id' => $transactieId,
            'sjabloon_uri' => $sjabloonUri,
            'type' => 'sjabloon',
            'volgorde' => 5,
            'crud_flags' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $transactieId = 5;

        if (! DB::table('transactie_soorten')->where('id', $transactieId)->exists()) {
            return;
        }

        DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieId)
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#GoedBeschrijving')
            ->where('type', 'sjabloon')
            ->delete();
    }
};
