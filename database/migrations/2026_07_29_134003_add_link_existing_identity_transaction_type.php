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
        DB::table('transactie_soorten')->updateOrInsert(
            ['rdf_uri' => 'http://ontologie.politie.nl/def/vwm#KoppelAanBestaandeIdentiteit'],
            [
                'naam' => 'Koppel aan bestaande identiteit',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('transactie_soorten')
            ->where('rdf_uri', 'http://ontologie.politie.nl/def/vwm#KoppelAanBestaandeIdentiteit')
            ->delete();
    }
};
