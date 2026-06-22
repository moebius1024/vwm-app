# Verbeterpunten

Dit document verzamelt verbeterpunten die inhoudelijk zijn vastgesteld, maar nog niet direct worden uitgevoerd.

## Ontologie: rol-TB als gecontextualiseerde uitspraak tussen GO's

### Status

Dit verbeterpunt is conceptueel uitgewerkt, maar wordt nu niet geimplementeerd. De wijziging raakt ontologie, SHACL, bestaande GraphDB-data, auditdata, rolmutatiecode en UI-weergave en vereist daarom een afzonderlijk implementatie- en migratieplan.

### Begrippen

- **GO (GegevensObject)**: het generieke object in de werkelijkheid waarover uitspraken worden gedaan.
- **GOIC (GegevensObjectInContext)**: een contextgebonden bundeling van uitspraken over een GO.
- **TB (ToestandsBeschrijving)**: een vastgelegde toestand of uitspraak binnen een context.
- **Rol-TB**: een TB die een contextgebonden roluitspraak vastlegt, bijvoorbeeld dat een persoon bestuurder is van een bestuurbaar object.

Een GO heeft formeel niet noodzakelijk zelf een domeintype zoals `dpm:Person` of `dpm:Vehicle`. Het bestaande patroon is:

```ttl
<persoonGoic> a vwm:GegevensObjectInContext ;
  vwm:beschrijftGO <persoonGo> ;
  vwm:heeftDoelClass dpm:Person .

<voertuigGoic> a vwm:GegevensObjectInContext ;
  vwm:beschrijftGO <voertuigGo> ;
  vwm:heeftDoelClass dpm:Vehicle .

<persoonGo> a vwm:GegevensObject .
<voertuigGo> a vwm:GegevensObject .
```

`persoonGo` en `voertuigGo` zijn dus informele aanduidingen. Formeel zijn het generieke GO's. Dat een GO in een context als `Person` of `Vehicle` wordt beschouwd, volgt uit de kwalificatie op de betreffende GOIC.

### Huidige modellering

De huidige rolregel selecteert een bron- en doel-GOIC op basis van hun doelclass. De rol-TB verwijst vervolgens rechtstreeks naar beide GOIC's:

```ttl
<bestuurderRolTb> a vwm:PersoonVoertuigRol ;
  vwm:heeftPersoon <persoonGoic> ;
  vwm:heeftVoertuig <voertuigGoic> ;
  vwm:rolType vwm:RolType_Bestuurder .
```

De properties zijn momenteel als volgt gedefinieerd:

```ttl
vwm:heeftPersoon
  rdfs:domain vwm:RolBeschrijving ;
  rdfs:range vwm:GegevensObjectInContext .

vwm:heeftVoertuig
  rdfs:domain vwm:PersoonVoertuigRol ;
  rdfs:range vwm:GegevensObjectInContext .
```

`RoleMutationWriter` schrijft daardoor feitelijk een relatie tussen twee GOIC's. Een GOIC is echter een bundeling van contextgebonden uitspraken, niet het object in de werkelijkheid dat de rol vervult.

Daarnaast krijgt een rol-TB in de huidige GraphDB-vastlegging niet dezelfde expliciete `vwm:beschrijftGOIC`-relatie als gewone TB's. Daarmee wijkt de rol-TB af van het normale TB-patroon.

### Waarom dit wringt

De uitspraak:

> De persoon in deze registratie is bestuurder van het voertuig in die registratie.

betekent semantisch nauwkeuriger:

> De GO die via een GOIC als `Person` is gekwalificeerd, is bestuurder van de GO die via een GOIC als `Vehicle` of algemener als bestuurbaar object is gekwalificeerd.

De relatie `is bestuurder van` bestaat dus inhoudelijk tussen de twee GO's. De GOIC's leveren:

- de context waarin de GO's zijn geregistreerd;
- de kwalificatie als bijvoorbeeld `Person` en `Vehicle`;
- de toestanden waarop de gebruiker zijn keuze baseert;
- de herkomst/provenance van de roluitspraak.

Een directe rolrelatie tussen twee GOIC's maakt de semantische uitspraak onnodig afhankelijk van twee specifieke bundelingen van uitspraken. Als dezelfde GO later in een andere context door een andere GOIC wordt beschreven, lijkt de rol ten onrechte alleen aan de oorspronkelijke doel-GOIC vast te zitten.

### Gewenste semantiek

De rol-TB is een gereificeerde, contextgebonden uitspraak over GO's. Zij blijft nodig omdat een directe triple tussen GO's onvoldoende ruimte biedt voor:

- registratietijd;
- context en dossier;
- roltype;
- audit en producerende mutatie;
- logische invalidatie;
- eventuele bronverantwoording.

Conceptueel:

```text
GOIC (gekwalificeerd als Person)
  -> rol-TB (Bestuurder)
  -> GO (in andere GOIC gekwalificeerd als Vehicle/bestuurbaar object)
```

De richting van `vwm:beschrijftGO` blijft formeel:

```text
GOIC -> beschrijftGO -> GO
```

Daarom kan de volledige samenhang als volgt worden weergegeven:

```text
PersoonGOIC -> beschrijftGO -> PersoonGO
RolTB       -> beschrijftGOIC -> PersoonGOIC
RolTB       -> rolType -> Bestuurder
RolTB       -> rolObjectGO -> VoertuigGO
VoertuigGOIC -> beschrijftGO -> VoertuigGO
```

### Mogelijke RDF-vastlegging

Voorkeursrichting waarbij het subject via het normale TB-patroon wordt bepaald:

```ttl
<bestuurderRolTb> a vwm:PersoonVoertuigRol ;
  vwm:beschrijftGOIC <persoonGoic> ;
  vwm:rolType vwm:RolType_Bestuurder ;
  vwm:rolObjectGO <voertuigGo> ;
  vwm:geregistreerdOp "..."^^xsd:dateTime .
```

De subject-GO wordt dan afgeleid via:

```ttl
<persoonGoic> vwm:beschrijftGO <persoonGo> .
```

Een explicietere variant legt beide zijden van de gereificeerde uitspraak vast:

```ttl
<bestuurderRolTb> a vwm:PersoonVoertuigRol ;
  vwm:beschrijftGOIC <persoonGoic> ;
  vwm:rolSubjectGO <persoonGo> ;
  vwm:rolObjectGO <voertuigGo> ;
  vwm:rolType vwm:RolType_Bestuurder .
```

De expliciete `rolSubjectGO` is mogelijk redundant, maar kan validatie en uitlegbaarheid vereenvoudigen. Bij uitwerking moet worden besloten of deze redundantie gewenst is.

Als provenance naar de geselecteerde doelregistratie nodig blijft, kan die afzonderlijk en expliciet worden vastgelegd, bijvoorbeeld:

```ttl
<bestuurderRolTb> vwm:gebaseerdOpDoelGOIC <voertuigGoic> .
```

Deze provenance-property is dan niet de inhoudelijke rolrelatie. De semantische objectzijde blijft de GO.

### Rolregels en validatie

De rolregel blijft metadata-gedreven bepalen welke kwalificaties zijn toegestaan. Voor `Bestuurder` bijvoorbeeld:

```text
bron-GOIC heeftDoelClass Person
doel-GOIC heeftDoelClass Vehicle of BestuurbaarObject
roltype is Bestuurder
```

Bij registratie wordt uit beide geselecteerde GOIC's hun GO bepaald via `vwm:beschrijftGO`. De rol-TB wordt aan de bron-GOIC gekoppeld en verwijst inhoudelijk naar de doel-GO.

SHACL moet onder andere controleren:

1. de rol-TB beschrijft exact een geldige bron-GOIC;
2. de bron-GOIC verwijst naar exact een GO;
3. de bron-GOIC heeft een voor het roltype toegestane doelclass;
4. de rol-TB verwijst naar exact een doel-GO;
5. er bestaat een relevante doel-GOIC die deze doel-GO met een toegestane class kwalificeert;
6. een optionele provenance-GOIC beschrijft daadwerkelijk dezelfde doel-GO;
7. actieve en geinvalideerde rol-TB's worden correct onderscheiden.

### Voorbeeld Bestuurder

```ttl
<goicPersoon42> a vwm:GegevensObjectInContext ;
  vwm:beschrijftGO <go123> ;
  vwm:heeftDoelClass dpm:Person .

<goicVoertuig17> a vwm:GegevensObjectInContext ;
  vwm:beschrijftGO <go987> ;
  vwm:heeftDoelClass dpm:Vehicle .

<rolTb55> a vwm:PersoonVoertuigRol ;
  vwm:beschrijftGOIC <goicPersoon42> ;
  vwm:rolType vwm:RolType_Bestuurder ;
  vwm:rolObjectGO <go987> ;
  vwm:gebaseerdOpDoelGOIC <goicVoertuig17> .
```

De betekenis is:

> GO `<go123>`, in deze context als `Person` gekwalificeerd, is bestuurder van GO `<go987>`, die via `<goicVoertuig17>` als `Vehicle` is gekwalificeerd.

Er wordt nadrukkelijk niet beweerd:

```ttl
<go987> rdf:type dpm:Vehicle .
```

De class-kwalificatie blijft contextgebonden via de GOIC.

### Gevolgen en open ontwerpvragen

1. **Subjectmodellering**
   - Is `vwm:beschrijftGOIC` voldoende om de subject-GO af te leiden?
   - Of leggen we daarnaast expliciet `vwm:rolSubjectGO` vast?

2. **Doelprovenance**
   - Is alleen de doel-GO voldoende?
   - Of moet ook worden vastgelegd op welke doel-GOIC/kwalificatie de uitspraak bij registratie was gebaseerd?

3. **Lifecycle van kwalificaties**
   - Blijft een roluitspraak geldig als de kwalificerende doel-TB of doel-GOIC later geen actieve kern-TB meer heeft?
   - Moet de rol-TB dan automatisch worden geinvalideerd of alleen worden gemarkeerd voor beoordeling?

4. **Directe domeintriple**
   - Moet uit de actieve rol-TB een directe relatie zoals `<persoonGo> dpm:isDriverOf <voertuigGo>` worden afgeleid?
   - Zo ja, dan moet duidelijk blijven dat deze afleiding context- en tijdgebonden is en vervalt bij invalidatie van de rol-TB.

5. **Generieke versus rol-specifieke properties**
   - Generiek: `vwm:rolSubjectGO` en `vwm:rolObjectGO`.
   - Rol-specifiek: bijvoorbeeld `vwm:isBestuurderVan`.
   - De keuze moet metadata-gedreven blijven en mag geen domeinkennis in Laravel introduceren.

### Verwachte impact

Dit is een brede modelwijziging met ten minste impact op:

- `ontology/statements.ttl` voor properties, domains, ranges en labels;
- `ontology/shapes-process.ttl` voor bron-/doelclass en propertymetadata;
- mogelijk `ontology/shapes-domain.ttl` en de gecombineerde `ontology/shapes.ttl`;
- `RoleMutationService` voor het resolven van bron- en doel-GO uit geselecteerde GOIC's;
- `RoleMutationWriter` voor `beschrijftGOIC` en GO-gerichte roltriples;
- `StateDeletionService` en cascade-/invalidatieregels;
- raadpleegqueries en UI-weergave van rollen;
- SQLite-auditdata in `object_mutaties.data`;
- bestaande rol-TB's en roltriples in GraphDB;
- regressietests voor registreren, raadplegen, muteren en verwijderen van rollen.

### Migratieaandachtspunten

Een eventuele migratie mag bestaande rol-TB-URI's en auditgeschiedenis niet verliezen. Voor iedere actieve en historische rol-TB moet worden onderzocht:

1. welke bron-GOIC nu via `vwm:heeftPersoon` is vastgelegd;
2. welke bron-GO via `vwm:beschrijftGO` bij die GOIC hoort;
3. welke doel-GOIC via `vwm:heeftVoertuig` of `vwm:heeftIncident` is vastgelegd;
4. welke doel-GO via `vwm:beschrijftGO` bij die doel-GOIC hoort;
5. welke nieuwe triples veilig kunnen worden toegevoegd;
6. welke oude triples pas na verificatie kunnen worden verwijderd;
7. hoe geinvalideerde rol-TB's historisch correct behouden blijven.

Uitvoering vereist minimaal:

- `SELECT` dry-runs volgens `readme/SPARQL_Werkafspraken.md`;
- impact- en volumechecks;
- representatieve SHACL-validatie voor en na de wijziging;
- verificatie van SQLite en GraphDB;
- een gefaseerde migratie met mogelijkheid tot terugval;
- regressietests en een gerichte live test.

Niet uitvoeren als onderdeel van een gewone refactor. Eerst een afzonderlijk ontwerpbesluit en implementatieplan vaststellen.
