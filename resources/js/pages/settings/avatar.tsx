import { Form, Head, router, usePage } from '@inertiajs/react';
import { CircleAlert, XIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import AvatarController from '@/actions/App/Http/Controllers/Settings/AvatarController';
import { AvatarCropper } from '@/components/account/avatar-cropper';
import {
    isOAuthProvider,
    useProviderName,
} from '@/components/account/oauth-buttons';
import { AvatarPicker } from '@/components/game/avatar-picker';
import type { AvatarPickerOption } from '@/components/game/avatar-picker';
import { PlayerAvatar } from '@/components/game/player-avatar';
import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { useTranslations } from '@/hooks/use-translations';
import {
    AVATAR_PRESET_LABEL_KEYS,
    avatarAltKey,
    isAvatarPresetKey,
} from '@/lib/game/avatar-keys';
import { edit } from '@/routes/avatar';
import type { BreadcrumbItem } from '@/types';
import { ACCOUNT_AVATAR_CHOICE, PROVIDER_AVATAR_CHOICE } from '@/types/player';
import type {
    AvatarData,
    AvatarPresetOption,
    SeatAvatarChoice,
} from '@/types/player';

type Props = {
    avatar: AvatarData;
    kind: 'preset' | 'provider' | 'upload' | null;
    preset: string | null;
    options: AvatarPresetOption[];
    /** La copie de la photo du fournisseur, seulement visible (spec 40 § 12.6). */
    provider: { url: string | null; source: string | null };
    upload: {
        /** `avatar.show`, seulement pour une image visible. */
        url: string | null;
        stored: boolean;
        blocked: boolean;
        maxKilobytes: number;
        sourceSize: number;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'account.avatar.title',
        href: edit(),
    },
];

/** Formats proposés au sélecteur de fichier ; le serveur relit les octets. */
const ACCEPTED_TYPES = 'image/jpeg,image/png,image/webp';

/**
 * L'écran « Avatar » des réglages du compte (spec 40 § 11, D49 du 01/10).
 *
 * - **Choix** : un prédéfini ou « Mon avatar » (l'image téléversée, quand
 *   elle est visible), par le même sélecteur qu'au siège ; `<Form>` Wayfinder.
 * - **Téléversement** : un fichier, recadré au carré dans le navigateur
 *   (`AvatarCropper`), envoyé par `router.post` en `FormData`. L'erreur
 *   traduite du serveur revient sous `avatar`.
 * - **Masquage** : l'état se lit ici et nulle part ailleurs au J1 ; le
 *   téléversement est alors fermé, jamais caché sans explication.
 */
export default function AvatarSettings({
    avatar,
    kind,
    preset,
    options,
    upload,
    provider,
}: Props) {
    const { t } = useTranslations();
    const { errors } = usePage<{ errors: Partial<Record<string, string>> }>()
        .props;
    const fileInput = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [sending, setSending] = useState(false);
    const [readError, setReadError] = useState(false);

    const pickerOptions: AvatarPickerOption[] = options
        .filter((option) => isAvatarPresetKey(option.key))
        .map((option) => ({
            key: option.key,
            url: option.url,
            label: t(AVATAR_PRESET_LABEL_KEYS[option.key]),
            taken: false,
        }));

    const providerName = useProviderName();
    const providerLabel =
        provider.source !== null && isOAuthProvider(provider.source)
            ? providerName(provider.source)
            : '';

    const initial: SeatAvatarChoice | null =
        kind === 'upload' && upload.url !== null
            ? ACCOUNT_AVATAR_CHOICE
            : kind === 'provider' && provider.url !== null
              ? PROVIDER_AVATAR_CHOICE
              : preset !== null && isAvatarPresetKey(preset)
                ? preset
                : (pickerOptions[0]?.key ?? null);
    const [choice, setChoice] = useState<SeatAvatarChoice | null>(initial);

    const send = (image: File): void => {
        router.post(
            AvatarController.store.url(),
            { avatar: image },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setSending(true),
                onSuccess: () => setFile(null),
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <>
            <Head title={t('account.avatar.title')} />

            <h1 className="sr-only">{t('account.avatar.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('account.avatar.heading')}
                    description={t('account.avatar.description')}
                />

                <div className="flex items-center gap-4">
                    <PlayerAvatar
                        avatar={avatar}
                        alt={t(avatarAltKey(avatar.altKey))}
                        className="size-20 text-xl"
                    />
                    <p className="text-sm text-muted-foreground">
                        {t('account.avatar.current')}
                    </p>
                </div>

                {upload.blocked && (
                    <Alert role="note">
                        <CircleAlert aria-hidden="true" />
                        <AlertDescription className="text-foreground">
                            {t('account.avatar.hidden_notice')}
                        </AlertDescription>
                    </Alert>
                )}

                {choice !== null && (
                    <Form
                        {...AvatarController.update.form()}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ processing, errors: formErrors }) => (
                            <>
                                <AvatarPicker
                                    name="avatar"
                                    options={pickerOptions}
                                    account={
                                        upload.url === null
                                            ? null
                                            : {
                                                  url: upload.url,
                                                  label: t(
                                                      'common.avatar.picker.account',
                                                  ),
                                              }
                                    }
                                    extraAccounts={
                                        provider.url === null
                                            ? []
                                            : [
                                                  {
                                                      url: provider.url,
                                                      label: t(
                                                          'account.avatar.use_provider',
                                                          {
                                                              provider:
                                                                  providerLabel,
                                                          },
                                                      ),
                                                      value: PROVIDER_AVATAR_CHOICE,
                                                  },
                                              ]
                                    }
                                    value={choice}
                                    onValueChange={setChoice}
                                    legend={t('account.avatar.choice_legend')}
                                    takenLabel=""
                                />
                                {formErrors.avatar && (
                                    <p className="text-sm text-destructive">
                                        {formErrors.avatar}
                                    </p>
                                )}
                                <Button
                                    disabled={processing}
                                    aria-busy={processing}
                                    className="min-h-11"
                                >
                                    {t('account.avatar.save')}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('account.avatar.upload_heading')}
                    description={t('account.avatar.upload_description')}
                />

                <p className="text-sm text-muted-foreground">
                    {t('account.avatar.rules')}
                </p>

                {!upload.blocked && file === null && (
                    <div className="flex flex-wrap gap-2">
                        <input
                            ref={fileInput}
                            type="file"
                            accept={ACCEPTED_TYPES}
                            className="sr-only"
                            tabIndex={-1}
                            aria-hidden="true"
                            onChange={(event) => {
                                const chosen = event.target.files?.[0] ?? null;
                                event.target.value = '';
                                setReadError(false);
                                setFile(chosen);
                            }}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => fileInput.current?.click()}
                        >
                            {upload.stored
                                ? t('account.avatar.replace_file')
                                : t('account.avatar.choose_file')}
                        </Button>
                    </div>
                )}

                {!upload.blocked && file !== null && (
                    <AvatarCropper
                        file={file}
                        outputSize={upload.sourceSize}
                        maxKilobytes={upload.maxKilobytes}
                        busy={sending}
                        labels={{
                            help: t('account.avatar.crop_help'),
                            zoom: t('account.avatar.zoom'),
                            preview: t('account.avatar.preview_alt'),
                            confirm: t('account.avatar.upload'),
                            cancel: t('account.avatar.cancel'),
                            busy: t('account.avatar.uploading'),
                        }}
                        onCancel={() => setFile(null)}
                        onCropped={send}
                        onUnreadable={() => {
                            setFile(null);
                            setReadError(true);
                        }}
                    />
                )}

                {readError && (
                    <p className="text-sm text-destructive">
                        {t('account.avatar.errors.read_failed')}
                    </p>
                )}

                {errors.avatar && file === null && (
                    <p className="text-sm text-destructive">{errors.avatar}</p>
                )}

                {upload.stored && (
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button
                                type="button"
                                variant="destructive"
                                className="min-h-11"
                            >
                                {t('account.avatar.delete')}
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
                            <DialogHeader className="pr-12">
                                <DialogTitle>
                                    {t('account.avatar.delete')}
                                </DialogTitle>
                                <DialogDescription>
                                    {t('account.avatar.delete_confirm')}
                                </DialogDescription>
                            </DialogHeader>
                            <Form
                                {...AvatarController.destroy.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="min-h-11"
                                            >
                                                {t('account.avatar.cancel')}
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                            aria-busy={processing}
                                            className="min-h-11"
                                        >
                                            {t('account.avatar.delete')}
                                        </Button>
                                    </DialogFooter>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                )}
            </div>
        </>
    );
}

AvatarSettings.layout = { breadcrumbs };
