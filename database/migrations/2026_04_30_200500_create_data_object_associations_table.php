<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('data_object_associations')) {
            return;
        }

        Schema::create('data_object_associations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('rdf_uri')->unique();
            $table->foreignId('object_mutatie_id')
                ->constrained('object_mutaties')
                ->onDelete('cascade');
            $table->string('owned_goic_uri');
            $table->string('target_goic_uri');
            $table->timestamp('produced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_object_associations');
    }
};

