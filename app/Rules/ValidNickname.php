<?php

namespace App\Rules;

use App\Support\Identity\NicknameNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * La règle de pseudo — contrat C5, première moitié (spec 40 § 5.3 et § 5.4).
 *
 * Elle valide la **forme canonique** telle qu'elle la reçoit : la FormRequest
 * l'a préparée par `PlayerIdentityValidationRules::prepareNickname()` dans
 * `prepareForValidation()` (§ 5.8), et l'action écrit exactement ce qui a été
 * validé. La règle ne recompose rien elle-même : une FormRequest qui aurait
 * oublié la préparation verrait un accent décomposé refusé, au lieu d'écrire
 * en base une forme que la règle n'a pas vue.
 *
 * **Un seul message par envoi, le premier échec l'emporte** (I5.2) :
 *
 * 1. longueur hors de [`MIN_LENGTH`, `MAX_LENGTH`] caractères, ou plus de
 *    `MAX_RAW_BYTES` octets → `length` ;
 * 2. au moins un caractère hors de {@see self::ALLOWED_PATTERN} : `script` si
 *    l'un des caractères refusés est une lettre ou un chiffre (`\p{L}`,
 *    `\p{N}`), sinon `characters`. Le message d'écriture l'emporte parce
 *    qu'il dit au joueur quoi faire. Une entrée qui n'est pas de l'UTF-8
 *    valide échoue ici sur `characters`, **jamais** sur `script` : ses octets
 *    invalides ne sont ni une lettre ni un chiffre ;
 * 3. forme repliée vide → `alnum` ;
 * 4. forme repliée de plus de `MAX_LENGTH` octets → `normalized_length`,
 *    jamais une erreur 1406 de MySQL.
 *
 * L'étape 5 (liste noire, `blocked`) est livrée par L40-4 ; l'étape 6
 * (unicité, `taken`) appartient à `50`, sous le verrou du salon, **jamais** à
 * la règle.
 */
final class ValidNickname implements ValidationRule
{
    /**
     * Écritures admises (D26 du 23/09, § 5.3) : l'alphabet latin seul — latin
     * de base, Latin-1 supplément (U+00C0–U+00FF sauf `×` et `÷`, plus les
     * indicateurs ordinaux `ª` et `º`) et Latin étendu-A (U+0100–U+017F) —,
     * les chiffres ASCII, l'espace U+0020, `-` et `_`.
     *
     * Pourquoi l'alphabet latin seul : la liste noire ne lit que les langues
     * activées, toutes deux latines ; les homoglyphes (« Bоb » au `о`
     * cyrillique) tombent d'eux-mêmes, `Spoofchecker` exigeant `ext-intl` ; et
     * la règle se vérifie sans extension. Ouvrir une écriture se fera avec
     * l'ajout de sa langue et de sa liste noire (05).
     */
    public const string ALLOWED_PATTERN = '/^[A-Za-z0-9\x{00AA}\x{00BA}\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{017F} _-]+$/u';

    /** Longueur hors bornes — `:attribute`, `:min`, `:max`. */
    public const string KEY_LENGTH = 'validation.nickname.length';

    /** Lettre ou chiffre d'une écriture non admise — `:attribute`. */
    public const string KEY_SCRIPT = 'validation.nickname.script';

    /** Symbole, émoji, caractère invisible, de contrôle ou combinant. */
    public const string KEY_CHARACTERS = 'validation.nickname.characters';

    /** Ni lettre ni chiffre : seulement des espaces, tirets et soulignés. */
    public const string KEY_ALNUM = 'validation.nickname.alnum';

    /** Forme repliée trop longue (`ß` → `ss`, `œ` → `oe`…). */
    public const string KEY_NORMALIZED_LENGTH = 'validation.nickname.normalized_length';

    /** Liste noire (L40-4) : ne cite jamais le mot, ne distingue jamais un nom réservé. */
    public const string KEY_BLOCKED = 'validation.nickname.blocked';

    /** Pseudo déjà pris dans le salon : émise par `50`, jamais par la règle. */
    public const string KEY_TAKEN = 'validation.nickname.taken';

    /**
     * Les sept messages du pseudo (§ 5.9), lus par la couverture de traduction
     * (« carries every key built by an enumerable key constructor »).
     *
     * @var list<string>
     */
    public const array MESSAGE_KEYS = [
        self::KEY_LENGTH,
        self::KEY_SCRIPT,
        self::KEY_CHARACTERS,
        self::KEY_ALNUM,
        self::KEY_NORMALIZED_LENGTH,
        self::KEY_BLOCKED,
        self::KEY_TAKEN,
    ];

    /** Une lettre ou un chiffre, toutes écritures confondues. */
    private const string LETTER_OR_DIGIT_PATTERN = '/[\p{L}\p{N}]/u';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Le type est l'affaire de la règle `string` qui la précède
        // (`nicknameRules()`) : un second message serait de trop (I5.2).
        if (! is_string($value)) {
            return;
        }

        $length = mb_strlen($value, 'UTF-8');

        if (strlen($value) > NicknameNormalizer::MAX_RAW_BYTES
            || $length < NicknameNormalizer::MIN_LENGTH
            || $length > NicknameNormalizer::MAX_LENGTH) {
            $fail(self::KEY_LENGTH)->translate([
                'min' => NicknameNormalizer::MIN_LENGTH,
                'max' => NicknameNormalizer::MAX_LENGTH,
            ]);

            return;
        }

        $refused = self::refusedCharacters($value);

        if ($refused !== []) {
            $fail(self::isScript($refused) ? self::KEY_SCRIPT : self::KEY_CHARACTERS)->translate();

            return;
        }

        $normalized = NicknameNormalizer::normalize($value);

        if ($normalized === '') {
            $fail(self::KEY_ALNUM)->translate();

            return;
        }

        if (strlen($normalized) > NicknameNormalizer::MAX_LENGTH) {
            $fail(self::KEY_NORMALIZED_LENGTH)->translate();
        }
    }

    /**
     * Les caractères que {@see self::ALLOWED_PATTERN} refuse, dans l'ordre de
     * la saisie. De l'UTF-8 invalide rend la chaîne entière, qu'aucune classe
     * `\p{…}` ne lira comme une lettre.
     *
     * Le motif s'applique **caractère par caractère, jamais à la chaîne
     * entière** : son `$`, sans modificateur `D` (motif contractuel, C5),
     * s'ancre aussi devant un saut de ligne final, si bien que `"Bob\n"` le
     * satisferait en entier alors que `\n` est hors de l'ensemble admis. La
     * saisie tient ici en `MAX_LENGTH` caractères (étape 1 passée) : la
     * boucle ne coûte rien.
     *
     * @return list<string>
     */
    private static function refusedCharacters(string $value): array
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return [$value];
        }

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(
            $characters === false ? [$value] : $characters,
            static fn (string $character): bool => preg_match(self::ALLOWED_PATTERN, $character) !== 1,
        ));
    }

    /**
     * Vrai si l'un des caractères refusés est une lettre ou un chiffre : le
     * joueur a écrit dans une autre écriture (kana, cyrillique, pleine
     * chasse, `µ`…), et le message d'écriture lui dit quoi faire.
     *
     * @param  list<string>  $refused
     */
    private static function isScript(array $refused): bool
    {
        foreach ($refused as $character) {
            if (mb_check_encoding($character, 'UTF-8')
                && preg_match(self::LETTER_OR_DIGIT_PATTERN, $character) === 1) {
                return true;
            }
        }

        return false;
    }
}
