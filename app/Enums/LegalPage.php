<?php

namespace App\Enums;

/**
 * Les pages publiques du site : mentions légales, CGU, confidentialité et
 * « signaler un contenu » (spec 90 § 4.1). **Seul endroit où leur liste est
 * écrite** : le contrôleur, la page `legal/show` (type `LegalPageName` de
 * `resources/js/types/legal.ts`), la configuration `config/legal.php` et les
 * partiels Blade en découlent, et `LegalPagesTest` itère ces cas.
 *
 * Le **corps** de chaque page est rédigé en français seulement (décision 4) et
 * vit hors des dictionnaires, dans un partiel Blade du dépôt ; son
 * **habillage** (titre, bandeau provisoire, bloc de contact) passe par le
 * domaine `legal`, symétrique FR/EN (spec 90 § 4.2).
 */
enum LegalPage: string
{
    case Notice = 'notice';

    case Terms = 'terms';

    case Privacy = 'privacy';

    case Report = 'report';

    /**
     * Titre de la page, clé du domaine `legal` : `legal.notice.title`,
     * `legal.terms.title`, `legal.privacy.title`, `legal.report.title`.
     */
    public function titleKey(): string
    {
        return 'legal.'.$this->value.'.title';
    }

    /**
     * Chemin ABSOLU du partiel français, rendu par `view()->file()`.
     *
     * Un chemin et non un nom de vue : le chercheur de vues de Laravel
     * (`FileViewFinder`) remplace chaque point d'un nom par `/`, si bien que le
     * nom pointé de `legal/notice.fr.blade.php` chercherait
     * `legal/notice/fr.blade.php` et lèverait « View not found » (spec 90
     * § 4.1).
     */
    public function viewPath(): string
    {
        return resource_path("views/legal/{$this->value}.fr.blade.php");
    }
}
