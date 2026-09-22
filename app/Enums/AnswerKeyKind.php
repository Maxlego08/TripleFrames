<?php

namespace App\Enums;

/** Nature d'une clé de réponse projetée, seule `prefix` étant soumise à la règle de collision : cast de `answer_key.key_kind`. */
enum AnswerKeyKind: string
{
    case TitleOriginal = 'title_original';

    case TitleLatin = 'title_latin';

    case Title = 'title';

    case Alias = 'alias';

    case Prefix = 'prefix';

    /** Précédence sur `(movie_id, normalized)` : toute nature exacte l'emporte sur `prefix`. */
    public function isExact(): bool
    {
        return $this !== self::Prefix;
    }
}
