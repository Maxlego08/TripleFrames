<?php

namespace App\Support\Deploy;

use App\Enums\DrainPhase;
use App\Support\Game\GamesInProgress;
use App\ValueObjects\Deploy\DrainState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;

/**
 * Le drapeau de drainage de déploiement — spec 100 § 11.3, contrat C18-bis
 * (noms et signatures figés). **Seul** drapeau de drainage du dépôt (R-08).
 *
 * Une entrée du cache par défaut (Redis `appendonly` en production), clé
 * `config('deploy.cache_key')`, valeur {@see DrainState}, TTL calé sur son
 * échéance : un drapeau que plus personne ne tient s'efface seul, ce qui
 * reproduit exactement la sémantique d'abandon quand `deploy:drain` meurt,
 * sans `pcntl` ni signal.
 *
 * **Écrivains** : `deploy:drain` ({@see self::start()}, {@see self::openWindow()},
 * {@see self::release()} à l'abandon), `deploy:release` et le hook qui les
 * appelle — jamais le code applicatif. **Lecteurs** : la garde de lancement
 * (`OpenGame`, « Rejouer », solo) et la prop partagée `maintenance`, par
 * {@see self::isDraining()} seulement ; `deploy:guard` par {@see self::state()}.
 * Aucun d'eux ne coupe ni ne retarde une partie en cours.
 *
 * Pas de liaison au conteneur : aucune donnée n'est gardée en mémoire, chaque
 * lecture interroge le cache.
 */
final class DeployDrain
{
    /** Millisecondes par minute : la borne se compte en minutes. */
    private const int MILLISECONDS_PER_MINUTE = 60_000;

    /**
     * Pose le drapeau en phase `draining`, échéance `now + timeout`, par
     * `Cache::add` — atomique sur Redis : deux drainages lancés ensemble n'en
     * posent qu'un.
     *
     * Une entrée présente mais illisible (aucune phase connue, instant mal
     * formé) ne protège rien et ne bloque aucun lancement : elle est
     * remplacée, au lieu de rendre tout drainage impossible jusqu'à un
     * `deploy:release`.
     *
     * @throws DrainAlreadyRunning Un drapeau lisible existe déjà ; rien n'est modifié.
     */
    public function start(int $timeoutMinutes): DrainState
    {
        $now = self::now();
        $state = new DrainState(DrainPhase::Draining, $now, $now->addMinutes($timeoutMinutes));

        if (Cache::add(self::key(), $state->toCache(), $state->ttlSeconds($now))) {
            return $state;
        }

        $existing = $this->state();

        if ($existing instanceof DrainState) {
            throw new DrainAlreadyRunning($existing);
        }

        Cache::put(self::key(), $state->toCache(), $state->ttlSeconds($now));

        return $state;
    }

    /**
     * Passe en phase `window`, échéance `now + window` : le temps de vérifier
     * `deploy:guard`, de cliquer « Déployer » et de laisser le hook finir.
     * L'instant de début du drainage est conservé.
     */
    public function openWindow(int $windowMinutes): DrainState
    {
        $now = self::now();
        $state = new DrainState(
            DrainPhase::Window,
            $this->state()->startedAt ?? $now,
            $now->addMinutes($windowMinutes),
        );

        Cache::put(self::key(), $state->toCache(), $state->ttlSeconds($now));

        return $state;
    }

    /**
     * Le drapeau en vigueur, ou `null` : absent, illisible ou échu. Relu dans
     * le cache à chaque appel.
     *
     * @phpstan-impure
     */
    public function state(): ?DrainState
    {
        $state = DrainState::fromCache(Cache::get(self::key()));

        if (! $state instanceof DrainState || $state->isExpiredAt(self::now())) {
            return null;
        }

        return $state;
    }

    /**
     * Vrai dans **les deux** phases : tant que le drapeau tient, aucune
     * nouvelle partie ne se lance. Lu par la garde de lancement et par la prop
     * partagée `maintenance` — rien d'autre ne part vers un joueur.
     *
     * @phpstan-impure
     */
    public function isDraining(): bool
    {
        return $this->state() instanceof DrainState;
    }

    /** Retire le drapeau, quelle que soit sa phase ; sans drapeau, ne fait rien. */
    public function release(): void
    {
        Cache::forget(self::key());
    }

    /**
     * Borne d'attente par défaut, en minutes (§ 11.3, R-10) :
     * `ceil(maxNaturalDurationMs / 60 000) + drain_margin_minutes`, soit
     * environ 78 + 20 = 98 minutes aux bornes actuelles. Dérivée du prédicat
     * de 60 (contrat C17 § 4.3), jamais d'un produit écrit à la main ; la
     * marge couvre au moins une pause, attente puis décompte de reprise
     * (`pauseTimeoutMs + launchCountdownMs`, § 11.8, précision (5)), ce que
     * `DeployDrainCommandTest` prouve.
     */
    public static function defaultTimeoutMinutes(): int
    {
        return (int) ceil(GamesInProgress::maxNaturalDurationMs() / self::MILLISECONDS_PER_MINUTE)
            + Config::integer('deploy.drain_margin_minutes');
    }

    private static function key(): string
    {
        return Config::string('deploy.cache_key');
    }

    /**
     * L'instant présent, tronqué à la milliseconde : la précision du drapeau
     * relu ({@see DrainState}). Sans troncature, un état rendu par
     * {@see self::start()} garderait ses microsecondes et ne serait jamais
     * égal à sa propre forme relue du cache — `deploy:drain` croirait son
     * drapeau levé ailleurs dès le premier réveil.
     */
    private static function now(): CarbonImmutable
    {
        return Date::now()->toImmutable()->startOfMillisecond();
    }
}
