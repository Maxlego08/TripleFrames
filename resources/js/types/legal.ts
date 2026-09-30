/**
 * Pages publiques (spec 90 § 4.1 et § 4.2) : miroir client des cas de
 * `App\Enums\LegalPage`, seul endroit côté serveur où leur liste est écrite.
 */
export type LegalPageName = 'notice' | 'terms' | 'privacy' | 'report';

/** Props de la page Inertia `legal/show`, posées par `LegalPageController`. */
export type LegalPageProps = {
    page: LegalPageName;
    /**
     * HTML du partiel Blade FRANÇAIS de la page, rendu côté serveur. Contenu
     * du dépôt seulement, jamais de la base : c'est ce qui rend sûre son
     * injection. Toujours rendu dans un conteneur `lang="fr"`.
     */
    body: string;
    /** `config('legal.pages.{page}.provisional')` : bandeau « texte provisoire ». */
    provisional: boolean;
    /**
     * Jour de dernière mise à jour, `AAAA-MM-JJ`, ou `null`. Formaté par
     * `Intl.DateTimeFormat` avec `timeZone: 'UTC'` : lu comme minuit UTC, il
     * s'afficherait sinon la veille pour tout visiteur à l'ouest de Greenwich.
     */
    updatedAt: string | null;
    /**
     * Adresse de contact publiée, ou `null` : le bloc de contact affiche alors
     * `legal.contact.unavailable`. Une variable d'environnement vide arrive
     * déjà `null` (le serveur la traite comme absente).
     */
    contactEmail: string | null;
};
