# Prototype HTML — accueil TripleFrames

Ouvrir `index.html` directement dans un navigateur.

- État visiteur, fond « Rideau pop » par défaut : `index.html`
- État connecté : `index.html?connected=1`
- Connexion : `login.html`
- Inscription : `register.html`
- Salle d’attente en tant qu’hôte : `waiting-room.html?host=1`
- Salle d’attente en tant que joueur : `waiting-room.html?host=0&name=Camille&code=M8K2`

Le formulaire de participation valide le pseudo et le code localement. Les
actions affichent des retours de démonstration ; elles ne sont pas branchées au
backend Laravel.

Les formulaires d’authentification utilisent les noms de champs Laravel
(`name`, `email`, `password`, `password_confirmation`, `terms`) et valident les
données côté navigateur. Les boutons Passkey, Google et Discord sont des points
d’entrée de démonstration à relier aux routes WebAuthn/OAuth du backend.

## Styles

Les sources sont écrites en SCSS avec une convention BEM et une palette OKLCH :

- `styles.scss` → `styles.css`
- `auth.scss` → `auth.css`
- `waiting-room.scss` → `waiting-room.css`
- `scss/_tokens.scss` et `scss/_mixins.scss` sont partagés
- `brand-logo.svg` est la source unique du logo et du favicon

Compilation :

`npx sass styles.scss:styles.css auth.scss:auth.css --no-source-map`
