<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactie_soort_sjabloon', function (Blueprint $table) {
            if (!Schema::hasColumn('transactie_soort_sjabloon', 'volgorde')) {
                $table->unsignedInteger('volgorde')->default(1)->after('sjabloon_uri');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactie_soort_sjabloon', function (Blueprint $table) {
            if (Schema::hasColumn('transactie_soort_sjabloon', 'volgorde')) {
                $table->dropColumn('volgorde');
            }
        });
    }
};
