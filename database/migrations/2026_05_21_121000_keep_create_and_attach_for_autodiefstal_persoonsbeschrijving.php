<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->setFlags('CRUDA');
    }

    public function down(): void
    {
        $this->setFlags('RUDA');
    }

    private function setFlags(string $flags): void
    {
        DB::table('transactie_soort_sjabloon as tss')
            ->join('transactie_soorten as ts', 'ts.id', '=', 'tss.transactie_soort_id')
            ->where('ts.naam', 'Autodiefstal')
            ->where('tss.type', 'sjabloon')
            ->where('tss.sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving')
            ->update([
                'tss.crud_flags' => $flags,
                'tss.updated_at' => now(),
            ]);
    }
};
