<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $transactieSoortId = DB::table('transactie_soorten')
            ->where('naam', 'Winkeldiefstal')
            ->value('id');

        if (! $transactieSoortId) {
            return;
        }

        $rollen = [
            ['uri' => 'http://ontologie.politie.nl/def/vwm#Rol_Aangever', 'volgorde' => 1],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#Rol_Verdachte', 'volgorde' => 2],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#Rol_Getuige', 'volgorde' => 3],
        ];

        foreach ($rollen as $rol) {
            $existing = DB::table('transactie_soort_sjabloon')
                ->where('transactie_soort_id', $transactieSoortId)
                ->where('sjabloon_uri', $rol['uri'])
                ->where('type', 'rol')
                ->first(['id']);

            if ($existing) {
                DB::table('transactie_soort_sjabloon')
                    ->where('id', $existing->id)
                    ->update([
                        'volgorde' => $rol['volgorde'],
                        'crud_flags' => 'CRD',
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('transactie_soort_sjabloon')->insert([
                'transactie_soort_id' => $transactieSoortId,
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
        $transactieSoortId = DB::table('transactie_soorten')
            ->where('naam', 'Winkeldiefstal')
            ->value('id');

        if (! $transactieSoortId) {
            return;
        }

        DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieSoortId)
            ->where('type', 'rol')
            ->whereIn('sjabloon_uri', [
                'http://ontologie.politie.nl/def/vwm#Rol_Aangever',
                'http://ontologie.politie.nl/def/vwm#Rol_Verdachte',
                'http://ontologie.politie.nl/def/vwm#Rol_Getuige',
            ])
            ->delete();
    }
};
