# VWM App (Laravel + GraphDB)

VWM is een metadata-gedreven applicatie voor registratie, mutatie en raadpleging van onderzoeksgegevens.
De kern is een scheiding tussen:

- Laravel: workflow, autorisatie, UI en audit
- GraphDB/RDF: domeinmodel, betekenis en validatieregels

## Inleiding: wat VWM precies vastlegt

VWM registreert primair geen "feiten", maar uitspraken in context.
Een registratie betekent dus: op tijdstip T, in werkcontext C, is deze beschrijving vastgelegd met deze herkomst en autorisatiecontext.

Dat maakt het mogelijk om:
- meerdere perspectieven naast elkaar te laten bestaan (multi-realiteit),
- wijzigingen over tijd traceerbaar te maken,
- en te herleiden waarom, waar en door wie gegevens zijn verwerkt.

## GO en GOIC (de kernbegrippen)

- GO (`GegevensObject`): de "sleutelhanger" van een ding in de werkelijkheid.
  Een GO representeert het object in de werkelijkheid waarnaar verwezen wordt.
- GOIC (`GegevensObjectInContext`): datzelfde GO, maar dan binnen een concrete context
  (bijvoorbeeld een specifiek dossier/case met eigen autorisatiekader).
  Een GOIC verwijst naar uitspraken over dat object, gedaan in die context.

Belangrijke consequentie:
- meerdere GOIC's kunnen verwijzen naar dezelfde GO;
- de inhoudelijke kennis zit in toestandsbeschrijvingen (states) die aan een GOIC hangen;
- mutaties maken de geschiedenis expliciet (nieuwe state of invalidatie), in plaats van "overschrijven".

## Doel van de applicatie

De applicatie ondersteunt het proces van:

1. Case starten en dossier(s) beheren
2. Objecten in context registreren (GOIC's)
3. Toestanden muteren/invalideren over tijd
4. Relaties en rollen vastleggen
5. Inhoud raadplegen binnen autorisatiegrenzen

Het systeem is generiek: gedrag komt uit RDF/SHACL metadata in plaats van hardcoded domeinlogica.

## Architectuur in 1 minuut

- Weblaag: Laravel 13 + Inertia/Vue
- Proceslaag: SQLite (cases, dossiers, transacties, mutatie-audit)
- Semantische laag: GraphDB (ontologie, SHACL, data-triples)

Belangrijk concept:
- GOIC (GegevensObjectInContext) verwijst via `vwm:beschrijftGO` naar een GO (sleutelhanger).
- Meerdere GOIC's kunnen aan dezelfde GO hangen (hergebruik over dossiers/cases).

## Belangrijkste functionaliteit

- Dynamische sjablonen op basis van SHACL (`/api/sjabloon/*`)
- Registreren/muteren/verwijderen van toestanden (`/api/mutatie`)
- Volgen van een GOIC uit een ander dossier (`/api/goic/volg`)
- SHACL-validatie op GraphDB (`/api/shacl/validate`)
- Lookup-verrijking (bijv. kenteken -> voertuigdata)

## Autorisatie (functioneel)

Toegang is gekoppeld aan `case_soort.rechtsgrond_id`.
De toegestane rechtsgronden voor een user worden afgeleid via:
`user -> medewerker -> functie -> autorisatie_rol`.

Dat bepaalt:
- welke cases zichtbaar/toegankelijk zijn
- welke dossier/GOIC-inhoud binnen die cases zichtbaar is

## Repository-structuur

- `app/` Laravel controllers/services/models
- `routes/` web + api routes
- `ontology/` RDF/TTL/SHACL bronbestanden
- `scripts/` operationele scripts (load/normalisatie/onderhoud)
- `readme/` verdiepende documentatie en werkafspraken

## Startpunten voor lezers

- Functioneel + technisch totaalbeeld:
  - `readme/VWM_Architectuur_Overzicht.md`
- Ontologie/SHACL en GraphDB afspraken:
  - `readme/README.md`
- Hergebruik- en volgconcepten:
  - `readme/Hergebruik.md`

## Ontwikkeling

Dit is een Laravel-applicatie. Lokale setup volgt standaard Laravel-conventies.
Voor ontologie/shapes geldt: wijzig `ontology/*.ttl` en synchroniseer daarna naar GraphDB met:

```bash
php scripts/update_ontologie_graphdb.php
```

Voor meer operationele afspraken, zie de documenten in `readme/`.
