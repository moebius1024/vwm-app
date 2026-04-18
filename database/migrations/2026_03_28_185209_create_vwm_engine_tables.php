<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. De Blauwdruk (Configuratie)
        Schema::create('case_soorten', function (Blueprint $table) {
            $table->id();
            $table->string('naam'); // Bijv. "Algemeen Onderzoek"
            $table->string('code')->unique(); // Bijv. "AO-01"
            $table->timestamps();
        });

        Schema::create('transactie_soorten', function (Blueprint $table) {
            $table->id();
            $table->string('naam'); // Bijv. "Persoon Toevoegen"
            $table->string('rdf_uri'); // De link naar het Sjabloon in de Graph
            $table->timestamps();
        });

        Schema::create('case_soort_transactie', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_soort_id')->constrained('case_soorten')->onDelete('cascade');
            $table->foreignId('transactie_soort_id')->constrained('transactie_soorten')->onDelete('cascade');
            $table->integer('volgorde')->default(1);
            $table->timestamps();
        });

        // 2. De Executie (Data & Autorisatie)
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique(); // De "Anker" URI in de Graph
            $table->foreignId('case_soort_id')->constrained('case_soorten');
            $table->foreignId('user_id')->constrained('users'); // De eigenaar/aanmaker
            $table->timestamps();
        });

        Schema::create('dossiers', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique(); // De "Anker" URI in de Graph
            $table->string('rdf_uri')->unique(); // Volledige URI in de Graph
            $table->foreignId('case_id')->constrained('cases')->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('dossiers')->onDelete('cascade');
            $table->string('naam'); // Bijv. "Subdossier Getuigen"
            $table->timestamps();
        });

        Schema::create('transacties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases');
            $table->foreignId('transactie_soort_id')->constrained('transactie_soorten');
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('object_mutaties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transactie_id')->constrained('transacties')->onDelete('cascade');
            $table->string('sjabloon_uri'); // URI naar de Klasse/Sjabloon definitie in RDF
            $table->json('data'); // De ruwe schermdata (tijdelijke opslag/audit)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Verwijder in omgekeerde volgorde om foreign key errors te voorkomen
        Schema::dropIfExists('object_mutaties');
        Schema::dropIfExists('transacties');
        Schema::dropIfExists('dossiers');
        Schema::dropIfExists('cases');
        Schema::dropIfExists('case_soort_transactie');
        Schema::dropIfExists('transactie_soorten');
        Schema::dropIfExists('case_soorten');
    }
};
