<!--
Gabarit de PR (spec 100 § 1). C'est un RAPPEL : la seule autorité est la
CI, et une case cochée ne remplace jamais un test. Cocher « sans objet »
par une mention explicite plutôt que laisser une case vide.
-->

## Lot

<!-- Identifiant du lot (par exemple L100-2) et spec de référence. -->

## Définition de « terminé »

- [ ] **Tests verts** : les tests nommés par la spec du lot existent sous leur nom exact et passent dans leur groupe (`composer ci:check`).
- [ ] **CI verte** : job `ci` vert sur la PR ; job `mysql-redis` vert sur `main` si le lot touche `database/**`, `app/Models/**`, `app/Enums/**`, `app/Settings/**`, `tests/Concurrency/**` ou `tests/Feature/Schema/**`.
- [ ] **FR et EN complets** : `TranslationCoverageTest` vert (symétrie des clés et des paramètres, clés réellement appelées, types de traduction à jour).
- [ ] **États chargement / erreur / déconnexion** : chaque écran du lot rend ses trois états avec les composants de `90` (`EmptyState`, `ErrorState`, bandeau `common.connection.*`) ; pour un écran de jeu, la resynchronisation est prouvée par les tests de `60`.
- [ ] **Parcours clavier vérifié** : tout élément interactif est atteignable et opérable au clavier ; règles de focus de C16 § 2.12.
- [ ] **Gardes du dépôt vertes** : `npm run check` (tokens de thème, règle `[unclassified]`) et tests `Architecture`.
- [ ] **Lot d'exploitation** : la procédure est jouée sur la machine réelle, son résultat consigné et daté dans la spec `100` (§ 10.1, § 13.6, § 16.6).
- [ ] **`.env.example` à jour** si une variable est née, identifiants livrés vides (`CLAUDE.md` §8).
- [ ] **`CLAUDE.md` relu** (D9 du 23/09).
