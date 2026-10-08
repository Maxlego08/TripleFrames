import { Form, Head } from '@inertiajs/react';
import { ConsentFields } from '@/components/account/consent-fields';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { home, logout } from '@/routes';
import { accept } from '@/routes/terms';
import type { AuthLayoutKeys } from '@/types';

type Props = {
    /** Aucune version acceptée jusqu'ici (premier administrateur). */
    firstAcceptance: boolean;
    /** Le compte n'a jamais déclaré son âge : la case d'âge est demandée. */
    asksAge: boolean;
};

/**
 * L'interstitiel de ré-acceptation des CGU (spec 40 § 13.1, Q40-6) : les
 * fonctions du compte restent suspendues tant que la version courante n'est
 * pas acceptée ; le jeu, lui, ne l'est jamais, et la déconnexion reste
 * offerte.
 */
export default function TermsUpdate({ firstAcceptance, asksAge }: Props) {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('account.terms_update.title')} />

            <p className="text-sm text-muted-foreground">
                {firstAcceptance
                    ? t('account.terms_update.description_first')
                    : t('account.terms_update.description')}
            </p>

            <Form {...accept.form()} className="flex flex-col gap-6">
                {({ processing, errors }) => (
                    <>
                        <ConsentFields errors={errors} withAge={asksAge} />

                        <Button
                            type="submit"
                            className="min-h-11 w-full"
                            disabled={processing}
                            aria-busy={processing}
                            data-test="terms-accept-button"
                        >
                            {processing && (
                                <Spinner
                                    aria-label={t('common.state.loading')}
                                />
                            )}
                            {t('account.terms_update.submit')}
                        </Button>
                    </>
                )}
            </Form>

            <p className="text-sm text-muted-foreground">
                {t('account.terms_update.play_note')}
            </p>

            <div className="flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm">
                <TextLink href={home()}>
                    {t('account.terms_update.play')}
                </TextLink>
                <TextLink href={logout()}>
                    {t('account.terms_update.logout')}
                </TextLink>
            </div>
        </>
    );
}

TermsUpdate.layout = {
    title: 'account.terms_update.title',
} satisfies AuthLayoutKeys;
