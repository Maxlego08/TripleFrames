<?php

namespace App\Support\Realtime;

use App\Settings\EngineConstants;
use Illuminate\Support\Facades\Config;

/**
 * La prop partagée `realtime` — spec 60 § 10.5, contrat C7 § 2.6 (n° 79,
 * A-27).
 *
 * `{ key, host, port, scheme, heartbeatIntervalMs, clockSamples }` : de quoi
 * configurer Echo **à l'exécution** (`lib/game/echo.ts`, L60-9), jamais
 * depuis une variable `VITE_REVERB_*` figée au build — un artefact construit
 * en CI se promeut sans rebuild, sans jamais connaître `<DOMAINE>`.
 *
 * - `key` : la clé d'application Reverb, **publique par conception** (spec
 *   100 § 10.10, n° 79 : elle est servie à tout client). Jamais le secret, jamais
 *   l'identifiant d'application.
 * - `host`, `port`, `scheme` : ce que le navigateur vise
 *   (`REVERB_CLIENT_*`, `broadcasting.connections.reverb.client`), nuls pour
 *   « prendre `window.location` ». Une valeur vide ou illisible vaut nulle :
 *   port hors de `1..65535`, schéma autre que `http` ou `https`.
 * - `heartbeatIntervalMs`, `clockSamples` : {@see EngineConstants}, jamais un
 *   littéral côté client (règle 2).
 *
 * Aucune donnée de partie, de siège ni de visiteur : la prop est identique
 * pour tous.
 */
final class RealtimeClientConfig
{
    /** Schémas que le navigateur sait viser. */
    private const array SCHEMES = ['http', 'https'];

    private const int MAX_PORT = 65535;

    /**
     * @return array{key: string, host: string|null, port: int|null, scheme: 'http'|'https'|null, heartbeatIntervalMs: int, clockSamples: int}
     */
    public static function toArray(): array
    {
        return [
            'key' => self::text(Config::get('broadcasting.connections.reverb.key')) ?? '',
            'host' => self::text(Config::get('broadcasting.connections.reverb.client.host')),
            'port' => self::port(Config::get('broadcasting.connections.reverb.client.port')),
            'scheme' => self::scheme(Config::get('broadcasting.connections.reverb.client.scheme')),
            'heartbeatIntervalMs' => EngineConstants::heartbeatIntervalMs(),
            'clockSamples' => EngineConstants::clockSamples(),
        ];
    }

    /** Une chaîne non vide, sans espaces de bord, ou NULL. */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function port(mixed $value): ?int
    {
        $value = self::text($value);

        if ($value === null || preg_match('/^\d{1,5}$/D', $value) !== 1) {
            return null;
        }

        $port = (int) $value;

        return $port >= 1 && $port <= self::MAX_PORT ? $port : null;
    }

    /**
     * @return 'http'|'https'|null
     */
    private static function scheme(mixed $value): ?string
    {
        $value = self::text($value);

        if ($value === null) {
            return null;
        }

        $value = strtolower($value);

        foreach (self::SCHEMES as $scheme) {
            if ($value === $scheme) {
                return $scheme;
            }
        }

        return null;
    }
}
