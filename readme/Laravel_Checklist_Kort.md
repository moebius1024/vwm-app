# Laravel Checklist (Kort)

1. Route + middleware klopt (`auth/verified/rol`).
2. Input validatie aanwezig en compleet.
3. DB constraints dekken de regel (`FK`, `unique`, `not null`).
4. Controller is dun; logica zit niet verspreid in view/controller.
5. Relaties in models kloppen met het domeinmodel.
6. Geen N+1-risico bij lijsten/detailweergaves.
7. Foutafhandeling geeft bruikbare melding (user + debugbaar).
8. Migration getest op actuele SQLite setup.
9. Gevolgde datawijziging geverifieerd met query/check.
10. Git status schoon; geen lokale rommel of `docs/` in commit.
