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

        // PersoonsBeschrijving moet dezelfde CRUD hebben als VerkeersIncident.
        DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieId)
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving')
            ->where('type', 'sjabloon')
            ->update([
                'crud_flags' => 'CRUD',
                'updated_at' => now(),
            ]);

        $rollen = [
            ['uri' => 'http://ontologie.politie.nl/def/vwm#Rol_Eigenaar', 'volgorde' => 1],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#Rol_Getuige', 'volgorde' => 2],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#Rol_Verdachte', 'volgorde' => 3],
        ];

        foreach ($rollen as $rol) {
            $existing = DB::table('transactie_soort_sjabloon')
                ->where('transactie_soort_id', $transactieId)
                ->where('sjabloon_uri', $rol['uri'])
                ->where('type', 'rol')
                ->first(['id']);

            if ($existing) {
                DB::table('transactie_soort_sjabloon')
                    ->where('id', $existing->id)
                    ->update([
                        'type' => 'rol',
                        'volgorde' => $rol['volgorde'],
                        'crud_flags' => 'CRD',
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('transactie_soort_sjabloon')->insert([
                'transactie_soort_id' => $transactieId,
                'sjabloon_uri' => $rol['uri'],
                'type' => 'rol',
                'volgorde' => $rol['volgorde'],
                'crud_flags' => 'CRD',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
            ->whereIn('sjabloon_uri', [
                'http://ontologie.politie.nl/def/vwm#Rol_Eigenaar',
                'http://ontologie.politie.nl/def/vwm#Rol_Getuige',
            ])
            ->where('type', 'rol')
            ->delete();

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
