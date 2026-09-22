<?php

namespace App\Enums;

/** Régime de disponibilité d'un contenu, un seul axe et cinq états : cast de `movie.availability` et `frame.availability`. */
enum ContentAvailability: string
{
    case Draft = 'draft';

    case Published = 'published';

    case Unpublished = 'unpublished';

    case Suspended = 'suspended';

    case Withdrawn = 'withdrawn';

    /** Seule valeur qui met en jeu ; les conditions de publication, elles, vivent au § 4.3 de la spec 10. */
    public function isPlayable(): bool
    {
        return $this === self::Published;
    }

    /** Retrait juridique : aucune transition n'en sort. */
    public function isTerminal(): bool
    {
        return $this === self::Withdrawn;
    }
}
