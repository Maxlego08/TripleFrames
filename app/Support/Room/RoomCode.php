<?php

namespace App\Support\Room;

use App\Models\Room;
use Closure;
use LogicException;
use RuntimeException;

/**
 * Le code de salon — spec 50 § 6.3 ; 10 § 6.2 ; 00 § Vocabulaire (« 6
 * caractères non ambigus »).
 *
 * **Seul générateur de production** du `room_code`. Les fabriques en sont
 * lectrices, selon le même motif que `SettingPresetCatalog` : un chiffrage
 * normatif n'habite pas `database/`, dont `faker` est en `require-dev`.
 *
 * **Recyclé à l'archivage, et par lui seul.** {@see self::generate()} tire
 * jusqu'à obtenir un code absent de `room_code_active` (`room_active_code_uq`,
 * une lecture de clé par tirage) : l'archivage, qui remet ce créneau à NULL,
 * est l'unique événement qui rend un code réattribuable. Un code porté par un
 * salon archivé est donc tirable, et un vieux lien mène alors au salon actif
 * qui le porte désormais — résidu assumé (§ 6.3), de probabilité (salons
 * actifs) / 32⁶ à un instant donné.
 *
 * **Saisie tolérante, contrôle strict.** {@see self::normalize()} replie la
 * casse et retire espaces et tirets ; {@see self::isWellFormed()} exige
 * ensuite exactement {@see self::LENGTH} signes de {@see self::ALPHABET}. Le
 * motif de route {@see self::ROUTE_PATTERN} est volontairement lâche : un
 * motif strict renverrait 404 avant la liaison pour un lien saisi en
 * minuscules ou avec un tiret, ce que `normalize()` existe pour accepter. La
 * liaison ({@see Room::resolveRouteBinding()}) normalise puis contrôle la
 * forme, et répond 404 **sans requête** à un code mal formé.
 *
 * **Miroir client** : `resources/js/lib/game/room-code.ts` (`ROOM_CODE`,
 * `normalizeRoomCode()`, `isWellFormedRoomCode()`), non autoritaire. La parité
 * est prouvée par le jeu partagé `tests/Fixtures/room/room-codes.json`, lu par
 * `RoomCodeTest` (Pest) et `room-code.test.ts` (Vitest). D'où deux choix de
 * normalisation, qui doivent rester identiques des deux côtés :
 * - **majuscules ASCII seules** (`strtoupper` de PHP 8.2+, insensible à la
 *   locale) : un repli Unicode ferait de `ß` « SS » et de `ſ` « S », et
 *   accepterait côté client un code que le serveur refuse ;
 * - **séparateurs ASCII seuls** : l'espace, les blancs de contrôle (tabulation,
 *   fins de ligne, saut de page) et le tiret-moins. Un espace insécable ou un
 *   tiret typographique reste en place, et le code est mal formé.
 */
final class RoomCode
{
    /**
     * 32 signes, ni `I`, ni `O`, ni `0`, ni `1`, qu'un joueur recopie de
     * travers depuis un écran partagé.
     */
    public const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Longueur du code : `room.room_code char(6)` (10 § 6.2). */
    public const int LENGTH = 6;

    /**
     * Motif TOLÉRANT du paramètre `{room}`, déclaré par `Route::pattern()` en
     * tête de `routes/game.php` : casse, espaces et tirets d'un lien saisi à
     * la main passent le routeur ; la validation stricte a lieu à la liaison.
     * `new` (trois signes) ne l'atteint jamais.
     */
    public const string ROUTE_PATTERN = '[A-Za-z0-9 -]{6,12}';

    /**
     * Garde de boucle — jamais une valeur de jeu : tirages de
     * {@see self::generate()}, et relances de la transaction de création sur
     * une violation de `room_active_code_uq` (`CreateRoom`, § 6.3 « Course »).
     */
    public const int MAX_ATTEMPTS = 8;

    /**
     * Séparateurs retirés par {@see self::normalize()} : espace, tabulation,
     * saut de ligne, tabulation verticale, saut de page, retour chariot et
     * tiret-moins — ASCII seulement, octet par octet (aucun drapeau `u`) : un
     * octet de séquence UTF-8 est toujours ≥ 0x80 et n'est jamais retiré.
     */
    private const string SEPARATORS = '/[\t\n\x0B\f\r -]/';

    /**
     * Un code neuf, absent de `room_code_active`.
     *
     * Chaque candidat est fait de {@see self::LENGTH} tirages `random_int`
     * dans {@see self::ALPHABET} — CSPRNG, hors de tout chemin seedé (le
     * contrat C3 ne s'applique qu'à `App\Support\Draw`). L'espace des codes
     * (32⁶, environ 10⁹) rend la boucle quasi immédiate.
     *
     * L'absence est lue, non réservée : deux créations concurrentes peuvent
     * tirer le même code, et c'est `room_active_code_uq` qui tranche à
     * l'insertion (relance de la transaction par `CreateRoom`).
     *
     * @param  (Closure(): string)|null  $draw  Source des candidats, pour les
     *                                          tests seulement ; `null` en
     *                                          production.
     *
     * @throws RuntimeException {@see self::MAX_ATTEMPTS} candidats tous portés par un salon actif :
     *                          l'échec bruyant d'un état impossible.
     * @throws LogicException Candidat injecté mal formé.
     */
    public static function generate(?Closure $draw = null): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = $draw === null ? self::draw() : $draw();

            if (! self::isCanonical($code)) {
                throw new LogicException('RoomCode : un candidat injecté doit être un code canonique bien formé.');
            }

            if (! Room::query()->where('room_code_active', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException(sprintf(
            'RoomCode : %d codes tirés, tous portés par un salon actif.',
            self::MAX_ATTEMPTS,
        ));
    }

    /**
     * La forme canonique d'une saisie : majuscules ASCII, espaces et tirets
     * retirés. Appliquée avant toute écriture et toute requête : MySQL est
     * insensible à la casse, SQLite en BINARY ne l'est pas, et la portabilité
     * vient de la donnée repliée, jamais d'une collation (10 § 1.4).
     */
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace(self::SEPARATORS, '', $code));
    }

    /**
     * Vrai si la saisie, normalisée, compte exactement {@see self::LENGTH}
     * signes de {@see self::ALPHABET}. Faux ne coûte aucune requête.
     */
    public static function isWellFormed(string $code): bool
    {
        return self::isCanonical(self::normalize($code));
    }

    /** Déjà normalisé, et bien formé. */
    private static function isCanonical(string $code): bool
    {
        return strlen($code) === self::LENGTH
            && strspn($code, self::ALPHABET) === self::LENGTH;
    }

    /** Un candidat : {@see self::LENGTH} tirages CSPRNG dans l'alphabet. */
    private static function draw(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($index = 0; $index < self::LENGTH; $index++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return $code;
    }
}
