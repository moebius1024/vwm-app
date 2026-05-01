<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS autorisatie_rol_uniek_per_functie_en_rechtsgrond');

            return;
        }

        DB::statement('ALTER TABLE autorisatie_rollen DROP INDEX autorisatie_rol_uniek_per_functie_en_rechtsgrond');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS autorisatie_rol_uniek_per_functie_en_rechtsgrond ON autorisatie_rollen(functie_soort_id, rechtsgrond_id)');

            return;
        }

        DB::statement('ALTER TABLE autorisatie_rollen ADD UNIQUE INDEX autorisatie_rol_uniek_per_functie_en_rechtsgrond (functie_soort_id, rechtsgrond_id)');
    }
};

