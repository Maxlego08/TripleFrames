# Prototype HTML — accueil TripleFrames

Ouvrir `index.html` directement dans un navigateur.

- État visiteur, fond « Rideau pop » par défaut : `index.html`
- État connecté : `index.html?connected=1`
- Comparateur des cinq fonds : `backgrounds.html`

Variantes directes :

- `index.html?bg=party`
- `index.html?bg=spotlight`
- `index.html?bg=frames`
- `index.html?bg=curtain`
- `index.html?bg=arcade`

Le formulaire de participation valide le pseudo et le code localement. Les
actions affichent des retours de démonstration ; elles ne sont pas branchées au
backend Laravel.

## Styles

Les sources sont écrites en SCSS avec une convention BEM et une palette OKLCH :

- `styles.scss` → `styles.css`
- `backgrounds.scss` → `backgrounds.css`
- `scss/_tokens.scss` et `scss/_mixins.scss` sont partagés

Compilation : `npx sass styles.scss styles.css && npx sass backgrounds.scss backgrounds.css`.
