<?php

namespace App\Support\Catalog;

use App\Enums\CertificationCountry;
use Carbon\CarbonImmutable;

/**
 * La certification **retenue** pour un pays — une seule, et c'est l'UNIQUE
 * `movie_cert_movie_country_uq` qui l'impose (§ 3.8).
 *
 * La résolution « la plus récente fait foi » se fait **en PHP** à la lecture de
 * `release_dates`, jamais en SQL, et seule la gagnante est stockée : sans
 * `released_on`, la règle ne serait pas vérifiable, et Orange mécanique, classé
 * X puis reclassé, serait exclu à tort.
 */
final readonly class ResolvedCertification
{
    public function __construct(
        public CertificationCountry $country,
        public string $certification,
        public ?CarbonImmutable $releasedOn,
        public bool $isRestrictive,
    ) {}

    /**
     * Les colonnes de `movie_certification`, `read_at` compris — c'est lui que
     * l'écran de différences d'une resynchronisation compare.
     *
     * @return array{country: CertificationCountry, certification: string, released_on: CarbonImmutable|null, is_restrictive: bool, read_at: CarbonImmutable}
     */
    public function toAttributes(CarbonImmutable $readAt): array
    {
        return [
            'country' => $this->country,
            'certification' => $this->certification,
            'released_on' => $this->releasedOn,
            'is_restrictive' => $this->isRestrictive,
            'read_at' => $readAt,
        ];
    }
}
