# VWM Architectuur (Laravel + GraphDB)

## Doel van dit document
Dit document beschrijft de actuele architectuur van de VWM-applicatie in `vwm-app`: modellen, routes, controllers, RDF/TTL/SHACL-bestanden en de belangrijkste functionele flows.

Voor de verhaallijn *idee -> model -> realisatie* sluit dit aan op de bestaande presentaties in `docs/`:
- `docs/VerwerkingsModel_Takkie_06.pptx`
- `docs/VerwerkingsModelHans_20220120.pptx`

## 1) Architectuuroverzicht
De applicatie bestaat uit drie lagen:

1. Laravel web/app-laag (workflow, autorisatie, UI-orchestratie)
- Inertia/Vue-pagina's voor start, bewerken, raadplegen, beheer.
- Authenticatie/autorisatie via Fortify + eigen autorisatietabellen.
- Generieke afhandeling van transacties en mutaties.

2. Relationele proceslaag (SQLite)
- Proces- en auditregistratie: cases, dossiers, transacties, mutaties, GOIC-referenties.
- Bevat geen volledige semantische domeininhoud; die zit in GraphDB.

3. Semantische laag (GraphDB)
- Ontologie (klassen/properties), sjabloondefinities, rolregels, validatieregels (SHACL).
- Inhoudelijke toestand en relaties van objecten worden als RDF-triples opgeslagen.

Kernprincipe: Laravel is workflow-engine; GraphDB is model + betekenis.

## 2) Belangrijkste modellen/tabellen (SQLite)
Bron: `database/migrations/*` en schema via Boost.

Kern van de flow:
- `cases`: onderzoekscases per gebruiker.
- `dossiers`: dossierstructuur binnen een case, inclusief `rdf_uri` verwijzing naar GraphDB.
- `transacties`: uitgevoerde transactie per case.
- `object_mutaties`: audit van handelingen op objecten/toestanden (incl. links naar geproduceerde/verwijderde toestand).
- `gegevens_objecten_in_context` (GOIC): lokale registratie van GOIC-identiteit met `rdf_uri`.
- `toestands_beschrijvingen`: lokale referenties naar toestanden (`rdf_uri`, metadata), niet de volledige semantische inhoud.
- `data_object_associations`: associaties tussen GOIC's (owned/target), geproduceerd door mutaties.

Configurerende tabellen:
- `transactie_soorten`
- `transactie_soort_sjabloon` (koppelt transacties aan sjablonen en rol-selectoren, met `crud_flags`)
- `case_soorten`, `case_soort_transactie`, `case_soort_dossier_types`

Autorisatiemodel:
- `rechtsgronden`, `autorisatie_rollen`, `functie_soorten`, `functies`, `medewerkers`, `teams`, `personen`.

Eloquent-modellen in `app/Models` dekken vooral autorisatie/organisatie (`Team`, `Medewerker`, `Functie`, `FunctieSoort`, `AutorisatieRol`, `Rechtsgrond`, `CaseSoort`, `Persoon`, `User`).

### 2.1 Autorisatie: precies zo is het in code geregeld
- De toegestane `rechtsgrond_id`'s voor een user worden afgeleid via:
  `medewerkers -> functies -> autorisatie_rollen` op `functie_soort_id`.
- Dit gebeurt in `CaseController::allowedRechtsgrondIdsForUser()`:
  `app/Http/Controllers/CaseController.php`.
- Case-toegang wordt daarna overal gefilterd met:
  `whereIn('case_soorten.rechtsgrond_id', $allowedRechtsgrondIds)`.
- Dezelfde autorisatielijn stuurt ook zichtbaarheid van data:
  `fetchVisibleGoicUrisForUser()` beperkt zichtbare GOIC's tot cases binnen toegestane rechtsgronden.
- Daardoor is autorisatie niet alleen "mag ik de case openen", maar ook
  "welke dossier/GOIC-inhoud mag ik zien".

## 3) Routes en entry points

### Web-routes (`routes/web.php`)
Belangrijkste gebruikersflow:
- `GET /start` -> `CaseController@index` (case kiezen/starten)
- `POST /cases` -> `CaseController@store` (nieuwe case)
- `POST /cases/{case}/dossiers` -> `CaseController@storeDossier`
- `GET /bewerken` -> `CaseController@edit` (registreren/muteren)
- `GET /raadplegen` -> `CaseController@consult`
- `GET /raadplegen/go` -> `CaseController@consultGo`
- `GET /beheer` + beheer-POST/PATCH routes -> `BeheerController`

Alles draait achter `auth` + `verified`; beheer achter extra `beheer` middleware.

### API-routes (`routes/api.php`)
Semantische motor/API:
- Sjabloon/metadata:
  - `GET /api/sjabloon/{id}`
  - `GET /api/sjabloon/uri`
  - `GET /api/sjablonen`
  - `GET /api/roltypes`
  - `GET|POST /api/labels`
  - `GET /api/identifiers`
- Mutatie/relatie:
  - `POST /api/mutatie`
  - `POST /api/goic/volg`
  - `POST /api/goic/volg-incident`
  - `POST /api/goic/displays`
- Validatie en hulpmiddelen:
  - `GET /api/shacl/validate`
  - `GET /api/voertuig/kenteken`
  - `POST /api/bestand/upload`, `GET /api/bestand/view`

## 4) Controllers en services

### `CaseController`
Verantwoordelijk voor case- en dossierworkflow:
- case/dossier aanmaken in SQLite
- dossier-type triples naar GraphDB schrijven
- bewerk- en raadpleegschermen voeden met dossiers, GOIC's, actieve toestanden, volgrelaties

### `SjabloonController`
Metadata-API voor dynamische formulieren:
- haalt sjabloondefinities op uit SHACL + ontologie
- combineert SQLite transactieconfiguratie (`transactie_soort_sjabloon`) met RDF-metadata
- levert `allowed_sjablonen`, `allowed_roles`, class hierarchy

### `MutatieController`
Kern van registreren/muteren/verwijderen:
- valideert payload (register/mutate/delete)
- gebruikt metadata uit SHACL/ontologie voor target class checks, CRUD-flags, rolregels, identity-keys
- schrijft triples naar GraphDB
- registreert audit/transactie/objectmutaties in SQLite
- ondersteunt rolmutaties en follow/association-logica

Specifiek voor GO/GOIC:
- Bij registratie wordt een GOIC gekoppeld aan exact één GO via
  `vwm:beschrijftGO` (triples opgebouwd in `storeMutatie()`).
- Daardoor kunnen meerdere GOIC's naar dezelfde GO wijzen (zelfde "sleutelhanger").
- Bij identity-match op basis van SHACL-regels (zoals kenteken) kan een bestaande GO
  worden hergebruikt in plaats van een nieuwe GO aan te maken.

### `BestandController`
Bestandsupload/bekijken, plus koppeling naar GraphDB/GOIC en auditregistratie.

### Services
- `GraphService`: generieke SPARQL query/update + SHACL validate endpoint.
- `SjabloonMetadataService`: leest ontologie- en shape-graphs en levert:
  - sjablonen/velden/labels
  - roltypes en rolregels
  - class hierarchy
  - capability- en identity-regels
  - value type hints

## 5) RDF/TTL/SHACL-bestanden (bron van waarheid)
Bestanden in `ontology/`:

1. `ontology/statements.ttl`
- Ontologie: classes/properties/labels.
- TB-classes met `vwm:beschrijftClass` (mapping TB -> domeinklasse).
- Roltypes (`vwm:RolType_*`, `vwm:roleKey`).
- Identifier-annotaties (`vwm:isIdentifier`).
- RelatieRegel-vocabulaire.

2. `ontology/shapes-domain.ttl`
- Domein-shapes (`sh:NodeShape`) per TB-class.
- Velddefinities: `sh:path`, `sh:datatype`, `sh:minCount`, `sh:order`, `sh:in`.
- Identity-metadata per veld (`vwm:isIdentityKey`, `vwm:identityNormalizer`).
- Domeinconstraints, inclusief SPARQL constraints.

3. `ontology/shapes-process.ttl`
- Proces/rol-shapes: mapping van roltype naar rol-TB-class + van/naar class/property.
- Stuurt rolinstantiatie en validatie in mutatieflow.

4. `ontology/shapes-ui.ttl`
- UI-only metadata (`ui:*`), zoals:
  - button labels
  - lookup endpoint/queryparam/sourcefield
  - field width hints
- Houdt rendergedrag buiten domeinconstraints.

5. `ontology/shapes.ttl`
- Gecombineerde shape-set (o.a. voor SHACLShapeGraph/validatie-setup).

## 6) Belangrijkste flows (end-to-end)

### Flow A: case starten
1. Gebruiker kiest case-soort (`/start`).
2. `CaseController@store` maakt `cases` + initieel `dossier` in SQLite.
3. Dossier-URI wordt als RDF type `vwm:Dossier` (en extra dossier types) in GraphDB gezet.

### Flow B: transactie openen en sjabloon laden
1. UI kiest transactie in `/bewerken`.
2. `GET /api/sjabloon/{transactieSoortId}`.
3. Controller combineert:
- SQLite-config (welke sjablonen/rollen met welke CRUD)
- SHACL/ontologie metadata (velden, target classes, labels, capabilities)
4. UI rendert dynamisch formulier/acties.

### Flow C: registreren/muteren/verwijderen van toestanden
1. UI post `POST /api/mutatie` met mode (`register|mutate|delete`) en objecten/rollen.
2. `MutatieController` valideert rechten, class-consistentie, CRUD-flags en rolregels.
3. Identity-rules kunnen bestaand GO-gebruik afdwingen/hergebruiken.
4. GraphDB krijgt nieuwe triples (en bij mutate/delete invalidaties).
5. SQLite logt `transacties`, `object_mutaties`, `toestands_beschrijvingen`, associations.

Implementatiedetail identity/hergebruik:
- Identity-rules worden uit SHACL gelezen via
  `SjabloonMetadataService::fetchIdentityRulesByTbClasses()`.
- Voor voertuig is dit o.a. `dpm:licensePlate` met
  `vwm:isIdentityKey true` + `vwm:identityNormalizer "ALNUM_UPPER"`
  in `ontology/shapes-domain.ttl`.

### Flow D: raadplegen
1. Gebruiker opent `/raadplegen`.
2. `CaseController@consult` bouwt dossieroverzicht en zichtbare GOIC's.
3. Actieve toestanden en linkmetadata worden uit GraphDB/SQLite gecombineerd.
4. UI toont semantische toestand per case/dossier.

### Flow D1: volgen van GOIC uit ander dossier (reeds ingericht)
1. UI roept `POST /api/goic/volg` aan met exact één `bron_goic_uri`.
2. `MutatieController::volgGoic()` valideert:
- user heeft toegang tot doel-case,
- bron-GOIC bestaat,
- bron-GOIC heeft een GO (`vwm:beschrijftGO`),
- dubbele follow voor dezelfde case wordt voorkomen.
3. Binnen een DB-transactie wordt:
- een nieuwe GOIC in eigen dossier aangemaakt (`gegevens_objecten_in_context`),
- een `data_object_associations`-record aangemaakt (`owned_goic_uri` -> nieuwe GOIC, `target_goic_uri` -> bron GOIC),
- audit vastgelegd in `object_mutaties`.
4. In GraphDB worden triples geschreven:
- nieuwe GOIC `vwm:beschrijftGO <goUri>` (dus koppeling aan dezelfde GO als de bron),
- `dpm:DataObjectAssociation` met `dpm:ownedObject` en `dpm:targetObject`.

Dit is exact het gedrag: volgen creëert een lokale GOIC, koppelt die aan dezelfde GO, en legt de associatie vast.

### Flow D2: GOIC-sleutelhanger (GO) en voertuig-normalisatie
- Conceptueel: elke GOIC hangt aan één GO (`vwm:beschrijftGO`), meerdere GOIC's kunnen dezelfde GO delen.
- Reeds ingericht proces voor voertuigen:
  `scripts/normalize_vehicle_go_by_license_plate.php`.
- Dit script:
1. leest voertuig-GOIC's + kentekens uit GraphDB,
2. normaliseert kenteken (ALNUM_UPPER),
3. detecteert dubbele kentekens over meerdere GOIC's,
4. koppelt die GOIC's aan één canonieke GO door `vwm:beschrijftGO` te herschrijven.

### Flow D3: bron-persoon bij Signalement (reeds ingericht)
- In SHACL is `vwm:heeftBronGOIC` onderdeel van `PersoonSignalementShape`
  (`ontology/shapes-domain.ttl`), inclusief regels:
  - actief signalement moet bron hebben,
  - bron moet GOIC van een Persoon zijn.
- In UI-metadata staat voor dit veld `ui:lookupClass dpm:Person`
  (`ontology/shapes-ui.ttl`), zodat de bronselectie op Persoon gebeurt.
- In de frontend wordt selectie beperkt tot bestaande GOIC's in het huidige dossier/
  context van de actieve case; voor beschrijving-op-bestaand-object is bovendien
  expliciete controle ingebouwd op actieve signalementstatus.

### Flow E: SHACL-validatie
1. `GET /api/shacl/validate`.
2. `GraphService@validateShacl` roept GraphDB REST validate endpoint aan.
3. Conformance + rapport terug naar UI/API-consument.

## 7) Conceptueel verhaal voor je presentatie
Voor "VWM: van idee -> model -> realisatie" kun je deze lijn hanteren:

1. Idee
- Niet hardcoded schermen per domeinobject, maar generieke workflow.
- Scheiding tussen proces (Laravel) en betekenis (RDF/SHACL).

2. Model
- Ontologie (`statements.ttl`) definieert objecten/relaties/labels.
- SHACL (`shapes-domain/process/ui.ttl`) definieert velden, regels, rollen en UI-hints.
- Transactieconfiguratie in SQLite bepaalt welke modellen in welke workflowstap mogen.

3. Realisatie
- Inertia/Vue + Laravel controllers renderen dynamisch op basis van metadata.
- Mutaties worden semantisch in GraphDB geschreven en procedureel geaudit in SQLite.
- Resultaat: uitbreidbaar systeem waarin nieuw gedrag vooral via modelwijziging (TTL/SHACL + configuratie) kan, met minimale code-impact.

## 8) Relevante bronbestanden (snelle index)
- Routes: `routes/web.php`, `routes/api.php`
- Controllers: `app/Http/Controllers/CaseController.php`, `MutatieController.php`, `SjabloonController.php`, `BeheerController.php`, `BestandController.php`
- Services: `app/Services/GraphService.php`, `app/Services/SjabloonMetadataService.php`
- RDF/SHACL: `ontology/statements.ttl`, `ontology/shapes-domain.ttl`, `ontology/shapes-process.ttl`, `ontology/shapes-ui.ttl`, `ontology/shapes.ttl`
- Richtlijnen: `readme/README.md`, `readme/Architecture_Principles.md`
- Presentaties: `docs/VerwerkingsModel_Takkie_06.pptx`, `docs/VerwerkingsModelHans_20220120.pptx`

---

Als je wilt, kan ik hierna een tweede versie maken die al direct als "slide-outline" is ingedeeld (8-12 slides, met kernboodschap per slide).
