<?php

namespace App\Support\Curation;

use App\Enums\FrameLevel;
use InvalidArgumentException;

/**
 * La grille d'exclusion de curation — versionnée EN CODE, sans aucune table.
 *
 * Une grille éditable en base permettrait de réécrire le libellé d'un item qu'une
 * revue a déjà cité, ce qui détruirait sa valeur de preuve. Le schéma ne fournit
 * que le NUMÉRO DE VERSION (`frame_review.grid_version`, `frame.review_grid_version`)
 * et le stockage des réponses par SLUG (`frame_review.answers`).
 *
 * Chaque version passée reste un tableau FIGÉ du dépôt, item par item, chaque item
 * portant un slug stable : c'est par ce slug que les réponses d'une revue sont
 * clées, donc relisibles sans le code de la version d'alors. **Une version publiée
 * ne se modifie jamais** — on en ajoute une, et `frame.review_grid_version` comparée
 * à {@see self::CURRENT_VERSION} produit la file « images à re-revoir ».
 *
 * Aucun libellé humain ici : chaque item n'expose qu'une CLÉ de traduction, gelée
 * avec sa version ({@see self::labelKey()}).
 *
 * Le contenu item par item appartient à `20-back-office-curation.md` ; la version 1
 * reprend la décision de `questions-ouvertes.md` révisée le 22/09.
 */
final readonly class ExclusionGrid
{
    /**
     * Version courante, comparée à `frame_review.grid_version` et à
     * `frame.review_grid_version`. Bornée par `unsignedTinyInteger`.
     */
    public const int CURRENT_VERSION = 1;

    /** Préfixe des clés de traduction, gelé avec la version. */
    private const string LANG_PREFIX = 'curation.exclusion_grid.';

    /**
     * Toutes les versions publiées, figées. `levels` énumère les `frame_level`
     * auxquels l'item s'applique.
     *
     * @var array<int, list<array{slug: string, levels: list<int>}>>
     */
    private const array VERSIONS = [
        1 => [
            // Ni affiche, ni jaquette : une reproduction d'un visuel promotionnel
            // n'est pas un photogramme transformé.
            ['slug' => 'no_poster_or_cover', 'levels' => [1, 2, 3, 4, 5]],

            // Pas de carton-titre.
            ['slug' => 'no_title_card', 'levels' => [1, 2, 3, 4, 5]],

            // Pas de logo de studio.
            ['slug' => 'no_studio_logo', 'levels' => [1, 2, 3, 4, 5]],

            // Pas de générique, de début comme de fin.
            ['slug' => 'no_credits', 'levels' => [1, 2, 3, 4, 5]],

            // Aucun texte qui NOMME ou IDENTIFIE le film — titre, sous-titre, nom
            // de saga, accroche, crédits —, quelle que soit l'écriture. Un texte de
            // décor sans rapport avec le titre reste autorisé, sans quoi le corpus
            // japonais et le corpus d'exception deviendraient impubliables.
            ['slug' => 'no_identifying_text', 'levels' => [1, 2, 3, 4, 5]],

            // Aucun filigrane ni mention de copyright incrustée.
            ['slug' => 'no_watermark_or_copyright', 'levels' => [1, 2, 3, 4, 5]],

            // Aucune incrustation de bande-annonce ni de diffuseur.
            ['slug' => 'no_broadcaster_or_trailer_overlay', 'levels' => [1, 2, 3, 4, 5]],

            // Aucune photo de plateau ni portrait promotionnel d'acteur : droit à
            // l'image, distinct du droit d'auteur sur l'œuvre.
            ['slug' => 'no_promotional_still_or_portrait', 'levels' => [1, 2, 3, 4, 5]],

            // Pas de visage du personnage principal — SEULS les niveaux 1 et 2,
            // parce que c'est une règle de cryptage de la manche et non une règle
            // de conformité : un plan iconique de niveau 5 le montre forcément.
            ['slug' => 'no_lead_face', 'levels' => [1, 2]],
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
        return array_map(
            static fn (array $item): string => $item['slug'],
            self::itemsOf($version),
        );
    }

    /**
     * Slugs applicables à un niveau d'image donné.
     *
     * @return list<string>
     */
    public static function slugsFor(FrameLevel $level, int $version = self::CURRENT_VERSION): array
    {
        $slugs = [];

        foreach (self::itemsOf($version) as $item) {
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
        if (! in_array($slug, self::slugs($version), true)) {
            throw new InvalidArgumentException("Item [{$slug}] absent de la grille d'exclusion v{$version}.");
        }

        return self::LANG_PREFIX."v{$version}.{$slug}";
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
     * @return list<array{slug: string, levels: list<int>}>
     */
    private static function itemsOf(int $version): array
    {
        if (! array_key_exists($version, self::VERSIONS)) {
            throw new InvalidArgumentException("Version [{$version}] inconnue de la grille d'exclusion.");
        }

        return self::VERSIONS[$version];
    }
}
