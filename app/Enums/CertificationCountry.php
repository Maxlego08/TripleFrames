<?php

namespace App\Enums;

/** Pays dont la certification est retenue, en `char(2)` majuscule : cast de `movie_certification.country`. */
enum CertificationCountry: string
{
    case France = 'FR';

    case UnitedStates = 'US';
}
