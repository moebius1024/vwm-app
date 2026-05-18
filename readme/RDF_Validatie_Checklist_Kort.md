# RDF Validatie Checklist (Kort)

1. Juiste data graph gekozen?
2. Juiste shapes graph actief?
3. `SELECT` preview gedraaid vóór update?
4. Datatypes kloppen met SHACL (`xsd:*`)?
5. Verplichte velden (`minCount`) ingevuld?
6. URI/literal nodeKind correct?
7. SHACL report gelezen op `focusNode/resultPath/component/value`?
8. Geen onbedoelde graph-clear/drop uitgevoerd?
9. Post-update verificatiequery gelogd?
10. UI-melding voor validatiefouten begrijpelijk?
