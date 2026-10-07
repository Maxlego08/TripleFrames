import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
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

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'account.profile.title',
        href: edit(),
    },
];

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;
    const { t } = useTranslations();

    // Page rendue derrière `auth`, mais le type partagé ne le sait pas :
    // `auth.user` est nul hors connexion (spec 40 § 8.5). Une constante, pour
    // que le rétrécissement tienne jusque dans le rendu de `<Form>`.
    const user = auth.user;

    if (!user) {
        return null;
    }

    const handleLogout = () => {
        router.flushAll();
    };

    return (
        <>
            <Head title={t('account.profile.title')} />

            <h1 className="sr-only">{t('account.profile.title')}</h1>

            <div className="settings-section space-y-6">
                <Heading
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
                                    autoComplete="name"
                                    placeholder={t(
                                        'account.fields.name_placeholder',
                                    )}
                                />

                                <InputError
                                    className="mt-2"
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
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            {t('account.profile.unverified')}{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="settings-profile__verification-link rounded-sm underline underline-offset-4"
                                            >
                                                {t(
                                                    'account.profile.verify_link',
                                                )}
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="settings-profile__verification-sent">
                                                {t(
                                                    'account.profile.verification_sent',
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    {t('account.profile.submit')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="settings-profile__session">
                    <Button
                        variant="outline"
                        className="settings-profile__logout"
                        asChild
                    >
                        <Link
                            href={logout()}
                            as="button"
                            onClick={handleLogout}
                            data-test="logout-button"
                        >
                            <LogOut aria-hidden="true" />
                            {t('common.nav.log_out')}
                        </Link>
                    </Button>
                </div>
            </div>
        </>
    );
}

Profile.layout = { breadcrumbs };
