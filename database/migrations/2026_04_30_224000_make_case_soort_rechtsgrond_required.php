<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $wpg8 = DB::table('rechtsgronden')->where('code', 'WPG8')->first();
        $rechtsgrondId = $wpg8?->id;

        if (! $rechtsgrondId) {
            $rechtsgrondId = DB::table('rechtsgronden')->insertGetId([
                'naam' => 'WPG8',
                'code' => 'WPG8',
                'omschrijving' => 'Standaard rechtsgrond.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('case_soorten')->update(['rechtsgrond_id' => $rechtsgrondId]);

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('
                CREATE TRIGGER IF NOT EXISTS case_soorten_rechtsgrond_required_insert
                BEFORE INSERT ON case_soorten
                FOR EACH ROW
                WHEN NEW.rechtsgrond_id IS NULL
                BEGIN
                    SELECT RAISE(ABORT, "case_soorten.rechtsgrond_id is verplicht");
                END;
            ');

            DB::unprepared('
                CREATE TRIGGER IF NOT EXISTS case_soorten_rechtsgrond_required_update
                BEFORE UPDATE OF rechtsgrond_id ON case_soorten
                FOR EACH ROW
                WHEN NEW.rechtsgrond_id IS NULL
                BEGIN
                    SELECT RAISE(ABORT, "case_soorten.rechtsgrond_id is verplicht");
                END;
            ');
        } else {
            DB::statement('ALTER TABLE case_soorten MODIFY rechtsgrond_id BIGINT UNSIGNED NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS case_soorten_rechtsgrond_required_insert;');
            DB::unprepared('DROP TRIGGER IF EXISTS case_soorten_rechtsgrond_required_update;');
        } else {
            DB::statement('ALTER TABLE case_soorten MODIFY rechtsgrond_id BIGINT UNSIGNED NULL');
        }
    }
};
