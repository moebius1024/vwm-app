# SPARQL Werkafspraken (VWM)

Gebaseerd op: `Docs/Learning-sparql-querying-and-updating-with-sparql-11.pdf`.
Doel: minder fouten, voorspelbare queries/updates, sneller debuggen.

## 1. Altijd vaste prefix-set gebruiken

Gebruik standaard:

```sparql
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX xsd:  <http://www.w3.org/2001/XMLSchema#>
PREFIX dpm:  <http://ontologie.politie.nl/def/dpm#>
PREFIX vwm:  <http://ontologie.politie.nl/def/vwm#>
```

## 2. Eerst klein verifiëren, dan pas schrijven

Voor elke `INSERT/DELETE` eerst:
1. `SELECT` met exact dezelfde `WHERE` om te zien wat geraakt wordt.
2. `SELECT (COUNT(*) AS ?n)` voor volume-check.
3. Pas daarna update uitvoeren.

## 3. Named graph discipline

We gebruiken gescheiden graphs (minimaal):
- ontologie
- SHACL shapes
- data (`onderzoek`)

Regels:
1. Updates naar data altijd expliciet met `GRAPH <...>` of `WITH <...>`.
2. Niet mixen van default graph en named graph in één update zonder noodzaak.
3. Voor bulk-acties eerst snapshot/export.

## 4. Update voorkeuren

1. Gebruik `INSERT DATA` als er geen variabelen/patronen nodig zijn.
2. Gebruik `INSERT ... WHERE` of `DELETE/INSERT ... WHERE` alleen bij patroon-gedreven logica.
3. Bij vervangingen: altijd `DELETE` + `INSERT` in één request, met dezelfde `WHERE`-scope.

## 5. Veiligheidschecks bij DELETE

Verplicht voor elk delete-script:
1. Eerst dry-run met `SELECT`.
2. Scope beperken met `VALUES`, specifieke subjecten, of graph-naam.
3. Nooit “breed” verwijderen zonder extra filter (bijv. alleen op predicate).

## 6. Datatype en taal altijd expliciet

1. Data die SHACL valideert: literal type moet exact kloppen (`xsd:date`, `xsd:dateTime`, `xsd:integer`, etc.).
2. Taal-tags: gebruik `langMatches(lang(?label), "en")` i.p.v. strikte `lang(?label) = "en"`.

## 7. Query performance (hoofdzaken)

1. Beperk zoekruimte vroeg (specifieke graph, class, predicate).
2. Zet selectieve triple patterns vroeg in `WHERE`.
3. Gebruik `OPTIONAL` alleen waar echt nodig.
4. Gebruik property paths spaarzaam; kunnen duur zijn.
5. `DISTINCT` alleen wanneer functioneel nodig.

## 8. Debug workflow

1. Start met minimale query (1-2 triple patterns).
2. Bouw incrementeel uit.
3. Voeg tijdelijk `LIMIT` toe.
4. Controleer tussenresultaten met extra variabelen in `SELECT`.
5. Bij updates: eerst equivalent `SELECT` draaien.

## 9. Standaard diagnosequeries

### 9.1 Bestaat een GOIC?
```sparql
SELECT * WHERE {
  GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
    VALUES ?goic { <GOIC_URI> }
    ?goic ?p ?o .
  }
}
```

### 9.2 Welke GOIC’s horen bij dezelfde GO?
```sparql
PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
SELECT ?goic ?go WHERE {
  GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
    VALUES ?goic { <GOIC_URI> }
    ?goic vwm:beschrijftGO ?go .
  }
}
```

### 9.3 Volg-associaties controleren
```sparql
PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>
SELECT ?assoc ?owned ?target WHERE {
  GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
    ?assoc a dpm:DataObjectAssociation ;
           dpm:ownedObject ?owned ;
           dpm:targetObject ?target .
  }
}
```

### 9.4 GOIC doelclass controleren
```sparql
PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
SELECT ?goic ?class WHERE {
  GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
    VALUES ?goic { <GOIC_URI> }
    OPTIONAL { ?goic vwm:heeftDoelClass ?class . }
  }
}
```

### 9.5 GOIC zonder doelclass (volume-check)
```sparql
PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
SELECT (COUNT(DISTINCT ?goic) AS ?zonderDoelClass) WHERE {
  GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
    ?goic a vwm:GegevensObjectInContext .
    FILTER NOT EXISTS { ?goic vwm:heeftDoelClass ?class . }
  }
}
```

## 10. Werkafspraak bij productie-achtige data

1. Elke destructieve query eerst laten reviewen (4-ogen-principe).
2. Query + verwacht effect kort documenteren in commit/issue.
3. Na update direct verificatiequery uitvoeren en resultaat bewaren.

---

## Kort samengevat

- Eerst meten (`SELECT`), dan muteren (`UPDATE`).
- Graph-scope altijd expliciet.
- Datatypes exact.
- Kleine, controleerbare stappen.
