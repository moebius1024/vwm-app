<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_soorten', function (Blueprint $table) {
            $table->foreignId('rechtsgrond_id')
                ->nullable()
                ->after('code')
                ->constrained('rechtsgronden')
                ->nullOnDelete();
        });

        $pairs = DB::table('rechtsgronden')
            ->select('id', 'case_soort_id')
            ->whereNotNull('case_soort_id')
            ->get();

        foreach ($pairs as $pair) {
            DB::table('case_soorten')
                ->where('id', (int) $pair->case_soort_id)
                ->update(['rechtsgrond_id' => (int) $pair->id]);
        }

        Schema::table('rechtsgronden', function (Blueprint $table) {
            $table->dropConstrainedForeignId('case_soort_id');
        });
    }

    public function down(): void
    {
        Schema::table('rechtsgronden', function (Blueprint $table) {
            $table->foreignId('case_soort_id')
                ->nullable()
                ->after('id')
                ->constrained('case_soorten')
                ->nullOnDelete();
        });

        $pairs = DB::table('case_soorten')
            ->select('id', 'rechtsgrond_id')
            ->whereNotNull('rechtsgrond_id')
            ->get();

        foreach ($pairs as $pair) {
            DB::table('rechtsgronden')
                ->where('id', (int) $pair->rechtsgrond_id)
                ->update(['case_soort_id' => (int) $pair->id]);
        }

        Schema::table('case_soorten', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rechtsgrond_id');
        });
    }
};

