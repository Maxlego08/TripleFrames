# Prototype HTML — TripleFrames

Ouvrir `html/index.html` directement dans un navigateur.

- État visiteur, fond « Rideau pop » par défaut : `html/index.html`
- État connecté : `html/index.html?connected=1`
- Connexion : `html/login.html`
- Inscription : `html/register.html`
- Gestion du compte : `html/account.html`
- Gestion du compte avec e-mail non vérifié : `html/account.html?unverified=1`
- Comment jouer : `html/how-to-play.html`
- Conditions d’utilisation : `html/terms.html`
- Politique de confidentialité : `html/privacy.html`
- Salle d’attente en tant qu’hôte : `html/waiting-room.html?host=1`
- Salle d’attente en tant que joueur : `html/waiting-room.html?host=0&name=Camille&code=M8K2`
- Partie en cours : `html/game.html` ou `html/game.html?name=Camille`
- Pages d’erreur : `html/error-400.html`, `html/error-401.html`,
  `html/error-403.html`, `html/error-404.html`, `html/error-408.html`,
  `html/error-419.html`, `html/error-422.html`, `html/error-429.html`,
  `html/error-500.html`, `html/error-502.html` et `html/error-503.html`

## Organisation

- `html/` : pages de la maquette
- `css/` : CSS compilé, sources SCSS et partiels partagés
- `js/` : interactions JavaScript
- `png/` : captures d’écran des différentes versions
- `svg/` : logo et favicon partagés

Le formulaire de participation valide le pseudo et le code localement. Les
actions affichent des retours de démonstration ; elles ne sont pas branchées au
backend Laravel.

Les formulaires d’authentification utilisent les noms de champs Laravel
(`name`, `email`, `password`, `password_confirmation`, `terms`) et valident les
données côté navigateur. Les boutons Passkey, Google et Discord sont des points
d’entrée de démonstration à relier aux routes WebAuthn/OAuth du backend.

## Styles

Les sources sont écrites en SCSS avec une convention BEM et une palette OKLCH :

- `css/styles.scss` → `css/styles.css`
- `css/auth.scss` → `css/auth.css`
- `css/waiting-room.scss` → `css/waiting-room.css`
- `css/game.scss` → `css/game.css`
- `css/errors.scss` → `css/errors.css`
- `css/account.scss` → `css/account.css`
- `css/info-pages.scss` → `css/info-pages.css`
- `css/scss/_tokens.scss` et `css/scss/_mixins.scss` sont partagés
- `svg/brand-logo.svg` est la source unique du logo et du favicon

Compilation :

Depuis `design-test/` :

`npx sass css/styles.scss:css/styles.css css/auth.scss:css/auth.css css/waiting-room.scss:css/waiting-room.css css/game.scss:css/game.css css/errors.scss:css/errors.css css/account.scss:css/account.css css/info-pages.scss:css/info-pages.css --no-source-map`

La page de compte reprend les réglages Laravel du projet : profil et vérification
de l’e-mail, mot de passe, authentification à deux facteurs, codes de
récupération, passkeys et apparence. Les actions sont interactives dans la
maquette et restent à connecter aux routes Laravel.
