# VWM Ontologie & Regels (GraphDB)

Deze map bevat de “bron van waarheid” voor de ontologie en het regelmodel.
We gebruiken `Docs/statements.ttl` als export + bronbestand en laden dit terug in GraphDB.

## Wat staat er in `statements.ttl`

- Ontologie (klassen, properties, labels)
- TB‑definities (klassen met `vwm:beschrijftClass`)
- **RelatieRegels** voor automatische koppelingen

## Wat staat er in `shapes.ttl`

- Dunne SHACL‑laag met `sh:targetClass` en `sh:property`
- Alleen minimale validatie + volgorde (`sh:order`)
- **RolRegels** (mapping van `vwm:RolType` naar rol‑TB metadata)

### Regelmodel (RDF)

**RelatieRegels** (automatische koppelingen)
- Class: `vwm:RelatieRegel`
- Properties:
  - `vwm:vanClass`
  - `vwm:naarClass`
  - `vwm:predicate`

**RolRegels** (rol‑TB’s genereren)
- Bron: SHACL `sh:NodeShape` met `sh:targetNode` op `vwm:RolType_*`
- Properties:
  - `sh:targetNode` (roltype)
  - `vwm:rolTbClass`
  - `vwm:vanClass`
  - `vwm:naarClass`
  - `vwm:vanProperty`
  - `vwm:naarProperty`

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
- `http://vwm.voorbeeld.nl/model/ontologie` (voor UI-metadatasqueries)
- `http://rdf4j.org/schema/rdf4j#SHACLShapeGraph` (voor GraphDB SHACL-validatie)

## Gebruik in de app

Laravel leest de regels via SPARQL:
- `RelatieRegels` → automatisch koppelen van GOIC’s
- `RolRegels` (uit SHACL) → aanmaken van rol‑TB’s
- `RolTypes` → mapping van legacy UI keys naar roltype‑URI’s

Daarmee bevat de controller geen inhoudelijke domein‑logica meer.
