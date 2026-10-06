import { Form, Link, usePage } from '@inertiajs/react';
import { useId } from 'react';
import ConsentController from '@/actions/App/Http/Controllers/Legal/ConsentController';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { privacy } from '@/routes/legal';

type ConsentValue = 'accepted' | 'refused';

/** Ancre de la section « Cookies » de la politique de confidentialité. */
export const CONSENT_SETTINGS_ID = 'cookies';

/**
 * Les deux boutons du choix, de **même poids** (CNIL : refuser doit être
 * aussi simple qu'accepter) — chacun son propre envoi, sans JavaScript de
 * plus que le `<Form>` d'Inertia.
 */
function ConsentButtons() {
    const { t } = useTranslations();

    return (
        <div className="flex flex-wrap gap-2">
            {(['refused', 'accepted'] as const).map((choice: ConsentValue) => (
                <Form
                    key={choice}
                    {...ConsentController.store.form()}
                    options={{ preserveScroll: true, preserveState: true }}
                >
                    {({ processing }) => (
                        <>
                            <input type="hidden" name="choice" value={choice} />
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                className="min-h-11 min-w-28"
                            >
                                {choice === 'accepted'
                                    ? t('legal.consent.accept')
                                    : t('legal.consent.refuse')}
                            </Button>
                        </>
                    )}
                </Form>
            ))}
        </div>
    );
}

/**
 * La bannière de consentement (D62 du 06/10, spec 90 § 4.6) : affichée tant
 * que le visiteur n'a pas répondu à la version courante (prop partagée
 * `consent` nulle), dans les coquilles publique et de jeu. Elle ne bloque
 * rien — on joue exactement pareil sans répondre — et n'ouvre aucune région
 * `aria-live` : c'est une région nommée, superposée en bas de la coquille.
 *
 * Composant de présentation : props partagées seulement, tokens seulement.
 */
export function ConsentBanner() {
    const { t } = useTranslations();
    const consent = usePage().props.consent;
    const headingId = useId();

    if (consent !== null) {
        return null;
    }

    return (
        <section
            aria-labelledby={headingId}
            className="absolute inset-x-0 bottom-0 z-50 bg-background/80 px-4 py-3 text-foreground"
        >
            <div className="mx-auto flex max-w-5xl flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div className="space-y-1 text-sm">
                    <h2 id={headingId} className="font-semibold">
                        {t('legal.consent.title')}
                    </h2>
                    <p className="text-muted-foreground">
                        {t('legal.consent.body')}{' '}
                        <Link
                            href={`${privacy().url}#${CONSENT_SETTINGS_ID}`}
                            className="text-foreground underline underline-offset-4"
                        >
                            {t('legal.consent.more')}
                        </Link>
                    </p>
                </div>
                <ConsentButtons />
            </div>
        </section>
    );
}

/**
 * « Vos préférences de cookies » sur la politique de confidentialité : le
 * choix en cours, et de quoi le changer à tout moment — le retrait du
 * consentement supprime le visiteur et son cookie.
 */
export function ConsentSettings() {
    const { t } = useTranslations();
    const consent = usePage().props.consent;

    return (
        <section
            id={CONSENT_SETTINGS_ID}
            aria-labelledby={`${CONSENT_SETTINGS_ID}-heading`}
            className="space-y-3"
        >
            <h2 id={`${CONSENT_SETTINGS_ID}-heading`}>
                {t('legal.consent.settings_heading')}
            </h2>
            <p>
                {consent === 'accepted'
                    ? t('legal.consent.current_accepted')
                    : consent === 'refused'
                      ? t('legal.consent.current_refused')
                      : t('legal.consent.current_none')}
            </p>
            <ConsentButtons />
        </section>
    );
}
