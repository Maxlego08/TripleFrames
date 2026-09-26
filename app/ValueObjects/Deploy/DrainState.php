<?php

namespace App\ValueObjects\Deploy;

use App\Enums\DrainPhase;
use App\Support\Deploy\DeployDrain;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Le drapeau de drainage tel qu'il vit dans le cache — spec 100 § 11.3,
 * contrat C18-bis § 2 et § 3 (noms et signatures figés).
 *
 * Valeur du cache : `{phase, startedAt, expiresAt}`, instants en ISO-8601
 * UTC à la milliseconde (`2026-09-26T14:00:00.250Z`). `startedAt` est
 * l'instant où le drainage a commencé, conservé à l'ouverture de la fenêtre ;
 * `expiresAt` est l'échéance de la phase en cours : celle du drainage, puis
 * celle de la fenêtre. Le TTL de l'entrée la suit ({@see self::ttlSeconds()}),
 * si bien qu'un drapeau abandonné par une commande morte s'efface seul.
 *
 * **Jamais envoyé à un joueur** : les pages ne reçoivent que le booléen
 * `maintenance` ({@see DeployDrain::isDraining()}) — ni heure, ni phase.
 */
final readonly class DrainState
{
    /** Format des deux instants : ISO-8601, UTC, à la milliseconde. */
    private const string FORMAT = 'Y-m-d\TH:i:s.v\Z';

    /** Forme exacte d'un instant relu ; `D` refuse un saut de ligne final. */
    private const string PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D';

    /** Millisecondes par seconde : le TTL du cache se compte en secondes. */
    private const int MILLISECONDS_PER_SECOND = 1000;

    /** Plancher du TTL : une entrée à TTL nul ne serait jamais écrite. */
    private const int MIN_TTL_SECONDS = 1;

    public function __construct(
        public DrainPhase $phase,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $expiresAt,
    ) {}

    /**
     * La valeur écrite dans le cache.
     *
     * @return array{phase: string, startedAt: string, expiresAt: string}
     */
    public function toCache(): array
    {
        return [
            'phase' => $this->phase->value,
            'startedAt' => self::format($this->startedAt),
            'expiresAt' => self::format($this->expiresAt),
        ];
    }

    /**
     * Relit une valeur du cache ; `null` pour tout ce qui n'est pas un drapeau
     * lisible (entrée absente, phase inconnue, instant mal formé ou
     * impossible). Un drapeau illisible ne protège rien : il vaut « aucun
     * drapeau » pour tous les lecteurs.
     */
    public static function fromCache(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $phase = is_string($raw['phase'] ?? null) ? DrainPhase::tryFrom($raw['phase']) : null;
        $startedAt = self::parse($raw['startedAt'] ?? null);
        $expiresAt = self::parse($raw['expiresAt'] ?? null);

        if ($phase === null || $startedAt === null || $expiresAt === null) {
            return null;
        }

        return new self($phase, $startedAt, $expiresAt);
    }

    /** Vrai dès l'échéance atteinte : le drapeau ne vaut plus rien. */
    public function isExpiredAt(CarbonImmutable $now): bool
    {
        return ! $now->lessThan($this->expiresAt);
    }

    /**
     * TTL de l'entrée du cache : `expiresAt − now`, en secondes entières
     * arrondies au-dessus, au moins une (§ 11.3). L'arrondi au-dessus peut
     * garder l'entrée moins d'une seconde après l'échéance ; les lecteurs
     * l'ignorent alors ({@see self::isExpiredAt()}), et le sens du drapeau
     * reste exact à la milliseconde.
     */
    public function ttlSeconds(CarbonImmutable $now): int
    {
        $remainingMs = (int) $this->expiresAt->getPreciseTimestamp(3) - (int) $now->getPreciseTimestamp(3);

        return max(self::MIN_TTL_SECONDS, (int) ceil($remainingMs / self::MILLISECONDS_PER_SECOND));
    }

    private static function format(CarbonImmutable $instant): string
    {
        return $instant->utc()->format(self::FORMAT);
    }

    /**
     * Un instant relu, ou `null` : forme exacte, puis aller-retour à
     * l'identique — `createFromFormat` accepterait un 30 février en le
     * reportant en mars.
     */
    private static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }

        try {
            $instant = CarbonImmutable::createFromFormat(self::FORMAT, $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        if (! $instant instanceof CarbonImmutable || self::format($instant) !== $value) {
            return null;
        }

        return $instant;
    }
}
