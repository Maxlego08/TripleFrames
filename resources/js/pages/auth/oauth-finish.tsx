import { Form, Head } from '@inertiajs/react';
import { useId } from 'react';
import {
    isOAuthProvider,
    useProviderName,
} from '@/components/account/oauth-buttons';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { terms } from '@/routes/legal';
import { store } from '@/routes/oauth/finish';
import type { AuthLayoutKeys } from '@/types';

type Props = {
    provider: string;
    /** L'adresse reçue du fournisseur, `null` s'il n'en a transmis aucune. */
    email: string | null;
    /** Le nom suggéré par le fournisseur : un préremplissage, jamais imposé. */
    suggestedName: string | null;
};

/**
 * « Finaliser l'inscription » (spec 40 § 12.3, D51 du 01/10) : le nom du
 * compte, prérempli et modifiable, l'acceptation des CGU (lien en nouvel
 * onglet, pour ne pas perdre la saisie) et la déclaration d'âge. Le serveur
 * date les deux consentements et celui du fournisseur à la création.
 */
export default function OAuthFinish({ provider, email, suggestedName }: Props) {
    const { t } = useTranslations();
    const providerName = useProviderName();
    const id = useId();
    const name = isOAuthProvider(provider) ? providerName(provider) : provider;

    return (
        <>
            <Head title={t('account.oauth.finish.title')} />

            <p className="text-sm text-muted-foreground">
                {t('account.oauth.finish.description', { provider: name })}
            </p>

            <p className="text-sm">
                {email === null
                    ? t('account.oauth.finish.no_email', { provider: name })
                    : t('account.oauth.finish.email', {
                          provider: name,
                          email,
                      })}
            </p>

            <Form {...store.form()} className="flex flex-col gap-6">
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-name`}>
                                {t('account.oauth.finish.name')}
                            </Label>
                            <Input
                                id={`${id}-name`}
                                name="name"
                                required
                                autoFocus
                                autoComplete="name"
                                defaultValue={suggestedName ?? ''}
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id={`${id}-terms`}
                                    name="terms"
                                    value="1"
                                />
                                <Label htmlFor={`${id}-terms`}>
                                    {t('account.oauth.finish.terms')}
                                </Label>
                            </div>
                            <a
                                href={terms().url}
                                target="_blank"
                                rel="noopener"
                                className="text-sm underline underline-offset-4"
                            >
                                {t('account.oauth.finish.terms_link')}
                                <span className="sr-only">
                                    {' '}
                                    {t('legal.new_tab')}
                                </span>
                            </a>
                            <InputError message={errors.terms} />
                        </div>

                        <div className="grid gap-2">
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id={`${id}-age`}
                                    name="age"
                                    value="1"
                                />
                                <Label htmlFor={`${id}-age`}>
                                    {t('account.oauth.finish.age')}
                                </Label>
                            </div>
                            <InputError message={errors.age} />
                        </div>

                        <Button
                            type="submit"
                            className="min-h-11 w-full"
                            disabled={processing}
                            aria-busy={processing}
                        >
                            {processing && (
                                <Spinner
                                    aria-label={t('common.state.loading')}
                                />
                            )}
                            {t('account.oauth.finish.submit')}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

OAuthFinish.layout = {
    title: 'account.oauth.finish.heading',
} satisfies AuthLayoutKeys;
