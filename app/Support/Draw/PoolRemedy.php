<?php

namespace App\Support\Draw;

use App\Enums\PoolRemedyKind;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Un remède d'un vivier bloqué, en données (spec 30 § 4.3, contrat C2).
 *
 * `count` est le vivier obtenu en appliquant le remède seul, en œuvres ;
 * `value` porte le réglage proposé quand le remède en a un — le `N` jouable le
 * plus proche, ou le `M` réduit au vivier courant —, `null` sinon.
 *
 * Avec {@see PoolReport}, seule classe sérialisable de `App\Support\Draw` : elle
 * ne porte que des entiers et un code, jamais un identifiant interne ni une
 * chaîne à afficher (règles 3 et 4).
 *
 * @implements Arrayable<string, string|int|null>
 */
final readonly class PoolRemedy implements Arrayable
{
    public function __construct(
        public PoolRemedyKind $kind,
        public ?int $value,
        public int $count,
    ) {}

    /**
     * @return array{kind: string, value: int|null, count: int}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'value' => $this->value,
            'count' => $this->count,
        ];
    }
}
