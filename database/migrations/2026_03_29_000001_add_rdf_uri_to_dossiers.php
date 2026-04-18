<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            if (!Schema::hasColumn('dossiers', 'rdf_uri')) {
                $table->string('rdf_uri')->unique()->after('uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            if (Schema::hasColumn('dossiers', 'rdf_uri')) {
                $table->dropColumn('rdf_uri');
            }
        });
    }
};
