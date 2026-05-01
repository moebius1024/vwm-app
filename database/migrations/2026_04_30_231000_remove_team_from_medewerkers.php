<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement('
                CREATE TABLE medewerkers_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    user_id INTEGER NULL,
                    medewerker_nummer VARCHAR NOT NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL,
                    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
                )
            ');
            DB::statement('
                INSERT INTO medewerkers_new (id, user_id, medewerker_nummer, created_at, updated_at)
                SELECT id, user_id, medewerker_nummer, created_at, updated_at
                FROM medewerkers
            ');
            DB::statement('DROP TABLE medewerkers');
            DB::statement('ALTER TABLE medewerkers_new RENAME TO medewerkers');
            DB::statement('CREATE UNIQUE INDEX medewerkers_medewerker_nummer_unique ON medewerkers(medewerker_nummer)');
            DB::statement('PRAGMA foreign_keys = ON');

            return;
        }

        DB::statement('ALTER TABLE medewerkers DROP FOREIGN KEY medewerkers_team_id_foreign');
        DB::statement('ALTER TABLE medewerkers DROP COLUMN team_id');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $defaultTeamId = (int) DB::table('teams')->orderBy('id')->value('id');
            if ($defaultTeamId <= 0) {
                $defaultTeamId = (int) DB::table('teams')->insertGetId([
                    'naam' => 'Default Team',
                    'code' => 'TEAM-DEFAULT-RESTORE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

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
                SELECT id, user_id, {$defaultTeamId}, medewerker_nummer, created_at, updated_at
                FROM medewerkers
            ");
            DB::statement('DROP TABLE medewerkers');
            DB::statement('ALTER TABLE medewerkers_old RENAME TO medewerkers');
            DB::statement('CREATE UNIQUE INDEX medewerkers_medewerker_nummer_unique ON medewerkers(medewerker_nummer)');
            DB::statement('CREATE INDEX medewerkers_team_id_index ON medewerkers(team_id)');
            DB::statement('PRAGMA foreign_keys = ON');

            return;
        }

        DB::statement('ALTER TABLE medewerkers ADD COLUMN team_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE medewerkers ADD CONSTRAINT medewerkers_team_id_foreign FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE');
    }
};

