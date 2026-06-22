<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_object_associations', function (Blueprint $table) {
            if (! Schema::hasColumn('data_object_associations', 'invalidated_at')) {
                $table->timestamp('invalidated_at')->nullable()->after('produced_at')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('data_object_associations', function (Blueprint $table) {
            if (Schema::hasColumn('data_object_associations', 'invalidated_at')) {
                $table->dropColumn('invalidated_at');
            }
        });
    }
};
