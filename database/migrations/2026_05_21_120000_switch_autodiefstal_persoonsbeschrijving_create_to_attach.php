<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateFlags(remove: 'C', add: 'A');
    }

    public function down(): void
    {
        $this->updateFlags(remove: 'A', add: 'C');
    }

    private function updateFlags(string $remove, string $add): void
    {
        $rows = DB::table('transactie_soort_sjabloon as tss')
            ->join('transactie_soorten as ts', 'ts.id', '=', 'tss.transactie_soort_id')
            ->where('ts.naam', 'Autodiefstal')
            ->where('tss.type', 'sjabloon')
            ->where('tss.sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving')
            ->select('tss.id', 'tss.crud_flags')
            ->get();

        foreach ($rows as $row) {
            $flags = strtoupper((string) ($row->crud_flags ?? ''));
            $letters = array_values(array_unique(str_split($flags)));
            $letters = array_values(array_filter($letters, fn (string $ch) => $ch !== strtoupper($remove)));

            if (! in_array(strtoupper($add), $letters, true)) {
                $letters[] = strtoupper($add);
            }

            $ordered = '';
            foreach (['C', 'R', 'U', 'D', 'A'] as $token) {
                if (in_array($token, $letters, true)) {
                    $ordered .= $token;
                }
            }

            DB::table('transactie_soort_sjabloon')
                ->where('id', (int) $row->id)
                ->update([
                    'crud_flags' => $ordered !== '' ? $ordered : strtoupper($add),
                    'updated_at' => now(),
                ]);
        }
    }
};
