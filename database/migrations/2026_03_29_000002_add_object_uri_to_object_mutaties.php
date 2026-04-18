<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('object_mutaties', function (Blueprint $table) {
            if (!Schema::hasColumn('object_mutaties', 'object_uri')) {
                $table->string('object_uri')->nullable()->after('sjabloon_uri');
            }
        });
    }

    public function down(): void
    {
        Schema::table('object_mutaties', function (Blueprint $table) {
            if (Schema::hasColumn('object_mutaties', 'object_uri')) {
                $table->dropColumn('object_uri');
            }
        });
    }
};
