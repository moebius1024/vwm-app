<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE medewerkers MODIFY team_id BIGINT UNSIGNED NULL');

            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('
            CREATE TABLE medewerkers_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                user_id INTEGER NULL,
                team_id INTEGER NULL,
                medewerker_nummer VARCHAR NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE
            )
        ');
        DB::statement('
            INSERT INTO medewerkers_new (id, user_id, team_id, medewerker_nummer, created_at, updated_at)
            SELECT id, user_id, team_id, medewerker_nummer, created_at, updated_at
            FROM medewerkers
        ');
        DB::statement('DROP TABLE medewerkers');
        DB::statement('ALTER TABLE medewerkers_new RENAME TO medewerkers');
        DB::statement('CREATE UNIQUE INDEX medewerkers_medewerker_nummer_unique ON medewerkers(medewerker_nummer)');
        DB::statement('CREATE INDEX medewerkers_team_id_index ON medewerkers(team_id)');
        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE medewerkers MODIFY team_id BIGINT UNSIGNED NOT NULL');

            return;
        }

        $fallbackTeamId = (int) DB::table('teams')->orderBy('id')->value('id');

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('
            CREATE TABLE medewerkers_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                user_id INTEGER NULL,
                team_id INTEGER NOT NULL,
                medewerker_nummer VARCHAR NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE
            )
        ');
        DB::statement("
            INSERT INTO medewerkers_old (id, user_id, team_id, medewerker_nummer, created_at, updated_at)
            SELECT id, user_id, COALESCE(team_id, {$fallbackTeamId}), medewerker_nummer, created_at, updated_at
            FROM medewerkers
        ");
        DB::statement('DROP TABLE medewerkers');
        DB::statement('ALTER TABLE medewerkers_old RENAME TO medewerkers');
        DB::statement('CREATE UNIQUE INDEX medewerkers_medewerker_nummer_unique ON medewerkers(medewerker_nummer)');
        DB::statement('CREATE INDEX medewerkers_team_id_index ON medewerkers(team_id)');
        DB::statement('PRAGMA foreign_keys = ON');
    }
};

