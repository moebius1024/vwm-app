<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $transactieSoortId = DB::table('transactie_soorten')->where('naam', 'Winkeldiefstal')->value('id');
        if (! $transactieSoortId) {
            return;
        }
        DB::table('transactie_soort_sjabloon')->updateOrInsert(
            ['transactie_soort_id' => $transactieSoortId, 'sjabloon_uri' => 'http://ontologie.politie.nl/def/vwm#Rol_Functie', 'type' => 'rol'],
            ['volgorde' => 4, 'crud_flags' => 'CRD', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $transactieSoortId = DB::table('transactie_soorten')->where('naam', 'Winkeldiefstal')->value('id');
        if (! $transactieSoortId) {
            return;
        }
        DB::table('transactie_soort_sjabloon')->where('transactie_soort_id', $transactieSoortId)->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#Rol_Functie')->where('type', 'rol')->delete();
    }
};
