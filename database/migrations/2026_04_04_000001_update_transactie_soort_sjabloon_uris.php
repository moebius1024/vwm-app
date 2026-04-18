<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Zet oude sjabloon-URI's om naar TB-classes
        DB::table('transactie_soort_sjabloon')
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#Sjabloon_Persoon')
            ->update(['sjabloon_uri' => 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving']);

        DB::table('transactie_soort_sjabloon')
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#Sjabloon_Voertuig')
            ->update(['sjabloon_uri' => 'http://ontologie.politie.nl/def/vwm#VoertuigBeschrijving']);

        DB::table('transactie_soort_sjabloon')
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#Sjabloon_Incident')
            ->update(['sjabloon_uri' => 'http://ontologie.politie.nl/def/vwm#IncidentBeschrijving']);

        DB::table('transactie_soorten')
            ->where('rdf_uri', 'http://ontologie.politie.nl/def/vwm#Sjabloon_Persoon')
            ->update(['rdf_uri' => 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving']);

        DB::table('transactie_soorten')
            ->where('rdf_uri', 'http://ontologie.politie.nl/def/vwm#Sjabloon_Voertuig')
            ->update(['rdf_uri' => 'http://ontologie.politie.nl/def/vwm#VoertuigBeschrijving']);

        DB::table('transactie_soorten')
            ->where('rdf_uri', 'http://ontologie.politie.nl/def/vwm#Sjabloon_Incident')
            ->update(['rdf_uri' => 'http://ontologie.politie.nl/def/vwm#IncidentBeschrijving']);
    }

    public function down(): void
    {
        DB::table('transactie_soort_sjabloon')
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving')
            ->update(['sjabloon_uri' => 'http://ontologie.politie.nl/def/vwm#Sjabloon_Persoon']);

        DB::table('transactie_soort_sjabloon')
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#VoertuigBeschrijving')
            ->update(['sjabloon_uri' => 'http://ontologie.politie.nl/def/vwm#Sjabloon_Voertuig']);

        DB::table('transactie_soort_sjabloon')
            ->where('sjabloon_uri', 'http://ontologie.politie.nl/def/vwm#IncidentBeschrijving')
            ->update(['sjabloon_uri' => 'http://ontologie.politie.nl/def/vwm#Sjabloon_Incident']);

        DB::table('transactie_soorten')
            ->where('rdf_uri', 'http://ontologie.politie.nl/def/vwm#PersoonsBeschrijving')
            ->update(['rdf_uri' => 'http://ontologie.politie.nl/def/vwm#Sjabloon_Persoon']);

        DB::table('transactie_soorten')
            ->where('rdf_uri', 'http://ontologie.politie.nl/def/vwm#VoertuigBeschrijving')
            ->update(['rdf_uri' => 'http://ontologie.politie.nl/def/vwm#Sjabloon_Voertuig']);

        DB::table('transactie_soorten')
            ->where('rdf_uri', 'http://ontologie.politie.nl/def/vwm#IncidentBeschrijving')
            ->update(['rdf_uri' => 'http://ontologie.politie.nl/def/vwm#Sjabloon_Incident']);
    }
};
