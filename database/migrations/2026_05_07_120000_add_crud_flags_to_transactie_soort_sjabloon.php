<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactie_soort_sjabloon', function (Blueprint $table) {
            if (! Schema::hasColumn('transactie_soort_sjabloon', 'crud_flags')) {
                $table->string('crud_flags', 8)->default('CRUD')->after('type');
            }
        });

        DB::table('transactie_soort_sjabloon')
            ->where('type', 'rol')
            ->update(['crud_flags' => 'CRD', 'updated_at' => now()]);

        DB::table('transactie_soort_sjabloon')
            ->where('type', 'sjabloon')
            ->update(['crud_flags' => 'CRUD', 'updated_at' => now()]);

        DB::table('transactie_soort_sjabloon')
            ->where('type', 'sjabloon')
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#IncidentBeschrijving')
            ->update(['crud_flags' => 'CRU', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('transactie_soort_sjabloon', function (Blueprint $table) {
            if (Schema::hasColumn('transactie_soort_sjabloon', 'crud_flags')) {
                $table->dropColumn('crud_flags');
            }
        });
    }
};

