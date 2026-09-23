# Relevé des licences tierces

Le dépôt est privé et **tous droits réservés** (`LICENSE`). Ce relevé trace la licence de chaque **actif** tiers livré avec l'application : une ligne par actif, avec son chemin, son auteur, sa licence, le fichier ou l'en-tête qui porte la licence et, quand la licence l'exige, le **texte d'attribution** (spec `100` § 7.6). La page légale de `90` reprend ces textes à sa publication.

Le relevé des licences des **dépendances** composer et npm relève du jalon 2 (spec `100`, section « Jalon 2 — à écrire », point 12).

Règles, vérifiées par `tests/Feature/Architecture/LicenseTest.php` :

- une ligne d'« Actifs livrés » nomme des chemins qui existent et un porteur de licence qui existe : un fichier, ou l'en-tête `# license:` de chaque fichier nommé ;
- toute ligne sous licence CC BY porte un texte d'attribution non vide ;
- tout fichier de `public/avatars/` et de `public/brand/` est couvert par une ligne d'« Actifs livrés » ;
- une ligne d'« Actifs attendus » passe dans « Actifs livrés » **dans le commit qui livre l'actif** : le test échoue dès qu'un de ses chemins existe alors que sa ligne est encore attendue.

## Actifs livrés

| Chemin | Auteur | Licence | Porteur de la licence | Attribution |
| ------ | ------ | ------- | --------------------- | ----------- |

## Actifs attendus au jalon 1

| Chemin                                                                           | Auteur                                                 | Licence                                                                  | Porteur de la licence                                  | Attribution                                                                                                                                                                                                                                                                                                                                                                                                                                             | Livré par                  |
| -------------------------------------------------------------------------------- | ------------------------------------------------------ | ------------------------------------------------------------------------ | ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------- |
| `public/avatars/preset-*.webp`                                                   | Kenney (https://kenney.nl), pack « Animal Pack Redux » | CC0 1.0, à revérifier au téléchargement                                  | `public/avatars/LICENSE.md`                            | — (non exigée par CC0)                                                                                                                                                                                                                                                                                                                                                                                                                                  | `40`, L40-5 (D27 du 23/09) |
| `resources/moderation/nicknames/en.txt`, `resources/moderation/nicknames/fr.txt` | Shutterstock, Inc., projet LDNOOBW                     | CC BY 4.0, à revérifier au téléchargement                                | en-têtes `# source:` et `# license:` de chaque fichier | « List of Dirty, Naughty, Obscene, and Otherwise Bad Words » (listes anglaise et française), © Shutterstock, Inc., https://github.com/LDNOOBW/List-of-Dirty-Naughty-Obscene-and-Otherwise-Bad-Words, sous licence Creative Commons Attribution 4.0 International (https://creativecommons.org/licenses/by/4.0/). Fourni en l'état, sans garantie. Modifié : les entrées retirées ou ajoutées sont déclarées par `# changes:` en tête de chaque fichier. | `40`, L40-4                |
| `public/brand/tmdb.svg`                                                          | The Movie Database (TMDB)                              | conditions d'usage des logos TMDB, recopiées et datées à la récupération | `public/brand/LICENSE.md`                              | mention exigée par les conditions d'utilisation de l'API TMDB, rendue à côté du logo par `90` : « This product uses the TMDB API but is not endorsed or certified by TMDB. » (texte à revérifier à la récupération du logo)                                                                                                                                                                                                                             | `90`, L90-3 (n° 72)        |

## Points ouverts, signalés au porteur

- `public/favicon.ico`, `public/favicon.svg` et `public/apple-touch-icon.png` sont les icônes héritées du starter `laravel/react-starter-kit` (logo Laravel). Ce sont des actifs tiers qu'aucune spec ne relève : à remplacer par une icône du projet, ou à relever ici avec leur licence, avant l'ouverture publique.
