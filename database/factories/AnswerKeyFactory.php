<?php

namespace Database\Factories;

use App\Enums\AnswerKeyKind;
use App\Enums\Locale;
use App\Models\AnswerKey;
use App\Models\Movie;
use App\Support\Catalog\AnswerKeyNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * La projection consultée à chaque tentative (§ 3.5), unique propriétaire des
 * formes normalisées et des clés dérivées, préfixes et sous-titres.
 *
 * **Le normaliseur ne vit plus ici.** {@see self::normalize()},
 * {@see self::prefixOf()} et {@see self::subtitleOf()} sont de simples renvois
 * vers {@see AnswerKeyNormalizer}, implémentation unique du projet : la fixture
 * doit projeter ses clés avec le **même** normaliseur que celui qui appariera
 * les réponses. Deux normaliseurs distincts, et aucune réponse ne valide jamais
 * sur un catalogue de démonstration pourtant complet — le pendant exact du
 * piège des deux hashs tirés indépendamment du § 13.3. Les renvois restent
 * parce que seeders et tests les nomment.
 *
 * @extends Factory<AnswerKey>
 */
class AnswerKeyFactory extends Factory
{
    /**
     * Longueur maximale d'une forme normalisée (§ 1.3) : la largeur de la
     * colonne. Clés et saisies sont tronquées au même endroit, et aucune
     * chaîne n'excède la colonne (spec 70 § 5.5, E10-12).
     */
    public const MAX_NORMALIZED_LENGTH = AnswerKeyNormalizer::MAX_NORMALIZED_LENGTH;

    /**
     * Define the model's default state.
     *
     * Une clé exacte de nature `title`, non ambiguë — seules les natures
     * dérivées, `prefix` et `subtitle`, sont soumises à la règle de collision.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $text */
        $text = fake()->unique()->words(3, true);

        return [
            'movie_id' => Movie::factory(),
            'key_kind' => AnswerKeyKind::Title,
            'source_locale' => Locale::English->value,
            'normalized' => self::normalize($text),
            'is_ambiguous' => false,
        ];
    }

    /**
     * Une clé projetée depuis une chaîne réelle : c'est la seule façon correcte
     * d'obtenir un `normalized` cohérent avec un titre ou un alias existant.
     *
     * @param  string|null  $sourceLocale  locale de CATALOGUE, nulle pour `title_original` et `title_latin`
     */
    public function forText(
        string $text,
        AnswerKeyKind $kind = AnswerKeyKind::Title,
        ?string $sourceLocale = null,
    ): static {
        return $this->state([
            'key_kind' => $kind,
            'source_locale' => $sourceLocale,
            'normalized' => self::normalize($text),
        ]);
    }

    /**
     * Le titre original, retenu **inconditionnellement** et sans filtre de
     * locale : `source_locale` est nulle par construction.
     */
    public function titleOriginal(string $text): static
    {
        return $this->forText($text, AnswerKeyKind::TitleOriginal);
    }

    /**
     * La translittération latine fournie par TMDB. Elle vient de
     * `movie.title_original_latin` et n'est **jamais** un alias (A4).
     */
    public function titleLatin(string $text): static
    {
        return $this->forText($text, AnswerKeyKind::TitleLatin);
    }

    /**
     * Un titre de catalogue, dans une locale **activée**.
     */
    public function title(string $text, string $sourceLocale = 'en'): static
    {
        return $this->forText($text, AnswerKeyKind::Title, $sourceLocale);
    }

    /**
     * Un alias curé ou importé. Un alias ne produit **jamais** de clé dérivée,
     * ni `prefix` (décision 13) ni `subtitle` (D23 du 23/09).
     */
    public function alias(string $text, string $sourceLocale = 'fr'): static
    {
        return $this->forText($text, AnswerKeyKind::Alias, $sourceLocale);
    }

    /**
     * Un préfixe dérivé d'un titre — nature soumise à la règle de collision.
     * `$text` est la partie **avant** le séparateur, pas le titre entier.
     */
    public function prefix(string $text, ?string $sourceLocale = null): static
    {
        return $this->forText($text, AnswerKeyKind::Prefix, $sourceLocale);
    }

    /**
     * Un sous-titre dérivé d'un titre (D23 du 23/09) — nature soumise à la
     * règle de collision, comme le préfixe. `$text` est la partie **après** le
     * premier séparateur, pas le titre entier.
     */
    public function subtitle(string $text, ?string $sourceLocale = null): static
    {
        return $this->forText($text, AnswerKeyKind::Subtitle, $sourceLocale);
    }

    /**
     * Une clé dérivée qu'un autre film `published` partage. Dénormalisé à
     * dessein : calculer l'ambiguïté par agrégation à chaque tentative
     * mettrait un `GROUP BY` sur le chemin le plus chaud du jeu.
     *
     * Une nature exacte n'est jamais ambiguë : posé sur une clé `subtitle`,
     * l'état la garde ; sur toute autre, il en fait un `prefix`.
     */
    public function ambiguous(): static
    {
        return $this->state(function (array $attributes): array {
            $kind = $attributes['key_kind'] ?? null;

            if (is_string($kind)) {
                $kind = AnswerKeyKind::tryFrom($kind);
            }

            return [
                'key_kind' => $kind instanceof AnswerKeyKind && $kind->isCollisionChecked()
                    ? $kind
                    : AnswerKeyKind::Prefix,
                'is_ambiguous' => true,
            ];
        });
    }

    /**
     * La forme normalisée d'une chaîne — **simple renvoi** vers le normaliseur
     * applicatif, jamais une seconde implémentation (arbitrage A5).
     */
    public static function normalize(string $text): string
    {
        return AnswerKeyNormalizer::normalize($text);
    }

    /**
     * Le préfixe normalisé d'un titre — **simple renvoi**, même raison. Ne
     * s'applique jamais à un alias (décision 13) : c'est l'appelant qui tient
     * cette règle, la fonction ne sait pas d'où vient sa chaîne.
     */
    public static function prefixOf(string $title): ?string
    {
        return AnswerKeyNormalizer::prefixOf($title);
    }

    /**
     * Le sous-titre normalisé d'un titre — **simple renvoi**, même raison et
     * même règle : jamais appliqué à un alias (D23 du 23/09).
     */
    public static function subtitleOf(string $title): ?string
    {
        return AnswerKeyNormalizer::subtitleOf($title);
    }
}
