<?php

namespace App\Support\Identity;

use App\Rules\ValidNickname;
use App\Support\Catalog\AnswerKeyNormalizer;
use Normalizer;

/**
 * Les deux formes d'un pseudo — contrat C5, première moitié (spec 40 § 5.2 et
 * § 5.5).
 *
 * - {@see self::canonical()} : la forme **affichée**, stockée dans
 *   `player.nickname`, accents et casse conservés (« Zoé » s'affiche « Zoé »).
 *   C'est elle que valide {@see ValidNickname} et elle qu'écrit
 *   l'action : aucune seconde normalisation dans un contrôleur (§ 5.8).
 * - {@see self::normalize()} : la forme **repliée**, seule écrivaine de
 *   `player.nickname_normalized` (E10-32), qui porte l'unicité par salon
 *   (`player_room_nickname_uq`, I5.4).
 *
 * **Un normaliseur dédié, pas celui des réponses** (§ 5.5). L'unicité d'un
 * pseudo sert à distinguer visuellement deux joueurs, pas à accepter une
 * réponse : `AnswerKeyNormalizer::normalize()` retirerait l'article de tête
 * (« Le Boss », « the_boss » et « Boss » deviendraient tous `boss`) et
 * convertirait les chiffres romains. Il est bâti sur
 * {@see AnswerKeyNormalizer::fold()} de `70` (C12), jamais sur `normalize()`,
 * et jamais sur `fold()` seul.
 *
 * Les bornes sont des **constantes de schéma** liées à `player.nickname` et
 * `nickname_normalized` `string(20)` (10 § 7.1), pas des réglages de jeu
 * (§ 5.10) : le front les reçoit en props, il ne les écrit jamais.
 */
final class NicknameNormalizer
{
    /** Longueur minimale du pseudo affiché, en caractères. */
    public const int MIN_LENGTH = 2;

    /**
     * Longueur maximale du pseudo affiché, en caractères, et de sa forme
     * repliée, en octets ASCII : `player.nickname` et `nickname_normalized`
     * sont tous deux `string(20)`.
     */
    public const int MAX_LENGTH = 20;

    /**
     * Garde : au-delà, l'entrée n'est pas transformée et échoue sur la
     * longueur (I5.1) — 256 octets d'UTF-8 font au moins 64 caractères, bien
     * au-delà de {@see self::MAX_LENGTH}. Aucune normalisation Unicode n'est
     * donc jamais payée sur une charge arbitrairement longue.
     */
    public const int MAX_RAW_BYTES = 256;

    /**
     * La forme **affichée** (I5.1), dans cet ordre :
     *
     * 1. normalisation **NFC** (`Normalizer::FORM_C`, polyfill Symfony, sans
     *    `ext-intl`) : un « é » saisi sur certains claviers mobiles arrive
     *    décomposé (`e` + U+0301), et sa marque combinante tomberait sinon
     *    hors des écritures admises ;
     * 2. `trim()` aux extrémités — le middleware `TrimStrings` a déjà coupé
     *    les blancs Unicode des bords d'une requête HTTP ;
     * 3. toute suite d'espaces U+0020 intérieure réduite à une seule. Les
     *    autres blancs intérieurs (tabulation, espace insécable…) ne sont pas
     *    touchés : la règle les refuse (`characters`).
     *
     * Une entrée de plus de {@see self::MAX_RAW_BYTES} octets, ou qui n'est
     * pas de l'UTF-8 valide, est rendue **telle quelle** : la règle la refuse
     * alors sur la longueur ou sur les caractères, jamais sur l'écriture.
     *
     * Idempotente : `canonical(canonical($x)) === canonical($x)`.
     */
    public static function canonical(string $raw): string
    {
        if (strlen($raw) > self::MAX_RAW_BYTES || ! mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        $composed = Normalizer::normalize($raw, Normalizer::FORM_C);

        if ($composed === false) {
            return $raw;
        }

        $trimmed = trim($composed);

        return preg_replace('/ {2,}/', ' ', $trimmed) ?? $trimmed;
    }

    /**
     * La forme **repliée** (I5.3) : {@see AnswerKeyNormalizer::fold()} de `70`
     * — translittération ASCII, minuscules —, puis suppression de tout ce qui
     * n'est pas `[a-z0-9]` : espaces, `-`, `_` et résidus de translittération.
     * « Jean-Luc », « jean luc » et « JEAN_LUC » sont le même nom à l'œil :
     * tous trois donnent `jeanluc`.
     *
     * **Aucun retrait d'article, aucune conversion de chiffres** : « Le Boss »
     * (`leboss`) reste distinct de « Boss » (`boss`), « Rocky II » de
     * « Rocky 2 ». **Aucune troncature** non plus : la translittération peut
     * allonger la chaîne (`ß` → `ss`, `œ` → `oe`), et c'est la règle qui
     * refuse une forme de plus de {@see self::MAX_LENGTH} caractères
     * (`normalized_length`), jamais MySQL par une 1406.
     *
     * Pure, déterministe et idempotente ; prend la forme canonique.
     */
    public static function normalize(string $canonical): string
    {
        return preg_replace('/[^a-z0-9]+/', '', AnswerKeyNormalizer::fold($canonical)) ?? '';
    }
}
