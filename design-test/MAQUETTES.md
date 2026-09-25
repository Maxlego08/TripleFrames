# Maquettes d'interface

Ce dossier contient les essais de design du porteur : pages HTML/CSS/JS autonomes et leurs captures.

Règles, vérifiées par `tests/Feature/Architecture/NoRealFixtureTest.php` (spec `100` § 7.3) :

- **Aucune image de film**, aucun photogramme, aucun visuel TMDB, aucun extrait de base de production. Seules des captures de maquettes d'interface y entrent.
- Ses images ne sont autorisées dans le dépôt que tant que ce fichier est suivi à côté d'elles.
- Le dossier est exclu du formateur et du lint (`vite.config.ts`) : ce n'est pas du code de l'application.
