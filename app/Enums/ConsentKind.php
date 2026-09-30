<?php

namespace App\Enums;

/** Nature d'un consentement daté et conservé en ajout seul : cast de `user_consent.kind`. */
enum ConsentKind: string
{
    case Terms = 'terms';

    case Age = 'age';
}
