<?php

namespace App\Enums;

/** Nature de l'appariement retenu pour une bonne réponse, distincte d'`AnswerKeyKind` dont elle ne partage pas le cas `choice` : cast de `guess.match_kind`. */
enum GuessMatchKind: string
{
    case Title = 'title';

    case Alias = 'alias';

    case Prefix = 'prefix';

    case Choice = 'choice';
}
