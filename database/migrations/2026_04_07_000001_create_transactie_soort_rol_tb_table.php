<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactie_soort_rol_tb', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transactie_soort_id')->constrained('transactie_soorten')->onDelete('cascade');
            $table->string('rol_tb_class');
            $table->integer('volgorde')->nullable();
            $table->timestamps();

            $table->unique(['transactie_soort_id', 'rol_tb_class']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactie_soort_rol_tb');
    }
};
