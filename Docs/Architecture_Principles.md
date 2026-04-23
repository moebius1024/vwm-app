# RDF Architecture & Laravel Integration

## 🎯 Doel van deze applicatie

Deze applicatie combineert:

- Laravel → workflow / proces / schermnavigatie  
- RDF (Graph DB) → inhoud / datamodel / semantiek  

Het systeem is metadata-gedreven en domein-agnostisch in Laravel.

---

## 🧠 Kernprincipes

### 1. Scheiding van verantwoordelijkheden

Laravel
- Workflow engine (user, case, dossier, transaction)
- Schermrouting
- Schermdefinities (Transacties --> Sjablonen)
- Request/response handling
- Orchestratie van queries en opslag
- Autorisatie


RDF (Graph DB)
- Domeinmodel (classes zoals Person, Organization, etc.)
- Eigenschappen (properties zoals lastName, birthDate)
- Sjablonen (gebundelde properties per Class)
- Data (instances / triples)
- Regels (SHACL)

---

### 2. Laravel is domein-agnostisch

Laravel mag geen kennis bevatten van specifieke domeinobjecten, zoals:

- Person
- Address
- lastName
- birthDate

❌ Niet toegestaan:
- PersonController
- findPersonsByLastName()
- if ($class === 'Person')

✅ Wel toegestaan:
- werken met URIs
- generieke resource handling
- metadata-driven gedrag

---

### 3. Runtime kennis vs hardcoded kennis

Laravel verwerkt op runtime:

- classUri (bijv. ex:Person)
- propertyUri (bijv. ex:lastName)
- contextUri
- resourceUri

Maar behandelt deze als data, niet als logica.

---

## 🧩 GOIC Model (Gegevens Object In Context)

Elke registratie resulteert in een GOIC:

- object (resource URI)
- context
- class (rol / type binnen context)
- toestandsbeschrijving 

Voorbeeld:

- object: urn:uuid:123
- class: ex:Person
- context: ex:IntakeContext
- properties: via state toestandsbeschrijving

---

## 🖥️ Schermmodel (RDF)

Schermen worden volledig gedefinieerd in RDF.

### Screen
- bepaalt welke resource class centraal staat
- bevat velden en acties

### Field
- heeft label, input type
- is gebonden aan een property

Voorbeeld:

Screen → Fields → Property bindings

---

## 🔗 Field binding

Een veld is gekoppeld aan een RDF property:

- field → propertyUri
- field → inputType
- field → label

Laravel gebruikt deze binding om:
- forms te renderen
- data op te slaan

---

## ⚙️ Acties (metadata-driven)

Acties zoals zoeken worden gedefinieerd in RDF.

Bijvoorbeeld:
- “zoek resources met dezelfde waarde”

Niet in code:
findPersonsByLastName()

Maar via metadata:
- source field
- target property
- target class
- operator (eq, contains, etc.)

Laravel voert deze generiek uit.

---

## 🔍 Query principe

Alle queries zijn generiek en gebaseerd op:

- resourceClassUri
- propertyUri
- operator
- value

Voorbeeld:

zoek resources waarbij: propertyUri == value

Laravel weet niet dat dit “achternaam” is.

---

## 💾 Opslag principe

Bij submit:

- Laravel ontvangt form data
- vertaalt fields → propertyUri
- schrijft triples

Geen domeinspecifieke opslaglogica.

---

## 🧱 Laravel componenten

Gebruik alleen generieke services:

- MetadataService
- ResourceService
- QueryService
- ActionExecutor
- TripleWriter

❌ Geen:
- PersonService
- AddressService

---

## 🎨 Frontend (Vue + Inertia)

Frontend is volledig dynamisch:

- ontvangt fields + actions via JSON
- rendert generiek
- kent geen domeinobjecten

Componenten:

- DynamicScreen
- DynamicField
- ActionButton
- ResultList

---

## ⚠️ Belangrijke regels

1. Nooit domeinkennis hardcoden in Laravel
2. Alles sturen via RDF metadata
3. URIs behandelen als data
4. Queries generiek houden
5. Scheiding:
   - meta (screen/field)
   - data (instances)

---

## 💡 Ontwerpdoel

Het systeem moet:

- uitbreidbaar zijn zonder codewijzigingen
- nieuwe domeinen ondersteunen via RDF
- schermen dynamisch kunnen aanpassen
- generieke verwerking hebben in Laravel

---

## 🧭 Samenvatting

Laravel = engine  
RDF = betekenis  

Laravel voert uit wat RDF beschrijft.

-