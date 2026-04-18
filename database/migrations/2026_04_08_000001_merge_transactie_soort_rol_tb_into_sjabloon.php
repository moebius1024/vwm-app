<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('transactie_soort_sjabloon', 'type')) {
            Schema::table('transactie_soort_sjabloon', function (Blueprint $table) {
                $table->string('type')->default('sjabloon')->after('sjabloon_uri');
            });
        }

        DB::table('transactie_soort_sjabloon')
            ->whereNull('type')
            ->update(['type' => 'sjabloon']);

        if (Schema::hasTable('transactie_soort_rol_tb')) {
            $rows = DB::table('transactie_soort_rol_tb')->get();
            foreach ($rows as $row) {
                $exists = DB::table('transactie_soort_sjabloon')
                    ->where('transactie_soort_id', $row->transactie_soort_id)
                    ->where('sjabloon_uri', $row->rol_tb_class)
                    ->where('type', 'rol')
                    ->exists();
                if (!$exists) {
                    DB::table('transactie_soort_sjabloon')->insert([
                        'transactie_soort_id' => $row->transactie_soort_id,
                        'sjabloon_uri' => $row->rol_tb_class,
                        'type' => 'rol',
                        'volgorde' => $row->volgorde,
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => $row->updated_at ?? now(),
                    ]);
                }
            }

            Schema::dropIfExists('transactie_soort_rol_tb');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('transactie_soort_rol_tb')) {
            Schema::create('transactie_soort_rol_tb', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transactie_soort_id')->constrained('transactie_soorten')->onDelete('cascade');
                $table->string('rol_tb_class');
                $table->integer('volgorde')->nullable();
                $table->timestamps();

                $table->unique(['transactie_soort_id', 'rol_tb_class']);
            });
        }

        $rows = DB::table('transactie_soort_sjabloon')
            ->where('type', 'rol')
            ->get();

        foreach ($rows as $row) {
            $exists = DB::table('transactie_soort_rol_tb')
                ->where('transactie_soort_id', $row->transactie_soort_id)
                ->where('rol_tb_class', $row->sjabloon_uri)
                ->exists();
            if (!$exists) {
                DB::table('transactie_soort_rol_tb')->insert([
                    'transactie_soort_id' => $row->transactie_soort_id,
                    'rol_tb_class' => $row->sjabloon_uri,
                    'volgorde' => $row->volgorde,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }
        }

        if (Schema::hasColumn('transactie_soort_sjabloon', 'type')) {
            Schema::table('transactie_soort_sjabloon', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
    }
};
