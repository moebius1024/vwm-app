# Laravel Werkafspraken (VWM)

Gebaseerd op inzichten uit `laravel-up-and-running-3ed` (3e editie), vertaald naar deze codebase.

## 1. Structuur en verantwoordelijkheid

1. Controllers blijven dun: request in, response uit.
2. Businesslogica in services of domeinlagen, niet in views.
3. Data-integriteit primair via database constraints + migrations.

## 2. Routing en controllers

1. Gebruik consistente route-naamgeving (`module.actie`).
2. Houd route-parameters en method-parameters gelijk benoemd.
3. Gebruik route model binding waar het gedrag eenduidig is.
4. Bescherm routes standaard met juiste middleware (`auth`, `verified`, straks rolchecks).

## 3. Validatie

1. Elke write-endpoint valideert server-side.
2. Bij complexere input: aparte Form Request classes.
3. Foutmeldingen altijd gebruikersvriendelijk en technisch herleidbaar (reason codes waar nuttig).

## 4. Database en migrations

1. Elke schemawijziging via migration (no manual drift).
2. Migrations idempotent en rollback-bewust maken.
3. Constrains expliciet: `foreign keys`, `unique`, `not null` waar functioneel vereist.
4. Bij SQLite-specifieke beperkingen: expliciet documenteren in migrationcomment of readme.

## 5. Eloquent en querykeuze

1. Gebruik Eloquent voor relaties en leesbaarheid.
2. Gebruik Query Builder/DB alleen als het duidelijk beter past (performance/bulk/special SQL).
3. Vermijd N+1: eager-load waar nodig.
4. Houd model-relaties semantisch zuiver (zoals `Team -> Medewerker -> Functie`).

## 6. Frontend met Inertia/Vue

1. UI toont alleen functioneel relevante data (technische mutaties verbergen).
2. API-fouten netjes afvangen en betekenisvol tonen.
3. Server-side rendering situaties: absolute URL fallback gebruiken waar nodig.

## 7. Security en autorisatie

1. Authenticatie is niet autorisatie: beide expliciet afdwingen.
2. Autorisatie op basis van keten `user -> medewerker -> functie -> autorisatie_rol`.
3. Beheerfeatures expliciet afschermen op rol (`BEHEER`).

## 8. Tests en regressiepreventie

1. Voor elke bugfix minimaal 1 regressietest (feature/integration).
2. Kritieke paden testen: case-openen, volgen, mutatie opslaan, autorisatie.
3. Bij datamigraties: post-migrate verificatiequery in checklist opnemen.

## 9. Git en repositoryhygiëne

1. Geen lokale documentdump in Git (`/docs/` blijft ignored).
2. Operationele docs in `readme/`.
3. Kleine, betekenisvolle commits met duidelijke boodschap.

## 10. Praktische standaard voor dit project

1. Eerst werkend + correct, dan refactor.
2. Bij twijfel: expliciet maken i.p.v. “magie”.
3. Wijzigingen die data raken altijd verifiëren met directe DB-check.
