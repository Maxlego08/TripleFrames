<?php

namespace App\Support\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * L'interrupteur d'incident de la purge — `purge:suspend` et `purge:resume`
 * (spec 100 § 14).
 *
 * Un drapeau du cache applicatif (Redis dédié, `appendonly`, en production),
 * sans échéance : une suspension ne se lève jamais d'elle-même, parce qu'une
 * purge qui repartirait seule au milieu d'un incident serait pire que la
 * panne. En contrepartie, **tant que le drapeau existe, la sonde `purge` est
 * en alerte** : l'interrupteur DÉCLENCHE l'alerte au lieu de la masquer
 * (`questions-ouvertes.md` § Risques élevés), et une suspension oubliée se
 * voit.
 *
 * Le moteur le lit avant chaque périmètre et avant chaque lot : une
 * suspension posée pendant une exécution l'arrête au lot suivant.
 */
final class PurgeSuspension
{
    /** La clé du drapeau ; sa valeur est l'instant de la suspension, en ISO-8601. */
    public const string CACHE_KEY = 'ops:purge:suspended';

    /**
     * Relu dans le cache à chaque appel : une suspension posée entre deux
     * lectures est vue à la suivante.
     *
     * @phpstan-impure
     */
    public function isSuspended(): bool
    {
        return Cache::has(self::CACHE_KEY);
    }

    /**
     * Pose le drapeau ; `false` s'il l'était déjà (rien n'est modifié).
     */
    public function suspend(CarbonImmutable $at): bool
    {
        return Cache::add(self::CACHE_KEY, $at->toIso8601String());
    }

    /**
     * Lève le drapeau ; `false` s'il n'était pas posé.
     */
    public function resume(): bool
    {
        $wasSuspended = $this->isSuspended();

        Cache::forget(self::CACHE_KEY);

        return $wasSuspended;
    }
}
