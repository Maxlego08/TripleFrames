/**
 * Types du fil de jeu (spec 60 § 11, contrat C7 § 3) : enveloppe, charges
 * d'événement et paquet de resynchronisation, miroirs de ce que le serveur
 * envoie. Les types d'autres contrats sont **importés, jamais redéclarés**
 * (R-27).
 *
 * Le fichier se remplit en trois lots, pour qu'aucun lot ne déclare un type
 * dont le fichier importé n'existe pas encore (60 § 11.4) :
 * - L60-2 — les seuls types sans import d'une autre spec, ci-dessous ;
 * - L60-4 — `SeatView`, `RoundTimeline`, `RoundState`, `SelfState`,
 *   `GameStatePacket`, qui importent `types/player`, `types/answers` et
 *   `types/scoring` ;
 * - L60-9 — les charges typées en `RoomSettingsState` et l'union
 *   `GameEventName`.
 *
 * Règle 3 : aucun de ces types ne porte, avant la révélation, un titre, un
 * alias, un identifiant interne, un niveau d'image ni l'index d'une bonne
 * proposition. `RevealMovie` ne voyage qu'à la révélation et au
 * récapitulatif.
 */

/**
 * Instant absolu sur le fil : `YYYY-MM-DDTHH:mm:ss.sssZ`, UTC, à la
 * milliseconde (05 : instants ISO-8601 UTC). Lu par `parseIsoMs()`
 * (`lib/game/wire.ts`). Durées et décalages, eux, sont des entiers en
 * millisecondes.
 */
export type IsoMs = string;

/**
 * En tête de chaque événement et de tout paquet de resynchronisation.
 * `v` suit `GAME_WIRE_VERSION` (`lib/game/wire.ts`), miroir de
 * `GameWire::VERSION` ; `gameRef` est nul pour un événement de lobby hors
 * partie.
 */
export interface WireEnvelope {
    v: 1;
    serverNow: IsoMs;
    gameRef: string | null;
}

/** Locales d'interface activées, miroir de `App\Enums\Locale::cases()`. */
export type LocaleCode = 'fr' | 'en';

/**
 * Référence d'image d'un palier. `url` est produite par le serveur (URL
 * signée de `frame.serve`), jamais reconstruite par Wayfinder ;
 * `fetchNotBefore` = `Tᵢ − preload_lead_ms` : le client ne la demande
 * jamais avant (60 § 7.6).
 */
export interface TierImageRef {
    tierIndex: number;
    url: string;
    fetchNotBefore: IsoMs;
}

/**
 * Un titre de la révélation et la langue dans laquelle il est écrit :
 * `lang` est la balise BCP 47 de la locale atteinte, ou, au rang 3,
 * `original_language` suffixé `-Latn` si la translittération est servie.
 * Le client la pose en attribut `lang` (05).
 */
export interface RevealTitle {
    text: string;
    lang: string;
}

/**
 * Le film révélé, composé par le seul `RevealMovieBuilder` du serveur : un
 * titre par locale activée, le titre original (et sa translittération), sa
 * langue et l'année. Aussi le « paquet de titres » du récapitulatif de fin
 * de partie (`TitlePacket` de `types/scoring.ts`, contrat C13).
 */
export interface RevealMovie {
    titles: Record<LocaleCode, RevealTitle>;
    originalTitle: string;
    originalTitleLatin: string | null;
    originalLanguage: string;
    year: number | null;
}
