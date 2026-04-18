<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gegevens_objecten_in_context')) {
            Schema::create('gegevens_objecten_in_context', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('rdf_uri')->unique();
                $table->foreignId('dossier_id')->constrained('dossiers')->onDelete('cascade');
                $table->json('context_data')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('toestands_beschrijvingen')) {
            Schema::create('toestands_beschrijvingen', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('rdf_uri')->unique();
                $table->string('beschrijving');
                $table->json('toestand_data')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('object_mutaties', function (Blueprint $table) {
            if (!Schema::hasColumn('object_mutaties', 'gegevens_object_in_context_id')) {
                $table->foreignId('gegevens_object_in_context_id')
                    ->nullable()
                    ->constrained('gegevens_objecten_in_context')
                    ->onDelete('set null');
            }
            if (!Schema::hasColumn('object_mutaties', 'geproduceerde_toestand_id')) {
                $table->foreignId('geproduceerde_toestand_id')
                    ->nullable()
                    ->constrained('toestands_beschrijvingen')
                    ->onDelete('set null');
            }
            if (!Schema::hasColumn('object_mutaties', 'verwijderde_toestand_id')) {
                $table->foreignId('verwijderde_toestand_id')
                    ->nullable()
                    ->constrained('toestands_beschrijvingen')
                    ->onDelete('set null');
            }
            if (!Schema::hasColumn('object_mutaties', 'datum_tijd')) {
                $table->timestamp('datum_tijd')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('object_mutaties', function (Blueprint $table) {
            if (Schema::hasColumn('object_mutaties', 'gegevens_object_in_context_id')) {
                $table->dropForeign(['gegevens_object_in_context_id']);
                $table->dropColumn('gegevens_object_in_context_id');
            }
            if (Schema::hasColumn('object_mutaties', 'geproduceerde_toestand_id')) {
                $table->dropForeign(['geproduceerde_toestand_id']);
                $table->dropColumn('geproduceerde_toestand_id');
            }
            if (Schema::hasColumn('object_mutaties', 'verwijderde_toestand_id')) {
                $table->dropForeign(['verwijderde_toestand_id']);
                $table->dropColumn('verwijderde_toestand_id');
            }
            if (Schema::hasColumn('object_mutaties', 'datum_tijd')) {
                $table->dropColumn('datum_tijd');
            }
        });

        Schema::dropIfExists('toestands_beschrijvingen');
        Schema::dropIfExists('gegevens_objecten_in_context');
    }
};
