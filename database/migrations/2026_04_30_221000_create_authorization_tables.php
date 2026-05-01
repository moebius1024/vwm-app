<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('naam');
            $table->string('code')->unique();
            $table->timestamps();
        });

        Schema::create('medewerkers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('naam');
            $table->string('medewerker_nummer')->unique();
            $table->timestamps();
        });

        Schema::create('personen', function (Blueprint $table) {
            $table->id();
            $table->string('naam');
            $table->string('identifier')->unique();
            $table->timestamps();
        });

        Schema::create('functie_soorten', function (Blueprint $table) {
            $table->id();
            $table->string('naam');
            $table->string('code')->unique();
            $table->timestamps();
        });

        Schema::create('rechtsgronden', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_soort_id')->constrained('case_soorten')->cascadeOnDelete();
            $table->string('naam');
            $table->string('code')->unique();
            $table->text('omschrijving')->nullable();
            $table->timestamps();
        });

        Schema::create('functies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medewerker_id')->constrained('medewerkers')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('functie_soort_id')->constrained('functie_soorten')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['medewerker_id', 'team_id', 'functie_soort_id'], 'functies_unique_assignment');
        });

        Schema::create('autorisatie_rollen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('functie_soort_id')->constrained('functie_soorten')->cascadeOnDelete();
            $table->foreignId('rechtsgrond_id')->constrained('rechtsgronden')->cascadeOnDelete();
            $table->string('naam');
            $table->string('code')->unique();
            $table->timestamps();

            $table->unique(['functie_soort_id', 'rechtsgrond_id'], 'autorisatie_rol_uniek_per_functie_en_rechtsgrond');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorisatie_rollen');
        Schema::dropIfExists('functies');
        Schema::dropIfExists('rechtsgronden');
        Schema::dropIfExists('functie_soorten');
        Schema::dropIfExists('personen');
        Schema::dropIfExists('medewerkers');
        Schema::dropIfExists('teams');
    }
};

