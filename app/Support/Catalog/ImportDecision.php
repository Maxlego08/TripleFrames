<?php

namespace App\Support\Catalog;

/**
 * Ce que l'import a fait d'une fiche TMDB.
 *
 * **Ce n'est PAS un enum de schéma** : il ne caste aucune colonne, et il n'en
 * castera jamais une. `import_run` ne compte que des lignes — `total_seen`,
 * `total_imported`, `total_skipped`, `total_refused_content` —, jamais un
 * verdict par film : le verdict d'un film importé vit sur `movie`
 * (`is_import_exception`, les trois motifs, `content_flag`), et celui d'un film
 * refusé n'a aucune ligne où vivre, par construction. Il reste donc hors de
 * `App\Enums`, qui est le domicile des seuls casts de colonnes.
 *
 * Les quatre compteurs de `import_run` s'en déduisent par
 * {@see ImportOutcome::countersFor()}.
 */
enum ImportDecision: string
{
    /** Le film est entré au catalogue. */
    case Imported = 'imported';

    /** Le film était déjà là ; ses métadonnées TMDB ont été relues (§ 9.3). */
    case Resynchronized = 'resynchronized';

    /** Le film était déjà là, et la voie employée n'écrase rien. */
    case Duplicate = 'duplicate';

    /**
     * Le filtre de **goût** l'a écarté. N'arrive que sur un balayage
     * `discover` : la voie d'exception l'ignore entièrement (§ 9.2).
     */
    case SkippedByFilter = 'skipped_by_filter';

    /**
     * Le filtre de **contenu** l'a refusé — `adult`, FR -18, US NC-17, US X.
     * Arrive sur **les deux** voies, décision 12, et c'est la seule porte que
     * rien ne contourne.
     */
    case RefusedContent = 'refused_content';

    /**
     * Un retrait juridique a déjà été prononcé sur ce `tmdb_id`. L'unique
     * `movie_tmdb_uq` est à lui seul le blocage de réimport : aucune table de
     * bannissement (A10).
     */
    case RefusedWithdrawn = 'refused_withdrawn';

    /** TMDB ne connaît pas cet identifiant — donnée ordinaire d'un collage. */
    case NotFound = 'not_found';

    /** Le film est entré au catalogue sans que rien n'ait été écrit (`--dry-run`). */
    case Simulated = 'simulated';
}
