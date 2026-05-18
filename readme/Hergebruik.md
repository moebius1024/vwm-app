# Hergebruik Afspraken (VWM)

Status: afspraken vastgesteld; situatie 2 (GOIC-niveau) is al geimplementeerd.

## Doel

Vastleggen hoe hergebruik bedoeld is in twee expliciete varianten, zodat:
- het model eenduidig blijft;
- uitzonderingen (GOIC zonder eigen kern-TB) expliciet en valide zijn;
- lifecycle/cascade voorspelbaar is.

## Situatie 1: Volgen op GO-niveau (afspraak)

Bij hergebruik via GO-niveau maken we in het doeldossier een nieuwe GOIC die:
1. gekoppeld is aan dezelfde GO (`beschrijftGO`);
2. daarnaast een expliciete follow-relatie naar die GO heeft (werknaam: `volgtGO` / GO-association).

Belangrijk:
- dit is **1 associatie naar de GO** (niet naar een set bron-GOIC's);
- daardoor komen ook later toegevoegde GOIC's op diezelfde GO automatisch in beeld.

### Semantiek
- `beschrijftGO`: structurele identiteitskoppeling (stabiel).
- `volgtGO`: dynamische hergebruik-relatie met lifecycle (kan geinvalideerd worden).

### Geldigheid zonder eigen kern-TB
Een GOIC zonder eigen kern-TB is normaal ongeldig, **behalve** als er een actieve `volgtGO`-association bestaat.

### Lifecycle
Als op het gevolgde GO geen actieve kern-TB's meer bestaan (in relevante broncontexten),
dan verliest `volgtGO` haar bestaansgrond en moet deze geinvalideerd kunnen worden.

## Situatie 2: Volgen op GOIC-niveau (huidige implementatie)

Hier volgt de nieuwe GOIC een **specifieke bron-GOIC**.

### API
- `POST /api/goic/volg`
- `POST /api/goic/volg-incident` (alias naar dezelfde handler)

Beide routes gaan naar `MutatieController@volgGoic`.

### Gedrag in code (nu)
1. Request accepteert exact 1 `bron_goic_uri`.
2. Er wordt bron-metadata uit GraphDB gelezen en de bijbehorende GO bepaald.
3. In het doeldossier wordt altijd een **nieuwe GOIC** aangemaakt.
4. Die nieuwe GOIC wordt aan dezelfde GO gekoppeld via `vwm:beschrijftGO`.
5. Er wordt een `dpm:DataObjectAssociation` vastgelegd met:
   - `dpm:ownedObject = nieuwe GOIC`
   - `dpm:targetObject = bron_goic_uri`
6. Zowel SQLite (audit) als GraphDB worden in dezelfde flow geschreven.

### Betekenis
- Functioneel is dit nu volgen van een specifieke bron-GOIC.
- Duplicaatpreventie gebeurt nu op combinatie `(case_id, bron_goic_uri)`.
- De keuze om altijd een nieuwe GOIC te maken voor volgen is al gerealiseerd.

## Situatie 3: Verwijzen naar 1 specifieke TB met automatisch doorzetten

Situatie 3 is gelijk aan situatie 4, met 1 verschil: bij een logische update van de bron wordt de afhankelijkheid automatisch doorgezet.

Werkwijze:
1. Een Afhankelijke TB verwijst via een expliciete **TB -> TB** relatie naar 1 bron-TB.
2. Als de bron-TB wordt geinvalideerd, wordt de Afhankelijke TB ook geinvalideerd.
3. Als er direct een opvolgende bron-TB ontstaat (logische update), dan wordt automatisch:
   - een nieuwe Afhankelijke TB geregistreerd;
   - met verwijzing naar die nieuwe bron-TB.

Resultaat:
- de afhankelijkheid blijft semantisch geldig over versies heen;
- handmatige tussenstap voor de gebruiker is niet nodig in dit scenario.

## Situatie 4: Verwijzen naar 1 specifieke TB vanuit bestaande GOIC

Doel: een bestaande GOIC laten steunen op exact 1 concrete toestand (TB) uit een andere context, zonder GO/GOIC-volgen op setniveau.

Werkwijze:
1. Bestaande GOIC blijft bestaan.
2. In die GOIC wordt een specifieke **Afhankelijke TB** aangemaakt.
3. Deze Afhankelijke TB legt een expliciete **TB -> TB** relatie naar precies 1 bron-TB
   (waarbij die bron-TB hoort bij een GOIC die aan dezelfde GO gekoppeld is).

Lifecycle/cascade:
1. Als de bron-TB wordt geinvalideerd, dan wordt de Afhankelijke TB automatisch ook geinvalideerd.
2. De gebruiker van de Afhankelijke TB krijgt daarna een melding met keuze:
   - wil je koppelen aan de nieuwe (opvolgende) TB?
   - of wil je de afhankelijkheid stoppen?

Opmerking:
- Deze situatie is conceptueel vastgesteld; implementatie volgt later.

## Verschil tussen situatie 1 en 2

- Situatie 1 (GO-niveau): koppeling richt zich op de GO als geheel.
- Situatie 2 (GOIC-niveau): koppeling richt zich op 1 specifieke bron-GOIC.
- Situatie 1 is nu afspraak/doelbeeld; situatie 2 is nu operationeel in code.

## Nog uit te werken (later)

1. Ontologie:
   - definitieve naamgeving van GO-niveau follow-relatie/association.
2. SHACL:
   - validatieregel voor GOIC zonder kern-TB maar met actieve GO-niveau follow.
3. Migratiepad:
   - keuze: situatie 2 handhaven naast situatie 1, of volledig naar situatie 1.
4. UI:
   - zichtbare status van type volgen (GO of GOIC).
5. Audit:
   - eenduidige actiecodes en verificatiequeries voor follow-start/follow-stop.
