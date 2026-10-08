import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { useId } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth, BreadcrumbItem } from '@/types';

type PageProps = {
    auth: Auth;
};

type Props = {
    mustVerifyEmail: boolean;
    status?: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'account.profile.title',
        href: edit(),
    },
];

/**
 * Réglages › Profil (spec 40, 90 § 11.2) : nom de compte, adresse e-mail,
 * renvoi du lien de vérification et déconnexion. Écran réécrit hors du
 * starter : tokens et `settings.scss` seulement, chaque erreur reliée à son
 * champ par `aria-describedby`.
 */
export default function Profile({ mustVerifyEmail, status }: Props) {
    const { auth } = usePage<PageProps>().props;
    const { t } = useTranslations();
    const headingId = useId();

    // Page rendue derrière `auth`, mais le type partagé ne le sait pas :
    // `auth.user` est nul hors connexion (spec 40 § 8.5). Une constante, pour
    // que le rétrécissement tienne jusque dans le rendu de `<Form>`.
    const user = auth.user;

    if (!user) {
        return null;
    }

    const unverified = mustVerifyEmail && user.email_verified_at === null;

    return (
        <>
            <Head title={t('account.profile.title')} />

            <h1 className="sr-only">{t('account.profile.title')}</h1>

            <section
                className="settings-section space-y-6"
                aria-labelledby={headingId}
            >
                <Heading
                    id={headingId}
                    variant="small"
                    title={t('account.profile.heading')}
                    description={t('account.profile.description')}
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('account.fields.name')}
                                </Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={user.name}
                                    name="name"
                                    required
                                    autoComplete="nickname"
                                    placeholder={t(
                                        'account.fields.name_placeholder',
                                    )}
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.name ? 'name-error' : undefined
                                    }
                                />

                                <InputError
                                    id="name-error"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('account.fields.email')}
                                </Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={user.email ?? ''}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder={t(
                                        'account.fields.email_placeholder',
                                    )}
                                    aria-invalid={
                                        errors.email ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.email ? 'email-error' : undefined
                                    }
                                />

                                <InputError
                                    id="email-error"
                                    message={errors.email}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    type="submit"
                                    className="min-h-11"
                                    disabled={processing}
                                    aria-busy={processing}
                                    data-test="update-profile-button"
                                >
                                    {t('account.profile.submit')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                {unverified && (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            {t('account.profile.unverified')}{' '}
                            <Link
                                href={send()}
                                as="button"
                                className="settings-profile__verification-link min-h-11 rounded-sm underline underline-offset-4"
                            >
                                {t('account.profile.verify_link')}
                            </Link>
                        </p>

                        <div role="status">
                            {status === 'verification-link-sent' && (
                                <p className="settings-profile__verification-sent">
                                    {t('account.profile.verification_sent')}
                                </p>
                            )}
                        </div>
                    </div>
                )}

                <div className="settings-profile__session">
                    <Button
                        variant="outline"
                        className="settings-profile__logout min-h-11"
                        asChild
                    >
                        <Link
                            href={logout()}
                            as="button"
                            onClick={() => router.flushAll()}
                            data-test="logout-button"
                        >
                            <LogOut aria-hidden="true" />
                            {t('common.nav.log_out')}
                        </Link>
                    </Button>
                </div>
            </section>
        </>
    );
}

Profile.layout = { breadcrumbs };
