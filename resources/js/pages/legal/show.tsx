import { ConsentSettings } from '@/components/public/consent-banner';
import { Head } from '@inertiajs/react';
import { InfoIcon } from 'lucide-react';
import { useMemo } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useTranslations } from '@/hooks/use-translations';
import { prepareLegalDocument } from '@/lib/legal-document';
import type { LegalPageName, LegalPageProps } from '@/types/legal';
import type { TranslationKey } from '@/types/translations';

/**
 * Titre de chaque page, par une table écrite ici et jamais par une clé
 * construite à l'exécution (spec 90 § 6.7) : `t()` est typé par
 * `TranslationKey`, et une clé composée échapperait à la vérification des
 * clés réellement appelées.
 */
const TITLE_KEYS: Record<LegalPageName, TranslationKey> = {
    notice: 'legal.notice.title',
    terms: 'legal.terms.title',
    privacy: 'legal.privacy.title',
    report: 'legal.report.title',
};

const DESCRIPTION_KEYS: Record<LegalPageName, TranslationKey> = {
    notice: 'legal.notice.description',
    terms: 'legal.terms.description',
    privacy: 'legal.privacy.description',
    report: 'legal.report.description',
};

/** Langue du corps : les textes légaux sont rédigés en français seulement. */
const BODY_LOCALE = 'fr';

/**
 * Un jour `AAAA-MM-JJ` en date longue de la locale du visiteur, ou `null`.
 * Lu comme minuit UTC et formaté en UTC : formaté dans le fuseau local, il
 * s'afficherait la veille pour tout visiteur à l'ouest de Greenwich
 * (spec 90 § 4.2).
 */
function formatDay(day: string, locale: string): string | null {
    const date = new Date(`${day}T00:00:00Z`);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'long',
        timeZone: 'UTC',
    }).format(date);
}

/**
 * Page publique unique — mentions légales, CGU, confidentialité et
 * « signaler un contenu » (spec 90 § 4), dans `PublicLayout`.
 *
 * **Habillage traduit, corps en français.** Le titre, le bandeau provisoire,
 * l'avertissement « disponible en français seulement », la date de mise à
 * jour et le bloc de contact suivent la langue du visiteur, comme
 * `<html lang>` ; le corps, partiel Blade du dépôt, est injecté dans un
 * conteneur `lang="fr"`, pour qu'un lecteur d'écran le prononce en français
 * (spec 05 § Attribut `lang`). Contenu venu du dépôt seulement, jamais de la
 * base : c'est ce qui rend sûre cette injection.
 *
 * Page statique, en lecture seule : aucun formulaire, « signaler un contenu »
 * compris, au jalon 1 (spec 90 § 4.5). Aucune donnée à charger, donc aucun
 * état de chargement propre ; une erreur de chargement est la page `error`.
 */
export default function LegalShow({
    page,
    body,
    provisional,
    updatedAt,
    contactEmail,
}: LegalPageProps) {
    const { t, locale } = useTranslations();
    const title = t(TITLE_KEYS[page]);
    const updatedOn = updatedAt === null ? null : formatDay(updatedAt, locale);
    const document = useMemo(() => prepareLegalDocument(body), [body]);

    return (
        <>
            <Head title={title} />

            <section className="legal-page">
                <div className="legal-page__inner">
                    <header className="legal-hero">
                        <h1>{title}</h1>
                        <p>{t(DESCRIPTION_KEYS[page])}</p>
                    </header>

                    {provisional && (
                        <Alert role="note" className="legal-note">
                            <InfoIcon aria-hidden="true" />
                            <AlertDescription>
                                {t('legal.provisional')}
                            </AlertDescription>
                        </Alert>
                    )}

                    {locale !== BODY_LOCALE && (
                        <p className="legal-language-note">
                            {t('legal.french_only')}
                        </p>
                    )}

                    <div className="legal-surface legal-document">
                        <nav
                            className="legal-toc"
                            aria-label={t('legal.toc.label')}
                        >
                            <div className="legal-toc__inner">
                                <h2>{t('legal.toc.title')}</h2>
                                {/* Les intitulés viennent du corps français :
                                    `lang` sur la liste, jamais sur le
                                    `<nav>`, dont le titre suit le visiteur. */}
                                <ol lang="fr">
                                    {document.sections.map((section) => (
                                        <li key={section.id}>
                                            <a href={`#${section.id}`}>
                                                {section.label}
                                            </a>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </nav>

                        <article className="legal-content">
                            {updatedOn !== null && (
                                <div className="legal-meta">
                                    <span>
                                        {t('legal.updated_at', {
                                            date: updatedOn,
                                        })}
                                    </span>
                                </div>
                            )}

                            <div
                                lang="fr"
                                className="legal-body"
                                dangerouslySetInnerHTML={{
                                    __html: document.html,
                                }}
                            />

                            {page === 'privacy' && (
                                <div className="legal-body">
                                    <ConsentSettings />
                                </div>
                            )}

                            <ContactBlock email={contactEmail} />

                            <a
                                className="legal-back-to-top"
                                href="#public-main"
                            >
                                {t('legal.back_to_top')}
                            </a>
                        </article>
                    </div>
                </div>
            </section>
        </>
    );
}

/**
 * Bloc de contact de l'habillage, vers lequel renvoient les partiels. Sans
 * adresse publiée, la mention `legal.contact.unavailable` remplace la phrase :
 * une adresse qui ne répond pas est pire qu'aucune (spec 90 § 4.3).
 */
function ContactBlock({ email }: { email: string | null }) {
    const { t } = useTranslations();

    return (
        <section
            aria-labelledby="legal-contact-heading"
            className="legal-contact"
        >
            <h2 id="legal-contact-heading">{t('legal.contact.heading')}</h2>

            {email === null ? (
                <p>{t('legal.contact.unavailable')}</p>
            ) : (
                <ContactSentence
                    sentence={t('legal.contact.description', { email })}
                    email={email}
                />
            )}
        </section>
    );
}

/**
 * La phrase traduite, dont l'adresse devient un lien `mailto:`. La phrase est
 * découpée autour de l'adresse déjà interpolée : aucun fragment de texte n'est
 * écrit ici, et une traduction qui placerait l'adresse ailleurs reste juste.
 */
function ContactSentence({
    sentence,
    email,
}: {
    sentence: string;
    email: string;
}) {
    const at = sentence.indexOf(email);

    if (at === -1) {
        return <p>{sentence}</p>;
    }

    return (
        <p>
            {sentence.slice(0, at)}
            <a href={`mailto:${email}`}>{email}</a>
            {sentence.slice(at + email.length)}
        </p>
    );
}
