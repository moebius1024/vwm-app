<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaultTeamId = $this->ensureDefaultTeam();

        if (DB::getDriverName() === 'sqlite') {
            $this->migrateMedewerkersSqlite($defaultTeamId);
            $this->migratePersonenSqlite($defaultTeamId);

            return;
        }

        DB::statement('ALTER TABLE medewerkers ADD COLUMN team_id BIGINT UNSIGNED NULL');
        DB::statement("UPDATE medewerkers SET team_id = {$defaultTeamId} WHERE team_id IS NULL");
        DB::statement('ALTER TABLE medewerkers MODIFY team_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE medewerkers ADD CONSTRAINT medewerkers_team_id_foreign FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE medewerkers DROP COLUMN naam');

        DB::statement('ALTER TABLE personen ADD COLUMN medewerker_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE personen ADD CONSTRAINT personen_medewerker_id_foreign FOREIGN KEY (medewerker_id) REFERENCES medewerkers(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE personen ADD UNIQUE personen_medewerker_id_unique (medewerker_id)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement('
            CREATE TABLE personen_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                naam VARCHAR NOT NULL,
                identifier VARCHAR NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        DB::statement('
            INSERT INTO personen_old (id, naam, identifier, created_at, updated_at)
            SELECT id, naam, identifier, created_at, updated_at
            FROM personen
        ');
        DB::statement('DROP TABLE personen');
        DB::statement('ALTER TABLE personen_old RENAME TO personen');
        DB::statement('CREATE UNIQUE INDEX personen_identifier_unique ON personen(identifier)');

        DB::statement('
            CREATE TABLE medewerkers_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                user_id INTEGER NULL,
                naam VARCHAR NOT NULL,
                medewerker_nummer VARCHAR NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
            )
        ');
        DB::statement('
            INSERT INTO medewerkers_old (id, user_id, naam, medewerker_nummer, created_at, updated_at)
            SELECT id, user_id, "Onbekend", medewerker_nummer, created_at, updated_at
            FROM medewerkers
        ');
        DB::statement('DROP TABLE medewerkers');
        DB::statement('ALTER TABLE medewerkers_old RENAME TO medewerkers');
        DB::statement('CREATE UNIQUE INDEX medewerkers_medewerker_nummer_unique ON medewerkers(medewerker_nummer)');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function ensureDefaultTeam(): int
    {
        $team = DB::table('teams')->where('code', 'TEAM-DEFAULT')->first();
        if ($team) {
            return (int) $team->id;
        }

        return (int) DB::table('teams')->insertGetId([
            'naam' => 'Default Team',
            'code' => 'TEAM-DEFAULT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function migrateMedewerkersSqlite(int $defaultTeamId): void
    {
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
            SELECT id, user_id, {$defaultTeamId}, medewerker_nummer, created_at, updated_at
            FROM medewerkers
        ");
        DB::statement('DROP TABLE medewerkers');
        DB::statement('ALTER TABLE medewerkers_new RENAME TO medewerkers');
        DB::statement('CREATE UNIQUE INDEX medewerkers_medewerker_nummer_unique ON medewerkers(medewerker_nummer)');
        DB::statement('CREATE INDEX medewerkers_team_id_index ON medewerkers(team_id)');
        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function migratePersonenSqlite(int $defaultTeamId): void
    {
        $personen = DB::table('personen')
            ->select('id', 'naam', 'identifier', 'created_at', 'updated_at')
            ->orderBy('id')
            ->get();

        $medewerkerIds = DB::table('medewerkers')->orderBy('id')->pluck('id')->all();
        $nextMedewerkerIndex = 0;

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('
            CREATE TABLE personen_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                medewerker_id INTEGER NOT NULL,
                naam VARCHAR NOT NULL,
                identifier VARCHAR NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY(medewerker_id) REFERENCES medewerkers(id) ON DELETE CASCADE
            )
        ');

        foreach ($personen as $persoon) {
            $medewerkerId = null;

            if ($nextMedewerkerIndex < count($medewerkerIds)) {
                $medewerkerId = (int) $medewerkerIds[$nextMedewerkerIndex];
                $nextMedewerkerIndex++;
            } else {
                $medewerkerId = (int) DB::table('medewerkers')->insertGetId([
                    'user_id' => null,
                    'team_id' => $defaultTeamId,
                    'medewerker_nummer' => 'AUTO-PERS-'.$persoon->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('personen_new')->insert([
                'id' => (int) $persoon->id,
                'medewerker_id' => $medewerkerId,
                'naam' => $persoon->naam,
                'identifier' => $persoon->identifier,
                'created_at' => $persoon->created_at,
                'updated_at' => $persoon->updated_at,
            ]);
        }

        DB::statement('DROP TABLE personen');
        DB::statement('ALTER TABLE personen_new RENAME TO personen');
        DB::statement('CREATE UNIQUE INDEX personen_identifier_unique ON personen(identifier)');
        DB::statement('CREATE UNIQUE INDEX personen_medewerker_id_unique ON personen(medewerker_id)');
        DB::statement('PRAGMA foreign_keys = ON');
    }
};

