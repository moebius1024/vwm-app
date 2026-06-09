<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('case_soorten', function (Blueprint $table) {
            $table->boolean('vereist_incident_in_dossier')->default(false);
        });

        Schema::table('dossiers', function (Blueprint $table) {
            $table->boolean('vereist_incident_in_dossier')->default(false);
        });

        DB::table('case_soorten')
            ->whereIn('code', ['VI-001', 'AD-001', 'WD-001'])
            ->update([
                'vereist_incident_in_dossier' => true,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropColumn('vereist_incident_in_dossier');
        });

        Schema::table('case_soorten', function (Blueprint $table) {
            $table->dropColumn('vereist_incident_in_dossier');
        });
    }
};
