# VWM Documentatieoverzicht

Deze map bevat de functionele en technische afspraken van VWM.
Gebruik dit bestand als ingang; de details staan in de onderliggende documenten.

## Wat is VWM in het kort?

VWM is een metadata-gedreven Laravel + GraphDB applicatie voor:

- case- en dossierworkflow
- registratie/mutatie van GOIC's en toestanden
- semantische modellering in RDF/SHACL
- autorisatie op basis van rechtsgrond

Kernprincipe:
- Laravel regelt proces en autorisatie.
- GraphDB regelt betekenis, model en validatie.

## Leesvolgorde (aanbevolen)

1. Totaaloverzicht
- `readme/VWM_Architectuur_Overzicht.md`

2. Architectuurprincipes
- `readme/Architecture_Principles.md`

3. Ontologie + SHACL + GraphDB afspraken
- `readme/RDF_Validatie_Werkafspraken.md`
- `readme/SPARQL_Werkafspraken.md`

4. Hergebruik/follow semantiek
- `readme/Hergebruik.md`

5. Verificatie en checklists
- `readme/Mutatie_Verificatie_Queries.md`
- `readme/Laravel_Checklist_Kort.md`
- `readme/SPARQL_Checklist_Kort.md`
- `readme/RDF_Validatie_Checklist_Kort.md`

## Ontologie en SHACL: bronbestanden

De bron van waarheid voor model/regels staat in `ontology/`:

- `ontology/statements.ttl` -> ontologie/triples
- `ontology/shapes-domain.ttl` -> domein-shapes en constraints
- `ontology/shapes-process.ttl` -> proces- en rolshapes
- `ontology/shapes-ui.ttl` -> UI-only metadata
- `ontology/shapes.ttl` -> gecombineerde shape-set

## Harde scheiding (belangrijk)

Do:
- `ui:*` metadata alleen in `shapes-ui.ttl`
- domeinconstraints in `shapes-domain.ttl`
- proces/rolregels in `shapes-process.ttl`

Don't:
- geen `ui:*` in domain/process
- geen domein/proceslogica in UI-shapes

## Synchroniseren naar GraphDB

Na shape/ontologie-wijzigingen altijd synchroniseren met:

```bash
php scripts/update_ontologie_graphdb.php
```

Dit script synchroniseert naar:

- `http://vwm.voorbeeld.nl/model/ontologie`
- `http://vwm.voorbeeld.nl/model/shapes/domain`
- `http://vwm.voorbeeld.nl/model/shapes/process`
- `http://vwm.voorbeeld.nl/model/shapes/ui`
- `http://rdf4j.org/schema/rdf4j#SHACLShapeGraph`

## Extra operationele scripts

Voertuig-GOIC normalisatie op kenteken:

```bash
php scripts/normalize_vehicle_go_by_license_plate.php
php scripts/normalize_vehicle_go_by_license_plate.php --apply
```

Historische `toestand_data` opschonen:

```bash
php scripts/clear_toestand_data_sqlite.php
php scripts/clear_toestand_data_sqlite.php --apply
```

## Verificatie na mutaties

Gebruik:
- `readme/Mutatie_Verificatie_Queries.md`

Voor standaardcontrole op Laravel/SPARQL/RDF validatie, gebruik de checklists in deze map.
 