# SPARQL Checklist (Kort)

1. Prefixes kloppen en zijn volledig (`rdf`, `rdfs`, `xsd`, `dpm`, `vwm`).
2. Juiste graph gekozen (`ontologie`, `shapes`, `onderzoek/data`).
3. Eerst `SELECT`-dry-run met exact dezelfde `WHERE` als de update.
4. `COUNT` gecontroleerd op impactvolume.
5. `INSERT DATA` gebruikt als geen variabelen nodig zijn.
6. Bij vervanging: `DELETE/INSERT` in één request met identieke scope.
7. Datatypes gecontroleerd (`xsd:date`, `xsd:dateTime`, `xsd:integer`, etc.).
8. Geen brede `DELETE` zonder extra filter (`VALUES`, subject, graph).
9. Na update direct verificatiequery gedraaid en resultaat gecontroleerd.
10. Query + effect kort vastgelegd (commit/issue/notitie).
