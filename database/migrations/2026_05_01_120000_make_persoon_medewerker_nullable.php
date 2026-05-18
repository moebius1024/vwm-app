<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE personen MODIFY medewerker_id BIGINT UNSIGNED NULL');

            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('
            CREATE TABLE personen_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                medewerker_id INTEGER NULL,
                naam VARCHAR NOT NULL,
                identifier VARCHAR NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY(medewerker_id) REFERENCES medewerkers(id) ON DELETE CASCADE
            )
        ');
        DB::statement('
            INSERT INTO personen_new (id, medewerker_id, naam, identifier, created_at, updated_at)
            SELECT id, medewerker_id, naam, identifier, created_at, updated_at
            FROM personen
        ');
        DB::statement('DROP TABLE personen');
        DB::statement('ALTER TABLE personen_new RENAME TO personen');
        DB::statement('CREATE UNIQUE INDEX personen_identifier_unique ON personen(identifier)');
        DB::statement('CREATE UNIQUE INDEX personen_medewerker_id_unique ON personen(medewerker_id)');
        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE personen MODIFY medewerker_id BIGINT UNSIGNED NOT NULL');

            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('
            CREATE TABLE personen_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                medewerker_id INTEGER NOT NULL,
                naam VARCHAR NOT NULL,
                identifier VARCHAR NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY(medewerker_id) REFERENCES medewerkers(id) ON DELETE CASCADE
            )
        ');
        DB::statement('
            INSERT INTO personen_old (id, medewerker_id, naam, identifier, created_at, updated_at)
            SELECT id, COALESCE(medewerker_id, (SELECT id FROM medewerkers ORDER BY id LIMIT 1)), naam, identifier, created_at, updated_at
            FROM personen
        ');
        DB::statement('DROP TABLE personen');
        DB::statement('ALTER TABLE personen_old RENAME TO personen');
        DB::statement('CREATE UNIQUE INDEX personen_identifier_unique ON personen(identifier)');
        DB::statement('CREATE UNIQUE INDEX personen_medewerker_id_unique ON personen(medewerker_id)');
        DB::statement('PRAGMA foreign_keys = ON');
    }
};

