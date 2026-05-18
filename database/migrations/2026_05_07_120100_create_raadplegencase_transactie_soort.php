<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $raadplegenId = DB::table('transactie_soorten')
            ->where('naam', 'RaadplegenCase')
            ->value('id');

        if (! $raadplegenId) {
            $raadplegenId = DB::table('transactie_soorten')->insertGetId([
                'naam' => 'RaadplegenCase',
                'rdf_uri' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $links = DB::table('transactie_soort_sjabloon')
            ->whereIn('type', ['sjabloon', 'rol'])
            ->select('sjabloon_uri', 'type', 'volgorde')
            ->distinct()
            ->get();

        foreach ($links as $link) {
            $exists = DB::table('transactie_soort_sjabloon')
                ->where('transactie_soort_id', $raadplegenId)
                ->where('sjabloon_uri', $link->sjabloon_uri)
                ->where('type', $link->type)
                ->exists();

            if ($exists) {
                DB::table('transactie_soort_sjabloon')
                    ->where('transactie_soort_id', $raadplegenId)
                    ->where('sjabloon_uri', $link->sjabloon_uri)
                    ->where('type', $link->type)
                    ->update([
                        'crud_flags' => 'R',
                        'volgorde' => $link->volgorde ?? 1,
                        'updated_at' => $now,
                    ]);
                continue;
            }

            DB::table('transactie_soort_sjabloon')->insert([
                'transactie_soort_id' => $raadplegenId,
                'sjabloon_uri' => $link->sjabloon_uri,
                'type' => $link->type,
                'crud_flags' => 'R',
                'volgorde' => $link->volgorde ?? 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $raadplegenId = DB::table('transactie_soorten')
            ->where('naam', 'RaadplegenCase')
            ->value('id');

        if (! $raadplegenId) {
            return;
        }

        DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $raadplegenId)
            ->delete();

        DB::table('transactie_soorten')
            ->where('id', $raadplegenId)
            ->delete();
    }
};

