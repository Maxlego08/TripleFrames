<?php

namespace App\Enums;

/** Portée réellement retenue par l'administrateur, distincte du `claimed_scope` verbatim du demandeur : cast de `takedown_request.scope_kind`. */
enum TakedownScopeKind: string
{
    case Movie = 'movie';

    case Frame = 'frame';

    case Site = 'site';
}
