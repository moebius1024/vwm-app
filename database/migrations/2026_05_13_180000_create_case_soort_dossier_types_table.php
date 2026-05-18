<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_soort_dossier_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_soort_id')->constrained('case_soorten')->cascadeOnDelete();
            $table->string('rdf_type_uri');
            $table->unsignedInteger('volgorde')->default(1);
            $table->timestamps();

            $table->unique(['case_soort_id', 'rdf_type_uri'], 'csdt_case_soort_rdf_type_unique');
            $table->index(['case_soort_id', 'volgorde'], 'csdt_case_soort_volgorde_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_soort_dossier_types');
    }
};
