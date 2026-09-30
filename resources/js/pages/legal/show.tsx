import { Head } from '@inertiajs/react';
import { InfoIcon } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useTranslations } from '@/hooks/use-translations';
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

/** Langue du corps : les textes légaux sont rédigés en français seulement. */
const BODY_LOCALE = 'fr';

/**
 * Mise en forme du corps par sélecteurs descendants, aux tokens : les
 * partiels Blade n'ont aucune classe, un re-skin ne les touche jamais
 * (spec 90 § 4.2). Un tableau trop large défile dans sa région (`role="region"`,
 * étiquetée et focalisable, pour qu'on la fasse défiler au clavier), jamais la
 * page : aucun défilement horizontal à 360 de large. Le tableau garde son
 * affichage de tableau, donc sa sémantique pour un lecteur d'écran.
 */
const BODY_CLASS =
    'flex max-w-prose flex-col gap-3 text-sm leading-relaxed [&_a]:rounded-sm [&_a]:underline [&_a]:underline-offset-4 [&_a]:hover:text-foreground [&_a]:focus-visible:ring-2 [&_a]:focus-visible:ring-ring [&_a]:focus-visible:outline-none [&_code]:rounded-sm [&_code]:bg-muted [&_code]:px-1 [&_code]:font-mono [&_code]:text-xs [&_h2]:mt-4 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-foreground [&_[role=region]]:max-w-full [&_[role=region]]:overflow-x-auto [&_[role=region]]:rounded-sm [&_[role=region]]:focus-visible:ring-2 [&_[role=region]]:focus-visible:ring-ring [&_[role=region]]:focus-visible:outline-none [&_table]:w-full [&_table]:border-collapse [&_td]:border [&_td]:border-border [&_td]:px-2 [&_td]:py-1 [&_td]:align-top [&_th]:border [&_th]:border-border [&_th]:bg-muted [&_th]:px-2 [&_th]:py-1 [&_th]:text-left [&_th]:font-medium [&_ul]:flex [&_ul]:list-disc [&_ul]:flex-col [&_ul]:gap-1 [&_ul]:pl-5';

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

    return (
        <>
            <Head title={title} />

            <article className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-8">
                <header className="flex flex-col gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {title}
                    </h1>

                    {provisional && (
                        <Alert role="note">
                            <InfoIcon aria-hidden="true" />
                            <AlertDescription>
                                {t('legal.provisional')}
                            </AlertDescription>
                        </Alert>
                    )}

                    {locale !== BODY_LOCALE && (
                        <p className="text-sm text-muted-foreground">
                            {t('legal.french_only')}
                        </p>
                    )}

                    {updatedOn !== null && (
                        <p className="text-sm text-muted-foreground">
                            {t('legal.updated_at', { date: updatedOn })}
                        </p>
                    )}
                </header>

                <div
                    lang="fr"
                    className={BODY_CLASS}
                    dangerouslySetInnerHTML={{ __html: body }}
                />

                <ContactBlock email={contactEmail} />
            </article>
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
            className="flex flex-col gap-2 border-t border-border pt-6 text-sm"
        >
            <h2 id="legal-contact-heading" className="text-lg font-semibold">
                {t('legal.contact.heading')}
            </h2>

            {email === null ? (
                <p className="text-muted-foreground">
                    {t('legal.contact.unavailable')}
                </p>
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
            <a
                href={`mailto:${email}`}
                className="rounded-sm underline underline-offset-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {email}
            </a>
            {sentence.slice(at + email.length)}
        </p>
    );
}
