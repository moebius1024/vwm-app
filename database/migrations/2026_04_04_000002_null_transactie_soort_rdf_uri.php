<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // rdf_uri is NOT NULL in huidige schema, dus leegmaken naar lege string
        DB::table('transactie_soorten')->update(['rdf_uri' => '']);
    }

    public function down(): void
    {
        // Geen herstel: oorspronkelijke waarden onbekend
    }
};
