<?php

namespace App\Support\Catalog;

/**
 * Ce qu'un texte saisi au back-office deviendra dans `answer_key` — et donc
 * quelles formes {@see AmbiguityPreview::forText()} en dérive (spec 20 § 8.2,
 * § 9.1, § 9.2).
 *
 * Un **titre** produit sa forme exacte, plus le préfixe et le sous-titre que
 * le projecteur en dérive ; un **alias** ne produit que sa forme exacte, un
 * alias ne donnant jamais de forme dérivée (décision 13, D23 du 23/09).
 */
enum TextTarget: string
{
    case Title = 'title';

    case Alias = 'alias';
}
