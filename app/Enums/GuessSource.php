<?php

namespace App\Enums;

/** Voie de saisie d'une bonne réponse : cast de `guess.source`. */
enum GuessSource: string
{
    case Text = 'text';

    case Choice = 'choice';
}
