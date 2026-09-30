<?php

namespace App\Support\Curation;

use App\Enums\FrameLevel;
use App\Enums\ReviewDecision;
use InvalidArgumentException;

/**
 * La grille d'exclusion de curation — versionnée EN CODE, sans aucune table
 * (spec 20 § 7.1 et § 7.2, contrat C14-bis).
 *
 * Une grille éditable en base permettrait de réécrire le libellé d'un item qu'une
 * revue a déjà cité, ce qui détruirait sa valeur de preuve. Le schéma ne fournit
 * que le NUMÉRO DE VERSION (`frame_review.grid_version`, `frame.review_grid_version`)
 * et le stockage des réponses par SLUG (`frame_review.answers`).
 *
 * Chaque version passée reste un tableau FIGÉ du dépôt, item par item, chaque item
 * portant un slug stable : c'est par ce slug que les réponses d'une revue sont
 * clées, donc relisibles sans le code de la version d'alors. **Une version publiée
 * ne se modifie jamais** — items, niveaux et drapeau `retroactive` compris : on en
 * ajoute une, avec un slug nouveau, et `frame.review_grid_version` comparée à
 * {@see self::CURRENT_VERSION} produit la file « images à re-revoir ». Un test
 * verrouille l'empreinte SHA-256 de la version 1.
 *
 * **Drapeau `retroactive`** (D13 du 23/09) : faux par défaut, vrai SEULEMENT pour
 * une version qui ajoute un item pour motif JURIDIQUE, cité en commentaire de la
 * version avec la référence de la demande de retrait s'il y en a une. Une montée
 * de version est prospective par défaut : rien ne sort du jeu, et une
 * clarification de libellé ne vide pas le vivier.
 *
 * Aucun libellé humain ici : chaque item n'expose que deux CLÉS de traduction du
 * domaine `admin`, gelées avec sa version ({@see self::labelKey()},
 * {@see self::helpKey()}) — `admin.exclusion_grid.v{n}.{slug}.label` et `.help`,
 * forme R-47 : dans un tableau de langue PHP, une clé ne peut pas être à la fois
 * une feuille et un nœud.
 *
 * Le contenu item par item appartient à `20-back-office-curation.md` § 7.1 ; la
 * version 1 reprend la décision de `questions-ouvertes.md` révisée le 22/09.
 */
final readonly class ExclusionGrid
{
    /**
     * Version courante, comparée à `frame_review.grid_version` et à
     * `frame.review_grid_version` : la plus grande clé de {@see self::VERSIONS}.
     * Bornée par `unsignedTinyInteger`.
     */
    public const int CURRENT_VERSION = 1;

    /** Préfixe des clés de traduction, gelé avec la version (domaine `admin`, spec 20 § 13.2). */
    private const string LANG_PREFIX = 'admin.exclusion_grid.';

    /**
     * Toutes les versions publiées, figées. `levels` énumère les `frame_level`
     * auxquels l'item s'applique ; `retroactive` dit si la version ajoute un
     * item pour motif juridique (§ 7.2).
     *
     * @var array<int, array{retroactive: bool, items: list<array{slug: string, levels: list<int>}>}>
     */
    private const array VERSIONS = [
        // Version 1 — la grille du jalon 1, non rétroactive : aucune revue
        // n'existait avant elle.
        1 => [
            'retroactive' => false,
            'items' => [
                // Ni affiche, ni jaquette : une reproduction d'un visuel
                // promotionnel n'est pas un photogramme transformé.
                ['slug' => 'no_poster_or_cover', 'levels' => [1, 2, 3, 4, 5]],

                // Pas de carton-titre.
                ['slug' => 'no_title_card', 'levels' => [1, 2, 3, 4, 5]],

                // Pas de logo de studio.
                ['slug' => 'no_studio_logo', 'levels' => [1, 2, 3, 4, 5]],

                // Pas de générique, de début comme de fin.
                ['slug' => 'no_credits', 'levels' => [1, 2, 3, 4, 5]],

                // Aucun texte qui NOMME ou IDENTIFIE le film — titre,
                // sous-titre, nom de saga, accroche, crédits —, quelle que
                // soit l'écriture. Un texte de décor sans rapport avec le
                // titre reste autorisé, sans quoi le corpus japonais et le
                // corpus d'exception deviendraient impubliables.
                ['slug' => 'no_identifying_text', 'levels' => [1, 2, 3, 4, 5]],

                // Aucun filigrane ni mention de copyright incrustée.
                ['slug' => 'no_watermark_or_copyright', 'levels' => [1, 2, 3, 4, 5]],

                // Aucune incrustation de bande-annonce ni de diffuseur.
                ['slug' => 'no_broadcaster_or_trailer_overlay', 'levels' => [1, 2, 3, 4, 5]],

                // Aucune photo de plateau ni portrait promotionnel d'acteur :
                // droit à l'image, distinct du droit d'auteur sur l'œuvre.
                ['slug' => 'no_promotional_still_or_portrait', 'levels' => [1, 2, 3, 4, 5]],

                // Pas de visage du personnage principal — SEULS les niveaux 1
                // et 2, parce que c'est une règle de cryptage de la manche et
                // non une règle de conformité : un plan iconique de niveau 5
                // le montre forcément.
                ['slug' => 'no_lead_face', 'levels' => [1, 2]],
            ],
        ],
    ];

    /**
     * Versions publiées, de la plus ancienne à la plus récente.
     *
     * @return list<int>
     */
    public static function versions(): array
    {
        return array_keys(self::VERSIONS);
    }

    public static function isKnownVersion(int $version): bool
    {
        return array_key_exists($version, self::VERSIONS);
    }

    /**
     * Tous les slugs d'une version, dans l'ordre d'affichage de la passe de revue.
     *
     * @return list<string>
     */
    public static function slugs(int $version = self::CURRENT_VERSION): array
    {
        return self::slugsOf(self::itemsIn(self::VERSIONS, $version));
    }

    /**
     * Slugs applicables à un niveau d'image donné, dans l'ordre d'affichage :
     * exactement les clés qu'une revue de ce niveau doit porter.
     *
     * @return list<string>
     */
    public static function slugsFor(FrameLevel $level, int $version = self::CURRENT_VERSION): array
    {
        $slugs = [];

        foreach (self::itemsIn(self::VERSIONS, $version) as $item) {
            if (in_array($level->value, $item['levels'], true)) {
                $slugs[] = $item['slug'];
            }
        }

        return $slugs;
    }

    public static function appliesTo(string $slug, FrameLevel $level, int $version = self::CURRENT_VERSION): bool
    {
        return in_array($slug, self::slugsFor($level, $version), true);
    }

    /**
     * Clé de traduction du libellé d'un item, gelée avec sa version : une revue
     * passée reste relisible dans les mots de la grille qu'elle a réellement
     * appliquée.
     */
    public static function labelKey(string $slug, int $version = self::CURRENT_VERSION): string
    {
        return self::itemKey($slug, $version).'.label';
    }

    /**
     * Clé de traduction de l'aide d'un item — ce que l'item exclut, rendu à
     * côté du libellé sur l'écran de revue et sur la page « premiers pas ».
     */
    public static function helpKey(string $slug, int $version = self::CURRENT_VERSION): string
    {
        return self::itemKey($slug, $version).'.help';
    }

    /**
     * Vrai si la version ajoute un item pour motif juridique (D13 du 23/09) :
     * seule une version rétroactive ouvre, au jalon 2, le geste qui dépublie
     * les images publiées sans revue à cette version.
     */
    public static function isRetroactive(int $version): bool
    {
        return self::retroactiveIn(self::VERSIONS, $version);
    }

    /**
     * Les slugs qu'une version AJOUTE à la précédente — `slugs(v) − slugs(v−1)`,
     * dans l'ordre d'affichage. La première version n'a pas de précédente :
     * tous ses slugs sont des ajouts.
     *
     * @return list<string>
     */
    public static function addedSlugs(int $version): array
    {
        return self::addedSlugsIn(self::VERSIONS, $version);
    }

    /**
     * Les niveaux que touchent les items AJOUTÉS par une version rétroactive —
     * ceux des images que le geste rétroactif du jalon 2 peut dépublier —,
     * croissants et sans doublon. Vide pour une version non rétroactive : une
     * montée prospective ne sort rien du jeu.
     *
     * @return list<FrameLevel>
     */
    public static function retroactiveLevels(int $version = self::CURRENT_VERSION): array
    {
        return self::retroactiveLevelsIn(self::VERSIONS, $version);
    }

    /**
     * La décision d'une revue, dérivée côté serveur et jamais reçue du client
     * (spec 20 § 7.5, C14-bis § 4 point 5) : `passed` si et seulement si
     * l'ensemble des clés est EXACTEMENT celui des items applicables à ce
     * niveau et à cette version, et que toutes valent `true` ; `rejected`
     * sinon. Une réponse manquante, en trop ou autre que `true` ne publie donc
     * jamais rien : la grille est bloquante.
     *
     * @param  array<array-key, mixed>  $answers  Réponses clées par slug ; `true` = conforme.
     */
    public static function decisionFor(array $answers, FrameLevel $level, int $version): ReviewDecision
    {
        $expected = self::slugsFor($level, $version);
        $given = array_map(static fn (int|string $key): string => (string) $key, array_keys($answers));

        sort($expected);
        sort($given);

        if ($given !== $expected) {
            return ReviewDecision::Rejected;
        }

        foreach ($answers as $answer) {
            if ($answer !== true) {
                return ReviewDecision::Rejected;
            }
        }

        return ReviewDecision::Passed;
    }

    /**
     * Vrai si une revue faite sous cette version doit être rejouée.
     *
     * `null` = image jamais revue. C'est le prédicat de la file « images à
     * re-revoir », que la requête indexée reproduit en SQL.
     */
    public static function isOutdated(?int $reviewGridVersion): bool
    {
        return $reviewGridVersion !== self::CURRENT_VERSION;
    }

    /**
     * Items applicables restés sans réponse — la grille est BLOQUANTE, donc une
     * revue incomplète ne publie rien.
     *
     * @param  array<string, string|bool|null>  $answers  Réponses clées par slug.
     * @return list<string>
     */
    public static function missingAnswers(array $answers, FrameLevel $level, int $version = self::CURRENT_VERSION): array
    {
        $missing = [];

        foreach (self::slugsFor($level, $version) as $slug) {
            if (! array_key_exists($slug, $answers) || $answers[$slug] === null) {
                $missing[] = $slug;
            }
        }

        return $missing;
    }

    /**
     * Racine commune des deux clés d'un item, après vérification qu'il
     * appartient bien à la version citée.
     */
    private static function itemKey(string $slug, int $version): string
    {
        if (! in_array($slug, self::slugs($version), true)) {
            throw new InvalidArgumentException("Item [{$slug}] absent de la grille d'exclusion v{$version}.");
        }

        return self::LANG_PREFIX."v{$version}.{$slug}";
    }

    /*
    |--------------------------------------------------------------------------
    | Calculs sur une table de versions
    |--------------------------------------------------------------------------
    |
    | Les méthodes publiques les appliquent à {@see self::VERSIONS}, et à elle
    | seule. Ils prennent la table en argument pour que la règle « seuls les
    | niveaux des items AJOUTÉS » se prouve dès aujourd'hui sur une version
    | rétroactive fictive, sans attendre qu'une vraie version 2 existe — et
    | sans jamais écrire une version de test dans la table publiée.
    |
    */

    /**
     * @param  array<int, array{retroactive: bool, items: list<array{slug: string, levels: list<int>}>}>  $versions
     * @return list<array{slug: string, levels: list<int>}>
     */
    private static function itemsIn(array $versions, int $version): array
    {
        if (! array_key_exists($version, $versions)) {
            throw new InvalidArgumentException("Version [{$version}] inconnue de la grille d'exclusion.");
        }

        return $versions[$version]['items'];
    }

    /**
     * @param  array<int, array{retroactive: bool, items: list<array{slug: string, levels: list<int>}>}>  $versions
     */
    private static function retroactiveIn(array $versions, int $version): bool
    {
        self::itemsIn($versions, $version);

        return $versions[$version]['retroactive'];
    }

    /**
     * @param  array<int, array{retroactive: bool, items: list<array{slug: string, levels: list<int>}>}>  $versions
     * @return list<string>
     */
    private static function addedSlugsIn(array $versions, int $version): array
    {
        $current = self::slugsOf(self::itemsIn($versions, $version));
        $previous = array_key_exists($version - 1, $versions)
            ? self::slugsOf($versions[$version - 1]['items'])
            : [];

        return array_values(array_diff($current, $previous));
    }

    /**
     * @param  array<int, array{retroactive: bool, items: list<array{slug: string, levels: list<int>}>}>  $versions
     * @return list<FrameLevel>
     */
    private static function retroactiveLevelsIn(array $versions, int $version): array
    {
        if (! self::retroactiveIn($versions, $version)) {
            return [];
        }

        $added = self::addedSlugsIn($versions, $version);
        $levels = [];

        foreach (self::itemsIn($versions, $version) as $item) {
            if (in_array($item['slug'], $added, true)) {
                foreach ($item['levels'] as $level) {
                    $levels[$level] = FrameLevel::from($level);
                }
            }
        }

        ksort($levels);

        return array_values($levels);
    }

    /**
     * @param  list<array{slug: string, levels: list<int>}>  $items
     * @return list<string>
     */
    private static function slugsOf(array $items): array
    {
        return array_map(static fn (array $item): string => $item['slug'], $items);
    }
}
