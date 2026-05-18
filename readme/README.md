# VWM Ontologie & Regels (GraphDB)

Deze map bevat de “bron van waarheid” voor de ontologie en het regelmodel.
We gebruiken de bestanden in `ontology/` als bron en laden die terug in GraphDB.

## Bestandslocaties (actueel)

- `ontology/statements.ttl` → ontologie/triples (classes, properties, labels, beschrijftClass)
- `ontology/shapes-domain.ttl` → domein-shapes (sjablonen en velddefinities)
- `ontology/shapes-process.ttl` → proces-shapes (rolregels e.d.)
- `ontology/shapes-ui.ttl` → UI-only metadata (lookup hints)
- `ontology/shapes.ttl` → gecombineerde shape-set (o.a. voor SHACLShapeGraph)

## Harde scheidingsregel (Do / Don't)

Do:
- Zet **alleen** UI-metadata (`ui:*`, zoals `ui:lookup*`, `ui:fieldWidth`) in `ontology/shapes-ui.ttl`.
- Zet domeinconstraints (`sh:datatype`, `sh:minCount`, `sh:in`, semantische regels) in `ontology/shapes-domain.ttl`.
- Zet proces/rolregels in `ontology/shapes-process.ttl`.

Don't:
- Geen `ui:*` metadata toevoegen in `shapes-domain.ttl` of `shapes-process.ttl`.
- Geen domein- of proceslogica toevoegen in `shapes-ui.ttl`.

## Wat staat er in `statements.ttl`

- Ontologie (klassen, properties, labels)
- TB‑definities (klassen met `vwm:beschrijftClass`)
- **RelatieRegels** voor automatische koppelingen

## Wat staat er in `shapes.ttl`

- SHACL‑laag met `sh:targetClass` en `sh:property`
- Validatie + volgorde (`sh:order`)
- UI/lookup‑metadata op `sh:property` (bijv. externe lookup voor kenteken → RDW)

### Regelmodel (RDF)

**RelatieRegels** (automatische koppelingen, legacy/optioneel)
- Class: `vwm:RelatieRegel`
- Properties:
  - `vwm:vanClass`
  - `vwm:naarClass`
  - `vwm:predicate`

Let op:
- `vwm:RelatieRegel` staat nog in de ontologie, maar is niet meer het primaire sturingsmechanisme in de huidige bewerkflow.
- Primair zijn nu: `transactie_soort_sjabloon` (SQLite), rolregels in SHACL (`shapes-process.ttl`) en shape-metadata per sjabloon.

**Lookup metadata op PropertyShape** (externe verrijking)
- `ui:lookupEndpoint` (API endpoint)
- `ui:lookupQueryParam` (query parameter voor bronveld)
- `ui:lookupSourceField` (veldnaam uit response voor targetveld)
- `ui:lookupTrigger` (`input` of `blur`)
- `ui:lookupDebounceMs`
- `ui:lookupMinLength`
- `ui:fieldWidth` (UI-breedtehint, bijv. `sm`, `md`, `full`)

**Identity metadata op PropertyShape** (GO-hergebruik / deduplicatie)
- `vwm:isIdentityKey true` (veld telt mee als identity-key)
- `vwm:identityNormalizer` (normalisatie-strategie, bijv. `ALNUM_UPPER`)

**RolTypes**
- Class: `vwm:RolType`
- Extra property: `vwm:roleKey` (legacy key vanuit de UI, bijv. `drivers`, `owners`, `witnesses`, `bystanders`)

**Identifier‑properties**
- Gebruik `vwm:isIdentifier true` op de **property zelf**
  (bijv. `dpm:lastName`, `dpm:licensePlate`, `dpm:location`)
  om aan te geven welke waarden in het raadpleeg‑scherm als identificatie
  moeten worden getoond.

## Laden naar GraphDB

Gebruik het script om ontologie + SHACL te laden:

```bash
php scripts/update_ontologie_graphdb.php
```

Dit script synchroniseert SHACL-shapes naar beide graph-contexten:
- `http://vwm.voorbeeld.nl/model/ontologie` (ontologie triples)
- `http://vwm.voorbeeld.nl/model/shapes/domain` (domein-shapes die de app bevraagt)
- `http://vwm.voorbeeld.nl/model/shapes/process` (proces-shapes die de app bevraagt)
- `http://vwm.voorbeeld.nl/model/shapes/ui` (UI-shapes die de app bevraagt)
- `http://rdf4j.org/schema/rdf4j#SHACLShapeGraph` (voor GraphDB SHACL-validatie)

Belangrijk:
- Als alleen `ontology/shapes.ttl`/`SHACLShapeGraph` is bijgewerkt maar `/model/shapes/*` niet,
  kan de UI sjablonen tonen als **"Onbekend sjabloon"**.
- Daarom altijd `php scripts/update_ontologie_graphdb.php` draaien na shape-wijzigingen.

## Bestaande voertuigen normaliseren op kenteken

Voor bestaande data kun je voertuig-GOIC’s met hetzelfde kenteken aan dezelfde GO koppelen:

```bash
php scripts/normalize_vehicle_go_by_license_plate.php
```

Dit is een **dry-run** en laat alleen zien wat er aangepast zou worden.
Toepassen doe je met:

```bash
php scripts/normalize_vehicle_go_by_license_plate.php --apply
```

## Historische `toestand_data` opschonen in SQLite

Standaard schrijft de app nu geen inhoud meer naar `toestands_beschrijvingen.toestand_data`.
Bestaande inhoud kun je optioneel opschonen met:

```bash
php scripts/clear_toestand_data_sqlite.php
php scripts/clear_toestand_data_sqlite.php --apply
```

## Gebruik in de app

Laravel leest de regels via SPARQL:
- `RelatieRegels` → automatisch koppelen van GOIC’s
- `RolTypes` → mapping van legacy UI keys naar roltype‑URI’s
- Lookup metadata op `sh:property` → generieke veldverrijking in de UI
  (o.a. kentekenlookup via `/api/voertuig/kenteken`)
- Identity metadata op `sh:property` → generiek hergebruik van bestaande GO’s
  (`vwm:beschrijftGO`) op basis van SHACL-configuratie, zonder hardcoded domeinklasse
- In SQLite bewaren we bij `toestands_beschrijvingen` alleen de verwijzing naar TB
  (`uuid`/`rdf_uri`/`beschrijving`); inhoudelijke toestand staat in GraphDB
  en mutatiepayload blijft in `object_mutaties.data` alleen als audit-snapshot
  (niet actief bevraagd voor inhoudelijke applicatielogica)

Daarmee bevat de sjabloon/verwerkingsflow geen hardcoded veldmapping meer; gedrag komt uit RDF/SHACL metadata.

## Verificatie na muteren/verwijderen

Gebruik de standaard verificatie-set in:

- `readme/Mutatie_Verificatie_Queries.md`

Deze bevat SQLite- en SPARQL-queries voor:
- `mutate` (oude TB beëindigen + nieuwe TB registreren)
- `role delete` (alleen beëindigen van geraakte TB)
