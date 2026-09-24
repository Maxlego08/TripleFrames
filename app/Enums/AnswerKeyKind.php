<?php

namespace App\Enums;

/**
 * Nature d'une clé de réponse projetée : cast de `answer_key.key_kind`
 * (10 § 3.5, spec 70 § 4.1).
 *
 * Quatre natures **exactes** (`title_original`, `title_latin`, `title`,
 * `alias`), toujours acceptées pour le film de la manche, homonyme publié ou
 * non ; deux natures **dérivées** des seuls titres, jamais d'un alias
 * (décision 13, D23 du 23/09) : le préfixe, partie avant le premier séparateur
 * de sous-titre, et le sous-titre, partie après. Seules les dérivées sont
 * soumises à la règle de collision, mesurée sur le catalogue publié entier.
 */
enum AnswerKeyKind: string
{
    case TitleOriginal = 'title_original';

    case TitleLatin = 'title_latin';

    case Title = 'title';

    case Alias = 'alias';

    case Prefix = 'prefix';

    /** Partie après le premier séparateur d'un titre (D23 du 23/09) ; 8 caractères, sous la largeur `string(16)`. */
    case Subtitle = 'subtitle';

    /**
     * Vrai pour les deux natures dérivées, `prefix` et `subtitle` : une telle
     * clé du film de la manche est refusée dès qu'un autre film `published`
     * porte la même forme normalisée, sous quelque nature que ce soit (§ 4.2).
     */
    public function isCollisionChecked(): bool
    {
        return $this === self::Prefix || $this === self::Subtitle;
    }

    /**
     * Nature exacte, jamais soumise à collision — sémantique restreinte depuis
     * D23 du 23/09 : « pas dérivée », et non plus « pas `prefix` » (contrat
     * C12).
     *
     * Précédence sur `(movie_id, normalized)` dans le projecteur : toute nature
     * exacte l'emporte sur `prefix`, qui l'emporte sur `subtitle`.
     */
    public function isExact(): bool
    {
        return ! $this->isCollisionChecked();
    }

    /**
     * La nature d'appariement que `guess.match_kind` fige quand cette clé est
     * retenue (§ 6.4) : les trois natures de titre se confondent en `title`,
     * les deux dérivées gardent chacune la leur, pour que l'instantané dise
     * quelle règle de collision s'appliquait.
     */
    public function toMatchKind(): GuessMatchKind
    {
        return match ($this) {
            self::TitleOriginal, self::TitleLatin, self::Title => GuessMatchKind::Title,
            self::Alias => GuessMatchKind::Alias,
            self::Prefix => GuessMatchKind::Prefix,
            self::Subtitle => GuessMatchKind::Subtitle,
        };
    }

    /**
     * Les valeurs des natures soumises à collision, pour un `whereIn` — la
     * liste dérive de {@see self::isCollisionChecked()}, jamais d'un littéral.
     *
     * @return list<string>
     */
    public static function collisionCheckedValues(): array
    {
        return array_values(array_map(
            static fn (self $kind): string => $kind->value,
            array_filter(self::cases(), static fn (self $kind): bool => $kind->isCollisionChecked()),
        ));
    }
}
