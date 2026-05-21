# Mutatie Verificatie Queries (SQLite + GraphDB)

Gebruik deze queries direct na een `mutate` of `role delete` actie.

## 1) SQLite: laatste transacties binnen case

```sql
SELECT
  t.id AS transactie_id,
  t.case_id,
  t.transactie_soort_id,
  t.created_at,
  om.id AS object_mutatie_id,
  om.sjabloon_uri,
  om.object_uri,
  om.gegevens_object_in_context_id AS goic_id,
  om.geproduceerde_toestand_id AS tb_id,
  om.data
FROM transacties t
JOIN object_mutaties om ON om.transactie_id = t.id
WHERE t.case_id = :case_id
ORDER BY t.id DESC, om.id DESC
LIMIT 50;
```

## 2) SQLite: één GOIC-mutatiepad controleren

```sql
SELECT
  om.id AS object_mutatie_id,
  om.transactie_id,
  om.sjabloon_uri,
  om.object_uri,
  om.geproduceerde_toestand_id AS tb_id,
  tb.rdf_uri AS tb_uri,
  om.datum_tijd,
  om.data
FROM object_mutaties om
LEFT JOIN toestands_beschrijvingen tb ON tb.id = om.geproduceerde_toestand_id
WHERE om.gegevens_object_in_context_id = :goic_id
ORDER BY om.id DESC
LIMIT 50;
```

Verwachting bij `mutate`:
- 1 mutatie-record met `actie=beeindig_toestand` voor oude TB.
- 1 mutatie-record dat nieuwe TB produceert (`geproduceerde_toestand_id` gevuld).

Verwachting bij `role delete`:
- 1 mutatie-record met `actie=beeindig_toestand`.
- Geen nieuwe `geproduceerde_toestand_id` voor vervangende TB in die delete-stap.

## 3) GraphDB: target TB invalidatie controleren

```sparql
PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>

SELECT ?tb ?invalidatedAt WHERE {
  GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
    VALUES ?tb { <TB_URI> }
    ?tb dpm:invalidatedAtTime ?invalidatedAt .
  }
}
```

## 4) GraphDB: mutatie-events op GOIC controleren

```sparql
PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>

SELECT ?mut ?dt ?tbProduced WHERE {
  GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
    VALUES ?goic { <GOIC_URI> }
    ?mut a vwm:ObjectMutatie ;
         vwm:heeftBetrekkingOp ?goic ;
         vwm:datumTijd ?dt .
    OPTIONAL { ?mut vwm:produceert ?tbProduced . }
  }
}
ORDER BY DESC(?dt)
```

Verwachting bij `mutate`:
- event voor beëindiging oude toestand (zonder `vwm:produceert`).
- event voor nieuwe toestand (met `vwm:produceert` naar nieuwe TB).

Verwachting bij `role delete`:
- beëindig-event zonder nieuwe `vwm:produceert` TB.

## 5) GraphDB: actieve toestanden (zonder invalidatie) bekijken

```sparql
PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
PREFIX dpm: <http://ontologie.politie.nl/def/dpm#>

SELECT ?tb ?goic ?registeredAt WHERE {
  GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
    ?tb vwm:beschrijftGOIC ?goic ;
        vwm:geregistreerdOp ?registeredAt .
    FILTER NOT EXISTS { ?tb dpm:invalidatedAtTime ?x }
  }
}
ORDER BY DESC(?registeredAt)
LIMIT 200
```

## 6) GraphDB: doelclass op GOIC controleren

```sparql
PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>

SELECT ?goic ?class WHERE {
  GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
    VALUES ?goic { <GOIC_URI> }
    OPTIONAL { ?goic vwm:heeftDoelClass ?class . }
  }
}
```

Verwachting bij nieuwe GOIC mutatie:
- `?class` is gevuld met de target class van de mutatie (bijv. `dpm:Person`, `dpm:Incident`, `dpm:Vehicle`).

## 7) GraphDB: ontbrekende doelclass volume-check

```sparql
PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>

SELECT (COUNT(DISTINCT ?goic) AS ?zonderDoelClass) WHERE {
  GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
    ?goic a vwm:GegevensObjectInContext .
    FILTER NOT EXISTS { ?goic vwm:heeftDoelClass ?class . }
  }
}
```
