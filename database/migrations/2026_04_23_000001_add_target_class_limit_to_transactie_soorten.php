<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactie_soorten', function (Blueprint $table) {
            $table->string('max_target_class_uri')->nullable();
            $table->unsignedInteger('max_target_class_count')->nullable();
        });

        DB::table('transactie_soorten')
            ->whereRaw('LOWER(TRIM(naam)) = ?', ['verkeersincident'])
            ->update([
                'max_target_class_uri' => 'http://ontologie.politie.nl/def/dpm#Incident',
                'max_target_class_count' => 1,
            ]);
    }

    public function down(): void
    {
        Schema::table('transactie_soorten', function (Blueprint $table) {
            $table->dropColumn(['max_target_class_uri', 'max_target_class_count']);
        });
    }
};
