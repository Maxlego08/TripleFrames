import { Form, Head, usePage } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { useId } from 'react';
import LinkedAccountController from '@/actions/App/Http/Controllers/Settings/LinkedAccountController';
import {
    isOAuthProvider,
    OAuthButtons,
    useProviderName,
} from '@/components/account/oauth-buttons';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import { edit } from '@/routes/linked_accounts';
import type { BreadcrumbItem } from '@/types';

type ProviderRow = {
    provider: string;
    linked: boolean;
    linkedAt: string | null;
};

type Props = {
    providers: ProviderRow[];
    hasPassword: boolean;
    /** Vrai sous 2FA confirmée : la déliaison exige un code. */
    requiresCode: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'account.linked.title',
        href: edit(),
    },
];

/**
 * L'écran « Comptes liés » (spec 40 § 12.5, D51 du 01/10) : par fournisseur
 * actif, « Lier » (aller-retour d'intention `link`) ou « Délier » (boîte de
 * confirmation ; un code sous 2FA). La déliaison passe d'abord par la
 * confirmation fraîche de la route, par mot de passe ou par un fournisseur.
 * Un refus — dernière méthode, code faux — revient sous la boîte ou en tête
 * d'écran (`oauth`).
 */
export default function LinkedAccounts({ providers, requiresCode }: Props) {
    const { t, locale } = useTranslations();
    const providerName = useProviderName();
    const { errors } = usePage<{
        errors: Partial<Record<string, string>>;
    }>().props;
    const date = new Intl.DateTimeFormat(locale, { dateStyle: 'long' });

    return (
        <>
            <Head title={t('account.linked.title')} />

            <h1 className="sr-only">{t('account.linked.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('account.linked.heading')}
                    description={t('account.linked.description')}
                />

                <InputError message={errors.oauth ?? errors.provider} />

                {providers.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('account.linked.none')}
                    </p>
                )}

                <ul className="flex flex-col gap-3">
                    {providers
                        .filter((row) => isOAuthProvider(row.provider))
                        .map((row) => {
                            const name = isOAuthProvider(row.provider)
                                ? providerName(row.provider)
                                : row.provider;

                            return (
                                <li
                                    key={row.provider}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border p-3"
                                >
                                    <div className="flex flex-col gap-1">
                                        <span className="font-medium">
                                            {name}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {row.linked &&
                                            row.linkedAt !== null ? (
                                                t('account.linked.linked_on', {
                                                    date: date.format(
                                                        new Date(row.linkedAt),
                                                    ),
                                                })
                                            ) : (
                                                <Badge variant="outline">
                                                    {t(
                                                        'account.linked.not_linked',
                                                    )}
                                                </Badge>
                                            )}
                                        </span>
                                    </div>

                                    {row.linked ? (
                                        <UnlinkDialog
                                            provider={row.provider}
                                            name={name}
                                            requiresCode={requiresCode}
                                        />
                                    ) : (
                                        <div className="min-w-48">
                                            <OAuthButtons
                                                providers={[row.provider]}
                                                intent="link"
                                            />
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                </ul>
            </div>
        </>
    );
}

type UnlinkDialogProps = {
    provider: string;
    name: string;
    requiresCode: boolean;
};

function UnlinkDialog({ provider, name, requiresCode }: UnlinkDialogProps) {
    const { t } = useTranslations();
    const codeId = useId();

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline" className="min-h-11">
                    {t('account.linked.unlink')}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md [&>button:last-child]:hidden">
                <DialogClose asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={t('common.action.close')}
                        className="absolute top-3 right-3 min-h-11 min-w-11"
                    >
                        <XIcon aria-hidden="true" />
                    </Button>
                </DialogClose>
                <Form
                    {...LinkedAccountController.destroy.form({ provider })}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader className="pr-12">
                                <DialogTitle>
                                    {t('account.linked.unlink_title', {
                                        provider: name,
                                    })}
                                </DialogTitle>
                                <DialogDescription>
                                    {t('account.linked.unlink_body', {
                                        provider: name,
                                    })}
                                </DialogDescription>
                            </DialogHeader>

                            {requiresCode && (
                                <div className="grid gap-2">
                                    <Label htmlFor={codeId}>
                                        {t('account.linked.code_label')}
                                    </Label>
                                    <Input
                                        id={codeId}
                                        name="code"
                                        autoComplete="one-time-code"
                                        aria-describedby={`${codeId}-help`}
                                    />
                                    <p
                                        id={`${codeId}-help`}
                                        className="text-sm text-muted-foreground"
                                    >
                                        {t('account.linked.code_help')}
                                    </p>
                                    <InputError message={errors.code} />
                                </div>
                            )}

                            <InputError message={errors.provider} />

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        {t('account.linked.cancel')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    aria-busy={processing}
                                    className="min-h-11"
                                >
                                    {t('account.linked.unlink')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

LinkedAccounts.layout = { breadcrumbs };
