# Verbeterpunten

Dit document verzamelt verbeterpunten die inhoudelijk zijn vastgesteld, maar nog niet direct worden uitgevoerd.

## Ontologie: rolrelaties en GOIC-laag scheiden

### Observatie

In de huidige ontologie verwijzen rolproperties zoals `vwm:heeftPersoon` naar `vwm:GegevensObjectInContext`.

Voorbeeld:

```ttl
vwm:heeftPersoon
  rdfs:domain vwm:RolBeschrijving ;
  rdfs:range vwm:GegevensObjectInContext .
```

Dit is functioneel verklaarbaar vanuit de applicatie, omdat rollen operationeel gekoppeld worden aan een GOIC binnen een dossier/context. Ontologisch is dit echter minder zuiver: de propertynaam `heeftPersoon` suggereert een relatie naar de domeinclass `dpm:Person`, terwijl de range een proces/context-object is.

### Waarom dit wringt

Bij gewone toestandsbeschrijvingen is het patroon:

```text
TB-class -> beschrijftClass -> domeinclass
TB-instantie -> beschrijftGOIC -> GOIC
GOIC -> heeftDoelClass -> domeinclass
```

De TB-class verwijst dus naar de domeinclass, en pas de instantie verwijst naar een GOIC. Rolproperties wijken hiervan af doordat de ontologische property zelf naar GOIC verwijst.

### Verbeterpunt

Onderzoek een model waarbij domeinsemantiek en GOIC-proceslaag explicieter gescheiden zijn.

Mogelijke richting:

- Semantische rolregel blijft uitdrukken dat een rol gaat van `dpm:Person` naar bijvoorbeeld `dpm:Vehicle` of `dpm:Incident`.
- Operationele vastlegging verwijst naar GOIC's met expliciete GOIC-properties, bijvoorbeeld `vwm:heeftPersoonGOIC`, `vwm:heeftVoertuigGOIC` of generieker `vwm:rolBronGOIC` en `vwm:rolDoelGOIC`.
- De rolregel blijft bepalen welke domeinclass de bron- en doel-GOIC moeten representeren.

### Doel

De ontologie wordt daardoor consistenter met het bestaande TB-patroon:

- domeinclasses blijven domeinclasses;
- GOIC blijft de context/proceslaag;
- properties maken expliciet of zij naar domeinobjecten of naar GOIC's verwijzen.

### Let op

Dit is een modelwijziging met impact op:

- `ontology/statements.ttl`
- `ontology/shapes-process.ttl`
- rolmutatiecode
- bestaande data in GraphDB
- UI-weergave van rolrelaties

Niet uitvoeren zonder apart plan, SPARQL dry-run en regressietests.
