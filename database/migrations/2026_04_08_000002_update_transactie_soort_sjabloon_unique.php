<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactie_soort_sjabloon', function (Blueprint $table) {
            $table->dropUnique(['transactie_soort_id', 'sjabloon_uri']);
            $table->unique(['transactie_soort_id', 'sjabloon_uri', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('transactie_soort_sjabloon', function (Blueprint $table) {
            $table->dropUnique(['transactie_soort_id', 'sjabloon_uri', 'type']);
            $table->unique(['transactie_soort_id', 'sjabloon_uri']);
        });
    }
};
