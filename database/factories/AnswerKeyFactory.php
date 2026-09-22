<?php

namespace Database\Factories;

use App\Enums\AnswerKeyKind;
use App\Enums\Locale;
use App\Models\AnswerKey;
use App\Models\Movie;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * La projection consultée à chaque tentative (§ 3.5), unique propriétaire des
 * formes normalisées et des préfixes.
 *
 * **Le normaliseur de fixture vit ici, et il est provisoire.** L'algorithme
 * normatif — translittération, chiffres romains, tolérance de Levenshtein,
 * seuils — appartient à `docs/specs/70-validation-des-reponses.md`, qui n'est
 * pas écrite. Ce qui n'est PAS négociable, et c'est tout l'objet de ce fichier :
 * la fixture doit projeter ses clés avec le **même** normaliseur que celui qui
 * appariera les réponses. Deux normaliseurs distincts, et aucune réponse ne
 * valide jamais sur un catalogue de démonstration pourtant complet — le pendant
 * exact du piège des deux hashs tirés indépendamment du § 13.3.
 *
 * Quand la spec 70 posera son normaliseur applicatif, {@see self::normalize()}
 * devient un simple renvoi vers lui, jamais une seconde implémentation.
 *
 * L'alphabet de sortie est réduit à `[a-z0-9 ]` : sur cet alphabet,
 * `utf8mb4_unicode_ci` et BINARY rendent le **même** verdict, et la portabilité
 * cesse d'être une propriété du moteur pour devenir une propriété de la donnée.
 *
 * @extends Factory<AnswerKey>
 */
class AnswerKeyFactory extends Factory
{
    /**
     * Longueur maximale d'une forme normalisée (§ 1.3) : la borne haute du
     * réglage « longueur maximale d'une réponse », donc aucune chaîne
     * saisissable n'est jamais tronquée.
     */
    public const MAX_NORMALIZED_LENGTH = 200;

    /**
     * Articles de tête retirés à la normalisation. Liste provisoire, propriété
     * de la spec 70 : elle couvre les deux locales activées en v1.
     *
     * @var list<string>
     */
    private const LEADING_ARTICLES = [
        'le', 'la', 'les', 'l', 'un', 'une', 'des', 'du', 'de', 'the', 'a', 'an',
    ];

    /**
     * Séparateurs de sous-titre. Miroir provisoire de
     * `config('catalog.subtitle_separators')` (§ 3.5) : ces deux réglages vivent
     * en configuration et non en table, parce qu'un réglage de normalisation
     * modifiable sans reprojection produirait un index silencieusement
     * incohérent avec la règle qui l'a produit.
     *
     * @var list<string>
     */
    private const SUBTITLE_SEPARATORS = [' : ', ': ', ' - ', ' – ', ' — '];

    /**
     * Miroir provisoire de `config('catalog.min_prefix_length')`.
     */
    private const MIN_PREFIX_LENGTH = 4;

    /**
     * Define the model's default state.
     *
     * Une clé exacte de nature `title`, non ambiguë — seule la nature `prefix`
     * est soumise à la règle de collision.
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
     * Un alias curé ou importé. Un alias ne produit **jamais** de clé de nature
     * `prefix` (décision 13).
     */
    public function alias(string $text, string $sourceLocale = 'fr'): static
    {
        return $this->forText($text, AnswerKeyKind::Alias, $sourceLocale);
    }

    /**
     * Un préfixe dérivé d'un titre — la seule nature soumise à la règle de
     * collision.
     */
    public function prefix(string $text, ?string $sourceLocale = null): static
    {
        return $this->forText($text, AnswerKeyKind::Prefix, $sourceLocale);
    }

    /**
     * Un préfixe qu'un autre film `published` partage. Dénormalisé à dessein :
     * calculer l'ambiguïté par agrégation à chaque tentative mettrait un
     * `GROUP BY` sur le chemin le plus chaud du jeu.
     */
    public function ambiguous(): static
    {
        return $this->state([
            'key_kind' => AnswerKeyKind::Prefix,
            'is_ambiguous' => true,
        ]);
    }

    /**
     * La forme normalisée d'une chaîne : minuscules, diacritiques
     * translittérés, ponctuation retirée, article de tête retiré, alphabet
     * réduit à `[a-z0-9 ]`, bornée à 200 caractères.
     *
     * `Str::ascii()` et non `transliterator_transliterate()` : `ext-intl` est
     * **absente** de cet environnement (`CLAUDE.md` § 8), et un normaliseur qui
     * lèverait en CI ne normaliserait rien du tout.
     *
     * La ponctuation est remplacée par une espace **avant** `Str::ascii()`, et
     * c'est mesuré, pas décoratif : `Str::ascii()` SUPPRIME ce qu'elle ne sait
     * pas translittérer au lieu de le remplacer. Sans cette passe, « WALL·E »
     * donne `walle` là où le joueur qui tape « WALL E » donne `wall e`, et la
     * bonne réponse est refusée pour toujours.
     *
     * Rend la chaîne vide si rien ne subsiste — un titre entièrement non latin
     * passe alors par `movie.title_original_latin`, jamais par une clé vide.
     */
    public static function normalize(string $text): string
    {
        $unpunctuated = preg_replace('/[\p{P}\p{S}]+/u', ' ', $text) ?? $text;

        $folded = Str::lower(Str::ascii($unpunctuated));

        $spaced = preg_replace('/[^a-z0-9]+/', ' ', $folded) ?? '';
        $collapsed = trim(preg_replace('/\s+/', ' ', $spaced) ?? '');

        if ($collapsed === '') {
            return '';
        }

        return Str::substr(self::withoutLeadingArticle($collapsed), 0, self::MAX_NORMALIZED_LENGTH);
    }

    /**
     * Le préfixe **normalisé** d'un titre, découpé au premier séparateur de
     * sous-titre et retenu au-delà de la longueur minimale — ou `null` s'il n'y
     * a pas de sous-titre, si le préfixe est trop court, ou s'il ne se distingue
     * pas du titre entier.
     *
     * Ne s'applique **jamais** à un alias (décision 13) : c'est l'appelant qui
     * tient cette règle, la fonction ne sait pas d'où vient sa chaîne.
     */
    public static function prefixOf(string $title): ?string
    {
        $cut = null;

        foreach (self::SUBTITLE_SEPARATORS as $separator) {
            $position = mb_strpos($title, $separator);

            if ($position === false || $position === 0) {
                continue;
            }

            $cut = $cut === null ? $position : min($cut, $position);
        }

        if ($cut === null) {
            return null;
        }

        $prefix = self::normalize(mb_substr($title, 0, $cut));

        if (mb_strlen($prefix) < self::MIN_PREFIX_LENGTH || $prefix === self::normalize($title)) {
            return null;
        }

        return $prefix;
    }

    /**
     * Retire **un seul** article de tête, et jamais le dernier mot restant :
     * « The Thing » donne `thing`, « Le » seul reste `le`.
     */
    private static function withoutLeadingArticle(string $normalized): string
    {
        $words = explode(' ', $normalized);

        if (count($words) < 2 || ! in_array($words[0], self::LEADING_ARTICLES, true)) {
            return $normalized;
        }

        return implode(' ', array_slice($words, 1));
    }
}
