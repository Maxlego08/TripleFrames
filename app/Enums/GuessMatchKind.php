<?php

namespace App\Enums;

/**
 * Nature de l'appariement retenu pour une bonne réponse, distincte
 * d'`AnswerKeyKind` dont elle ne partage pas le cas `choice` : cast de
 * `guess.match_kind`. Une clé retenue s'y traduit par
 * {@see AnswerKeyKind::toMatchKind()}, jamais par une correspondance recopiée.
 */
enum GuessMatchKind: string
{
    case Title = 'title';

    case Alias = 'alias';

    case Prefix = 'prefix';

    /** Sous-titre dérivé accepté (D23 du 23/09) ; 8 caractères, sous la largeur `string(10)`. */
    case Subtitle = 'subtitle';

    case Choice = 'choice';
}
