<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactie_soorten')->update([
            'max_target_class_uri' => null,
            'max_target_class_count' => null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('transactie_soorten')
            ->where('naam', 'VerkeersIncident')
            ->update([
                'max_target_class_uri' => 'http://ontologie.politie.nl/def/dpm#Incident',
                'max_target_class_count' => 1,
                'updated_at' => now(),
            ]);
    }
};
