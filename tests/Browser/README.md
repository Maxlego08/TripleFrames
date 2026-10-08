# Parcours Playwright — préproduction

Spec `100` § 19 (L100-16). Trois parcours de bout en bout, joués **à la main, depuis le poste, contre la préproduction**, avant chaque promotion. Jamais en CI, jamais dans `composer ci:check` : la configuration refuse de partir sous `CI`, et n'admet qu'une cible `preprod.*` en HTTPS (ou une machine locale pour la mise au point).

## Une fois par poste

```bash
npm ci
npx playwright install chromium
```

`.npmrc` interdit les scripts d'installation : le navigateur ne se télécharge jamais seul.

## Avant chaque promotion

Variables **du poste**, jamais dans le dépôt ni dans `.env.example` :

```bash
export E2E_BASE_URL=https://preprod.<DOMAINE>
export E2E_HTTP_USER=<identifiant de l'authentification HTTP>
export E2E_HTTP_PASSWORD=<mot de passe, lu sans écho>
npm run test:e2e
```

Chaque parcours tourne deux fois : `mobile-360` (360 × 640, clavier ouvert simulé pendant la saisie) et `desktop`. Les six doivent être verts **sur le commit déployé en préproduction** ; en cas d'échec, la trace est dans `storage/framework/testing/playwright/` (`npx playwright show-trace …`) et le rapport dans `storage/framework/testing/playwright-report/`.

Puis : GitHub › Actions › `promote`, avec ce commit et la case « parcours joués » cochée (`ops/mise-en-service.md` § 12 f).

## Les trois parcours et leurs écrans

| Parcours                     | Écrans traversés                                                                                           | Ce qui est prouvé                                                                                                                                 |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `create-join-launch.spec.ts` | `room/create`, `game/lobby` (hôte), `room/join` par `/r/{code}`, `game/lobby` (invité), décompte, manche 1 | création au pseudo seul, `noindex` sur l'URL du salon, présence au lobby par Reverb, lancement, même manche chez les deux                         |
| `answer-lock-reveal.spec.ts` | lobby, manche 1 (saisie libre, Normal), écran « trouvé », liste des joueurs, révélation                    | verrouillage du premier bon répondant, « Found it » chez l'observateur **sans le titre**, manche continuée jusqu'à la révélation du titre accepté |
| `reconnect.spec.ts`          | lobby, manche 1, rechargement en pleine manche, révélation                                                 | siège repris sans formulaire, même manche, saisie rouverte, révélation au même instant serveur que chez l'hôte                                    |

La préproduction porte le **catalogue de démonstration** (spec `100` § 18) : le parcours 2 trouve la réponse en proposant ses titres (`support/demo-titles.ts`, gardé égal au seeder par `BrowserJourneysTest`), jamais par une lecture de la charge utile, qui ne la porte pas (règle 3). L'interface est forcée en anglais (cookie `locale`) : les sélecteurs suivent rôles et noms accessibles de `lang/en/*.php`, rappelés dans `support/players.ts`.
