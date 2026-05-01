<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaultTeamId = (int) DB::table('teams')->where('code', 'TEAM-LANDELIJK')->value('id');
        if ($defaultTeamId <= 0) {
            $defaultTeamId = (int) DB::table('teams')->orderBy('id')->value('id');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');

            DB::statement('
                CREATE TABLE medewerkers_new (
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
                INSERT INTO medewerkers_new (id, user_id, team_id, medewerker_nummer, created_at, updated_at)
                SELECT m.id, m.user_id, COALESCE(
                    (SELECT f.team_id FROM functies f WHERE f.medewerker_id = m.id ORDER BY f.id DESC LIMIT 1),
                    {$defaultTeamId}
                ), m.medewerker_nummer, m.created_at, m.updated_at
                FROM medewerkers m
            ");

            DB::statement('DROP TABLE medewerkers');
            DB::statement('ALTER TABLE medewerkers_new RENAME TO medewerkers');
            DB::statement('CREATE UNIQUE INDEX medewerkers_medewerker_nummer_unique ON medewerkers(medewerker_nummer)');
            DB::statement('CREATE INDEX medewerkers_team_id_index ON medewerkers(team_id)');

            DB::statement('
                CREATE TABLE functies_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    medewerker_id INTEGER NOT NULL,
                    functie_soort_id INTEGER NOT NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL,
                    FOREIGN KEY(medewerker_id) REFERENCES medewerkers(id) ON DELETE CASCADE,
                    FOREIGN KEY(functie_soort_id) REFERENCES functie_soorten(id) ON DELETE CASCADE
                )
            ');

            DB::statement('
                INSERT INTO functies_new (id, medewerker_id, functie_soort_id, created_at, updated_at)
                SELECT id, medewerker_id, functie_soort_id, created_at, updated_at
                FROM functies
            ');

            DB::statement('DROP TABLE functies');
            DB::statement('ALTER TABLE functies_new RENAME TO functies');
            DB::statement('CREATE UNIQUE INDEX functies_unique_assignment ON functies(medewerker_id, functie_soort_id)');

            DB::statement('PRAGMA foreign_keys = ON');

            return;
        }
    }

    public function down(): void
    {
        // Intentionally left minimal; this migration corrects data model direction.
    }
};

