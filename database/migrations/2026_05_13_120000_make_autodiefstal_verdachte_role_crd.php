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

        $selectorUri = 'http://ontologie.politie.nl/def/vwm#Rol_Verdachte';

        $existing = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieId)
            ->where('sjabloon_uri', $selectorUri)
            ->where('type', 'rol')
            ->first(['id', 'volgorde']);

        $nextVolgorde = ((int) DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieId)
            ->where('type', 'rol')
            ->max('volgorde')) + 1;

        if ($existing) {
            DB::table('transactie_soort_sjabloon')
                ->where('id', $existing->id)
                ->update([
                    'type' => 'rol',
                    'crud_flags' => 'CRD',
                    'volgorde' => $existing->volgorde ?? $nextVolgorde,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('transactie_soort_sjabloon')->insert([
            'transactie_soort_id' => $transactieId,
            'sjabloon_uri' => $selectorUri,
            'type' => 'rol',
            'volgorde' => $nextVolgorde,
            'crud_flags' => 'CRD',
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
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#Rol_Verdachte')
            ->where('type', 'rol')
            ->update([
                'crud_flags' => 'R',
                'updated_at' => now(),
            ]);
    }
};
