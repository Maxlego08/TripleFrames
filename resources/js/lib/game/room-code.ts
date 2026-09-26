/**
 * Miroir client, NON autoritaire, de `App\Support\Room\RoomCode` (spec 50
 * § 6.3 ; exigence de 90 § 4.7 ; patron du miroir `frame-geometry.ts`).
 *
 * Il sert le champ de code de l'accueil (`90`) : refuser un code mal formé
 * **sans requête**, sans écrire ailleurs la longueur ni l'alphabet. Le serveur
 * reste seul juge : un code bien formé peut répondre 404, et la liaison de
 * route refait la normalisation et le contrôle de forme.
 *
 * Les deux fonctions ne lisent que `ROOM_CODE` : aucun autre littéral de
 * longueur ni d'alphabet. Leurs deux règles de normalisation sont celles de
 * PHP, à l'octet près, et c'est pourquoi aucune ne s'en remet à Unicode :
 * - **majuscules ASCII seules** (`strtoupper` de PHP) : `toUpperCase()` sur
 *   toute la chaîne ferait de `ß` « SS » et de `ſ` « S », et accepterait un
 *   code que le serveur refuse ;
 * - **séparateurs ASCII seuls** (espace, blancs de contrôle, tiret-moins) :
 *   `\s` retirerait aussi l'espace insécable, que le serveur garde.
 *
 * La parité est prouvée par le jeu de cas partagé
 * `tests/Fixtures/room/room-codes.json`, écrit à la main : `RoomCodeTest`
 * l'exige de `RoomCode` (Pest), `room-code.test.ts` de ce module (Vitest), et
 * `RoomCodeTest` vérifie que `ROOM_CODE` reprend `ALPHABET` et `LENGTH`.
 */

/** Alphabet et longueur du code : miroir de `RoomCode::ALPHABET` et `LENGTH`. */
export const ROOM_CODE = {
    alphabet: 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
    length: 6,
} as const;

/** Séparateurs retirés : miroir de `RoomCode::SEPARATORS`. */
const SEPARATORS = /[\t\n\v\f\r -]/g;

/** Suites de minuscules ASCII, seules repliées en majuscules. */
const ASCII_LOWERCASE = /[a-z]+/g;

/**
 * La forme canonique d'une saisie : séparateurs retirés, majuscules ASCII.
 * Même règle que `RoomCode::normalize()`.
 */
export function normalizeRoomCode(code: string): string {
    return code
        .replace(SEPARATORS, '')
        .replace(ASCII_LOWERCASE, (run) => run.toUpperCase());
}

/**
 * Vrai si la saisie, normalisée, compte exactement `ROOM_CODE.length` signes
 * de `ROOM_CODE.alphabet`. Même règle que `RoomCode::isWellFormed()`.
 *
 * La longueur se compte en unités UTF-16 là où PHP compte des octets : les
 * deux ne diffèrent que sur un signe non ASCII, qu'aucun des deux côtés ne
 * trouve dans l'alphabet — le verdict est le même.
 */
export function isWellFormedRoomCode(code: string): boolean {
    const normalized = normalizeRoomCode(code);

    if (normalized.length !== ROOM_CODE.length) {
        return false;
    }

    for (const sign of normalized) {
        if (!ROOM_CODE.alphabet.includes(sign)) {
            return false;
        }
    }

    return true;
}
