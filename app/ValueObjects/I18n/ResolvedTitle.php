<?php

namespace App\ValueObjects\I18n;

use App\Enums\Locale;
use InvalidArgumentException;

/**
 * Un titre de film résolu par la chaîne de repli d'affichage de la spec 05
 * (spec 70 § 10.5, contrat C11), avec la **locale atteinte** et son **rang**.
 *
 * Le rang est une donnée de premier plan (05 § Chaîne de repli) : c'est lui
 * qui rend le QCM dangereux, et c'est la locale atteinte qui donne l'attribut
 * `lang` d'un fragment obtenu par repli (05 § Attribut `lang`,
 * `round_choice_set.rendered_locale`, titres de la révélation).
 *
 * - rang 1 : `movie_title` dans la locale demandée ;
 * - rang 2 : `movie_title` d'une autre locale activée, par `fallbackRank()` ;
 * - rang 3 : le titre original (ou sa translittération), `locale` nulle.
 *
 * Ni `Arrayable` ni `JsonSerializable` : le texte est un titre, qui ne quitte
 * le serveur que par une charge construite pour cela (QCM, révélation).
 */
final readonly class ResolvedTitle
{
    /** Titre dans la locale demandée. */
    public const int RANK_REQUESTED_LOCALE = 1;

    /** Titre d'une autre locale activée, par rang de repli d'instance. */
    public const int RANK_OTHER_LOCALE = 2;

    /** Titre original, ou sa translittération latine. */
    public const int RANK_ORIGINAL = 3;

    /**
     * @param  string  $text  La chaîne affichée, telle que stockée.
     * @param  Locale|null  $locale  La locale atteinte ; NULL = titre original.
     * @param  int  $rank  1, 2 ou 3.
     *
     * @throws InvalidArgumentException Rang hors de 1..3, ou locale incohérente avec le rang.
     */
    public function __construct(
        public string $text,
        public ?Locale $locale,
        public int $rank,
    ) {
        if ($rank < self::RANK_REQUESTED_LOCALE || $rank > self::RANK_ORIGINAL) {
            throw new InvalidArgumentException(sprintf(
                'ResolvedTitle : rang %d hors de [%d, %d].',
                $rank,
                self::RANK_REQUESTED_LOCALE,
                self::RANK_ORIGINAL,
            ));
        }

        if (($locale === null) !== ($rank === self::RANK_ORIGINAL)) {
            throw new InvalidArgumentException(
                'ResolvedTitle : la locale est nulle si et seulement si le titre est l’original.',
            );
        }
    }
}
