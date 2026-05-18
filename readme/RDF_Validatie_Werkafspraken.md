# RDF Validatie Werkafspraken (SHACL/GraphDB)

Gebaseerd op inzichten uit *Validating RDF Data* (SHACL + ShEx perspectief), toegepast op VWM.

## 1. Scheiding van concerns

1. Data graph is leidend voor instances (`onderzoek`).
2. Shapes graph is leidend voor constraints (`SHACLShapeGraph`).
3. Ontologie graph is leidend voor semantiek (class/property/labels).
4. Alleen bewust dubbelen naar ontologiegraph als UI-metadata dit vereist.

## 2. Valideren is twee-input proces

SHACL-validatie is altijd:
- `data graph` + `shapes graph` -> `ValidationReport`

Werkafspraak:
1. Nooit alleen op “lijkt goed” vertrouwen.
2. Bij iedere mutatieflow moet een SHACL-rapport uitlegbaar zijn in UI en logs.

## 3. Constraint-ontwerp

1. Gebruik `sh:minCount` en `sh:maxCount` alleen waar functioneel verplicht.
2. Leg datatype expliciet vast (`sh:datatype`) voor alle kritieke velden.
3. Gebruik `sh:nodeKind` waar URI vs literal semantisch belangrijk is.
4. Houd shapes klein en leesbaar; liever meerdere eenvoudige PropertyShapes dan één complexe shape.

## 4. Named graph discipline

1. Update scripts moeten graph-context expliciet noemen.
2. Nooit bulk `CLEAR`/`DROP` zonder voorafgaande `SELECT`-preview.
3. Voor onderhoudsacties eerst tellen (`COUNT`) en pas dan wijzigen.

## 5. Rapportinterpretatie

Bij `sh:ValidationResult` letten op:
1. `sh:focusNode` (welke resource)
2. `sh:resultPath` (welke property)
3. `sh:sourceConstraintComponent` (waarom fout)
4. `sh:value` (welke invoerwaarde)

Werkafspraak: UI toont minimaal deze vier elementen begrijpelijk.

## 6. Typische foutklasse in dit project

1. Datatype-mismatch (bv. string i.p.v. `xsd:integer`, datum zonder correct datatype)
2. Ontbrekende verplichte waarden (`sh:minCount`)
3. Verkeerde graph gevuld (data in shape graph of omgekeerd)
4. Relaties die semantisch kloppen maar syntactisch niet (URI/literal mismatch)

## 7. Veranderstrategie voor shapes

1. Eerst impactanalyse op bestaande data (hoeveel nodes gaan falen?).
2. Daarna shape aanpassen in feature branch.
3. Dan proefvalidatie op representatieve dataset.
4. Pas dan uitrollen naar gedeelde omgeving.

## 8. ShEx vs SHACL (praktisch)

Voor VWM gebruiken we SHACL als operationele validatiestandaard in GraphDB.
ShEx-ideeën kunnen helpen bij ontwerp, maar runtime-validatie en tooling blijven SHACL-first.

## 9. Operationale standaard

1. Elke datafix krijgt bijbehorende verificatiequery.
2. Elke shape-wijziging krijgt notitie met verwachte impact.
3. Geen silent failure: validatie-uitkomsten altijd loggen.

## 10. TL;DR

- Data, ontologie en shapes strikt scheiden.
- SHACL-rapporten als first-class output behandelen.
- Datatypes en cardinaliteit expliciet en getest houden.
