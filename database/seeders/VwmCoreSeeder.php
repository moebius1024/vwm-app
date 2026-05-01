<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VwmCoreSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Maak een CaseSoort (De 'Smaak' van het dossier)
        $caseSoortId = DB::table('case_soorten')
            ->where('code', 'AO-001')
            ->value('id');

        if (! $caseSoortId) {
            $caseSoortId = DB::table('case_soorten')->insertGetId([
                'naam' => 'Algemeen Onderzoek',
                'code' => 'AO-001',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Maak een TransactieSoort (De 'Actie' op het scherm)
        // De rdf_uri is de cruciale link naar je Sjabloon in GraphDB!
        $transactieSoortId = DB::table('transactie_soorten')
            ->where('naam', 'Persoon Registreren')
            ->value('id');

        if (! $transactieSoortId) {
            $transactieSoortId = DB::table('transactie_soorten')->insertGetId([
                'naam' => 'Persoon Registreren',
                'rdf_uri' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Koppel de transactie-soort aan één of meerdere sjablonen
        $linkExists = DB::table('transactie_soort_sjabloon')
            ->where('transactie_soort_id', $transactieSoortId)
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving')
            ->exists();

        if (! $linkExists) {
            DB::table('transactie_soort_sjabloon')->insert([
                'transactie_soort_id' => $transactieSoortId,
                'sjabloon_uri' => 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving',
                'type' => 'sjabloon',
                'volgorde' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2b. VerkeersIncident transactie + vaste set beschrijvingen (Incident, Persoon, Voertuig)
        $verkeersIncidentId = DB::table('transactie_soorten')
            ->where('naam', 'VerkeersIncident')
            ->value('id');

        if (! $verkeersIncidentId) {
            $verkeersIncidentId = DB::table('transactie_soorten')->insertGetId([
                'naam' => 'VerkeersIncident',
                'rdf_uri' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $beschrijvingen = [
            ['uri' => 'http://ontologie.politie.nl/def/vwm#IncidentBeschrijving', 'volgorde' => 1],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#IncidentToestandsWeergave', 'volgorde' => 2],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving', 'volgorde' => 3],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#PersoonToestandsWeergave', 'volgorde' => 4],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#VoertuigBeschrijving', 'volgorde' => 5],
        ];

        foreach ($beschrijvingen as $desc) {
            $existing = DB::table('transactie_soort_sjabloon')
                ->where('transactie_soort_id', $verkeersIncidentId)
                ->where('sjabloon_uri', $desc['uri'])
                ->first();

            if ($existing) {
                DB::table('transactie_soort_sjabloon')
                    ->where('id', $existing->id)
                    ->update([
                        'type' => 'sjabloon',
                        'volgorde' => $desc['volgorde'],
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('transactie_soort_sjabloon')->insert([
                'transactie_soort_id' => $verkeersIncidentId,
                'sjabloon_uri' => $desc['uri'],
                'type' => 'sjabloon',
                'volgorde' => $desc['volgorde'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2c. VerkeersIncident: toegestane rol-TB's (Eigenaar/Bestuurder/Getuige/Omstander)
        $rolTbClasses = [
            ['uri' => 'http://ontologie.politie.nl/def/vwm#Rol_Bestuurder', 'volgorde' => 1],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#Rol_Eigenaar', 'volgorde' => 2],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#Rol_Getuige', 'volgorde' => 3],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#Rol_Omstander', 'volgorde' => 4],
        ];

        foreach ($rolTbClasses as $rolTb) {
            $exists = DB::table('transactie_soort_sjabloon')
                ->where('transactie_soort_id', $verkeersIncidentId)
                ->where('sjabloon_uri', $rolTb['uri'])
                ->where('type', 'rol')
                ->exists();

            if (! $exists) {
                DB::table('transactie_soort_sjabloon')->insert([
                    'transactie_soort_id' => $verkeersIncidentId,
                    'sjabloon_uri' => $rolTb['uri'],
                    'type' => 'rol',
                    'volgorde' => $rolTb['volgorde'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 2d. Winkeldiefstal transactie + vaste set beschrijvingen (Incident, Persoon, Onderneming)
        $winkeldiefstalId = DB::table('transactie_soorten')
            ->where('naam', 'Winkeldiefstal')
            ->value('id');

        if (! $winkeldiefstalId) {
            $winkeldiefstalId = DB::table('transactie_soorten')->insertGetId([
                'naam' => 'Winkeldiefstal',
                'rdf_uri' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $winkeldiefstalBeschrijvingen = [
            ['uri' => 'http://ontologie.politie.nl/def/vwm#IncidentBeschrijving', 'volgorde' => 1],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving', 'volgorde' => 2],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#OndernemingsBeschrijving', 'volgorde' => 3],
        ];

        foreach ($winkeldiefstalBeschrijvingen as $desc) {
            $existing = DB::table('transactie_soort_sjabloon')
                ->where('transactie_soort_id', $winkeldiefstalId)
                ->where('sjabloon_uri', $desc['uri'])
                ->first();

            if ($existing) {
                DB::table('transactie_soort_sjabloon')
                    ->where('id', $existing->id)
                    ->update([
                        'type' => 'sjabloon',
                        'volgorde' => $desc['volgorde'],
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('transactie_soort_sjabloon')->insert([
                'transactie_soort_id' => $winkeldiefstalId,
                'sjabloon_uri' => $desc['uri'],
                'type' => 'sjabloon',
                'volgorde' => $desc['volgorde'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2e. CaseSoort Winkeldiefstal
        $winkeldiefstalCaseSoortId = DB::table('case_soorten')
            ->where('code', 'WD-001')
            ->value('id');

        if (! $winkeldiefstalCaseSoortId) {
            $winkeldiefstalCaseSoortId = DB::table('case_soorten')->insertGetId([
                'naam' => 'Winkeldiefstal',
                'code' => 'WD-001',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Koppel de Transactie aan de Case (De 'Workflow')
        $workflowExists = DB::table('case_soort_transactie')
            ->where('case_soort_id', $caseSoortId)
            ->where('transactie_soort_id', $transactieSoortId)
            ->exists();

        if (! $workflowExists) {
            DB::table('case_soort_transactie')->insert([
                'case_soort_id' => $caseSoortId,
                'transactie_soort_id' => $transactieSoortId,
                'volgorde' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3b. Koppel Winkeldiefstal transactie aan Winkeldiefstal case-soort
        $winkeldiefstalWorkflow = DB::table('case_soort_transactie')
            ->where('case_soort_id', $winkeldiefstalCaseSoortId)
            ->where('transactie_soort_id', $winkeldiefstalId)
            ->first();

        if ($winkeldiefstalWorkflow) {
            DB::table('case_soort_transactie')
                ->where('id', $winkeldiefstalWorkflow->id)
                ->update([
                    'volgorde' => 1,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('case_soort_transactie')->insert([
                'case_soort_id' => $winkeldiefstalCaseSoortId,
                'transactie_soort_id' => $winkeldiefstalId,
                'volgorde' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 4. Autodiefstal transactie + beschrijvingen (Incident + Voertuig)
        $autodiefstalTransactieId = DB::table('transactie_soorten')
            ->where('naam', 'Autodiefstal')
            ->value('id');

        if (! $autodiefstalTransactieId) {
            $autodiefstalTransactieId = DB::table('transactie_soorten')->insertGetId([
                'naam' => 'Autodiefstal',
                'rdf_uri' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $autodiefstalSjablonen = [
            ['uri' => 'http://ontologie.politie.nl/def/vwm#IncidentBeschrijving', 'volgorde' => 1],
            ['uri' => 'http://ontologie.politie.nl/def/vwm#VoertuigBeschrijving', 'volgorde' => 2],
        ];

        foreach ($autodiefstalSjablonen as $desc) {
            $existing = DB::table('transactie_soort_sjabloon')
                ->where('transactie_soort_id', $autodiefstalTransactieId)
                ->where('sjabloon_uri', $desc['uri'])
                ->first();

            if ($existing) {
                DB::table('transactie_soort_sjabloon')
                    ->where('id', $existing->id)
                    ->update([
                        'type' => 'sjabloon',
                        'volgorde' => $desc['volgorde'],
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('transactie_soort_sjabloon')->insert([
                'transactie_soort_id' => $autodiefstalTransactieId,
                'sjabloon_uri' => $desc['uri'],
                'type' => 'sjabloon',
                'volgorde' => $desc['volgorde'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 4b. CaseSoort Autodiefstal
        $autodiefstalCaseSoortId = DB::table('case_soorten')
            ->where('code', 'AD-001')
            ->value('id');

        if (! $autodiefstalCaseSoortId) {
            $autodiefstalCaseSoortId = DB::table('case_soorten')->insertGetId([
                'naam' => 'Autodiefstal',
                'code' => 'AD-001',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 4c. Koppel Autodiefstal transactie aan Autodiefstal case-soort
        $autodiefstalWorkflow = DB::table('case_soort_transactie')
            ->where('case_soort_id', $autodiefstalCaseSoortId)
            ->where('transactie_soort_id', $autodiefstalTransactieId)
            ->first();

        if ($autodiefstalWorkflow) {
            DB::table('case_soort_transactie')
                ->where('id', $autodiefstalWorkflow->id)
                ->update([
                    'volgorde' => 1,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('case_soort_transactie')->insert([
                'case_soort_id' => $autodiefstalCaseSoortId,
                'transactie_soort_id' => $autodiefstalTransactieId,
                'volgorde' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
