<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactie_soort_sjabloon', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transactie_soort_id')->constrained('transactie_soorten')->onDelete('cascade');
            $table->string('sjabloon_uri');
            $table->timestamps();

            $table->unique(['transactie_soort_id', 'sjabloon_uri']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactie_soort_sjabloon');
    }
};
