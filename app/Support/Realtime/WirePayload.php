<?php

namespace App\Support\Realtime;

use App\Events\Game\RoomBroadcast;
use App\Events\Game\SeatBroadcast;
use LogicException;

/**
 * La conformité d'une charge du fil de jeu, champ par champ — spec 60 § 11.3,
 * § 11.5 et § 11.7, contrat C7 § 2.3 et § 3 (règle 3).
 *
 * Chaque événement diffusé déclare la liste CLOSE de ses champs, hors
 * enveloppe, et leur nature ; sa base ({@see RoomBroadcast},
 * {@see SeatBroadcast}) fait passer sa charge par
 * {@see self::conform()} à la construction, donc sous le verrou de la
 * transition qui la précalcule. Une charge non conforme est un défaut de
 * l'émetteur : elle lève avant tout envoi, jamais après.
 *
 * **Natures** : `int`, `bool`, `string`, `iso` (un `IsoMs`, {@see WireTime::PATTERN}),
 * `object` (tableau associatif non vide, un objet JSON), `list` (liste,
 * éventuellement vide, un tableau JSON) ; préfixe `?` = nullable.
 *
 * **Garde de fuite, à toute profondeur** ({@see self::assertSafe()}) :
 * - que des données — `null`, booléens, nombres, chaînes, tableaux. Aucun
 *   objet : un modèle Eloquent passé tel quel serait sérialisé par son
 *   `toArray()`, identifiant interne compris, sans que rien ne le signale ;
 * - aucune clé d'identifiant interne ni de secret ({@see self::isInternalKey()}) :
 *   `id`, toute clé en `_id`/`Id` (sauf un `publicId`), `frame_level`,
 *   `game_path`, `draw_seed`, `active_seat_token`, `serve_token`, le hash du
 *   jeton, les chaînes `choice_1..4`… Un `$round->toArray()` glissé dans une
 *   charge porte `id` : il est refusé, même si ses colonnes cachées ne
 *   partent pas.
 */
final class WirePayload
{
    /** Natures admises, sans le préfixe nullable. */
    private const array KINDS = ['int', 'bool', 'string', 'iso', 'object', 'list'];

    /**
     * Noms interdits hors de la règle des identifiants, comparés en minuscules
     * sans `_` : un nom en `snake_case` et son équivalent `camelCase` tombent
     * ensemble (10 § 1.1, 60 § 11.7).
     */
    private const array INTERNAL_NAMES = [
        'framelevel',
        'gamepath',
        'masterpath',
        'drawseed',
        'drawpoolsize',
        'activeseattoken',
        'servetoken',
        'playertokenhash',
        'solotokenhash',
        'tokenhash',
        'tid',
        'choice1',
        'choice2',
        'choice3',
        'choice4',
        'nicknamenormalized',
        'answerkeynormalized',
        'submittednormalized',
        'settingssnapshot',
    ];

    /**
     * La charge, vérifiée champ par champ et remise dans l'ordre déclaré.
     *
     * @param  string  $event  Nom `broadcastAs` de l'événement, pour le message d'erreur.
     * @param  array<string, string>  $fields  Champs déclarés → nature.
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws LogicException champ manquant ou en trop, nature fausse, objet
     *                        ou clé d'identifiant interne à toute profondeur.
     */
    public static function conform(string $event, array $fields, array $payload): array
    {
        $declared = array_keys($fields);
        $given = array_map(static fn (int|string $key): string => (string) $key, array_keys($payload));

        $missing = array_values(array_diff($declared, $given));
        $extra = array_values(array_diff($given, $declared));

        if ($missing !== [] || $extra !== []) {
            throw new LogicException(sprintf(
                'Charge de [%s] hors de la liste close de ses champs : manquants [%s], en trop [%s].',
                $event,
                implode(', ', $missing),
                implode(', ', $extra),
            ));
        }

        self::assertSafe($payload, $event);

        $conformed = [];

        foreach ($fields as $field => $kind) {
            $value = $payload[$field];

            if (! self::matches($kind, $value)) {
                throw new LogicException(sprintf(
                    'Charge de [%s] : le champ [%s] doit être de nature [%s], reçu [%s].',
                    $event,
                    $field,
                    $kind,
                    get_debug_type($value),
                ));
            }

            $conformed[$field] = $value;
        }

        return $conformed;
    }

    /**
     * Refuse tout objet et toute clé d'identifiant interne, à toute profondeur.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws LogicException
     */
    public static function assertSafe(array $payload, string $context, string $path = ''): void
    {
        foreach ($payload as $key => $value) {
            $at = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key) && self::isInternalKey($key)) {
                throw new LogicException(sprintf('Charge de [%s] : clé d\'identifiant interne [%s].', $context, $at));
            }

            if (is_array($value)) {
                self::assertSafe($value, $context, $at);

                continue;
            }

            if ($value !== null && ! is_scalar($value)) {
                throw new LogicException(sprintf(
                    'Charge de [%s] : [%s] porte un objet [%s], jamais une donnée — un modèle se sérialiserait avec ses identifiants.',
                    $context,
                    $at,
                    get_debug_type($value),
                ));
            }
        }
    }

    /**
     * Vrai pour une clé qui nommerait un identifiant interne ou un secret :
     * `id` ; toute clé en `_id`, `_ids`, `Id` ou `Ids`, sauf un identifiant
     * public (`publicId`, `hostPublicId`, `public_id`…) ; les noms de
     * {@see self::INTERNAL_NAMES}, en `snake_case` comme en `camelCase`.
     */
    public static function isInternalKey(string $key): bool
    {
        // L'adresse publique d'un siège, seule forme d'identifiant admise.
        if (preg_match('/(^|_)public_ids?$|^publicIds?$|PublicIds?$/', $key) === 1) {
            return false;
        }

        // `id`, `ids`, `…_id`, `…_ids`, quelle que soit la casse.
        if (preg_match('/^ids?$|_ids?$/i', $key) === 1) {
            return true;
        }

        // `…Id`, `…Ids`, `…ID` : la majuscule distingue `roundId` de `valid`.
        if (preg_match('/[a-z0-9](Id|Ids|ID|IDs)$/', $key) === 1) {
            return true;
        }

        return in_array(str_replace('_', '', strtolower($key)), self::INTERNAL_NAMES, true);
    }

    /** Vrai si `$value` est de nature `$kind` (préfixe `?` : `null` admis). */
    private static function matches(string $kind, mixed $value): bool
    {
        if (str_starts_with($kind, '?')) {
            if ($value === null) {
                return true;
            }

            $kind = substr($kind, 1);
        }

        if (! in_array($kind, self::KINDS, true)) {
            throw new LogicException(sprintf('Nature de champ inconnue : [%s].', $kind));
        }

        return match ($kind) {
            'int' => is_int($value),
            'bool' => is_bool($value),
            'string' => is_string($value),
            'iso' => is_string($value) && preg_match(WireTime::PATTERN, $value) === 1,
            'object' => is_array($value) && $value !== [] && ! array_is_list($value),
            'list' => is_array($value) && array_is_list($value),
        };
    }
}
