import { Form } from '@inertiajs/react';
import { useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { AvatarPicker } from '@/components/game/avatar-picker';
import type { AvatarPickerOption } from '@/components/game/avatar-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import {
    AVATAR_PRESET_LABEL_KEYS,
    isAvatarPresetKey,
} from '@/lib/game/avatar-keys';
import { terms } from '@/routes/legal';
import type { AvatarPresetKey, AvatarPresetOption } from '@/types/player';
import type { TranslationKey } from '@/types/translations';
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * Props `avatars` des pages `room/create`, `room/join` (spec 50 § 6.2 et
 * § 7.2) et `room/solo` (spec 60 § 16.4) ; contrat C5 § 3 : le catalogue,
 * les avatars des sièges tenus du salon (aucun en solo), et la présélection
 * déterministe du serveur (`suggest()`, I5.8).
 */
export type SeatAvatars = {
    options: AvatarPresetOption[];
    taken: string[];
    suggested: string;
};

/**
 * Props `nickname` : bornes du pseudo lues sur `NicknameNormalizer`, jamais
 * écrites en dur ici (C5 § 3).
 */
export type NicknameBounds = {
    min: number;
    max: number;
};

type SeatFormProps = {
    /** Cible du formulaire, par Wayfinder (`.form()`), jamais une URL écrite. */
    form: RouteFormDefinition<'post'>;
    avatars: SeatAvatars;
    nickname: NicknameBounds;
    /** Libellé DÉJÀ traduit du bouton d'envoi. */
    submitLabel: string;
    /**
     * Aide du pseudo (`:min`, `:max`) : `room.identity.nickname_hint` par
     * défaut, qui dit l'unicité dans le salon ; `room.solo.nickname_hint` en
     * solo, où aucune unicité ne s'applique (contrat C5).
     */
    nicknameHintKey?: TranslationKey;
    /**
     * Champs propres à la page, rendus EN TÊTE du formulaire et envoyés avec
     * lui — le choix du preset de `room/solo` (spec 60 § 16.4). Leurs erreurs
     * se lisent dans les props de la page.
     */
    children?: ReactNode;
    /**
     * Rappel après un refus, une fois le focus rendu au pseudo s'il est en
     * cause : la page y porte le focus sur ses propres champs.
     */
    onError?: (errors: Partial<Record<string, string>>) => void;
};

/** Champs du formulaire, tels que le serveur les valide (C5 § 2). */
const NICKNAME_FIELD = 'nickname';
const AVATAR_FIELD = 'avatar';

/**
 * Le formulaire de pseudo et d'avatar d'un siège, commun à la création du
 * salon, à l'entrée (spec 50 § 6.2 et § 7.2) et au premier siège solo (spec
 * 60 § 16.4, qui y ajoute le choix du preset en tête) ; spec 90 § 10, écran
 * « Pseudo et avatar ».
 *
 * - `<Form>` d'Inertia, jamais un état de formulaire maison ; le pseudo est
 *   un champ natif, l'avatar un `RadioGroup` à nom natif (`AvatarPicker`),
 *   que `<Form>` sérialise sans code. Le serveur reste seul juge : il
 *   canonicalise et valide le pseudo, puis tranche l'unicité sous verrou.
 *   `noValidate` : `required` et `minLength` restent une sémantique
 *   (`aria-required`), jamais une bulle du navigateur dans SA langue — un
 *   pseudo vide ou trop court part au serveur, qui rend le message traduit
 *   sous `nickname`, dans la langue du joueur.
 * - **Erreurs** liées à leur champ par `aria-describedby`, rendues aux tokens
 *   (`text-destructive`) ; après un refus de validation, le focus revient au
 *   pseudo. L'état de la page est préservé sur erreur : pseudo et avatar
 *   saisis restent en place.
 * - **Soumission** : bouton désactivé, `aria-busy`, `Spinner` neutralisé
 *   (masqué aux lecteurs d'écran, arrêté quand les animations sont
 *   réduites).
 * - **Mention des CGU** sous le bouton d'envoi : le texte
 *   `legal.terms_notice` est le lien Wayfinder vers `legal.terms`, ouvert
 *   dans un NOUVEL onglet (`legal.new_tab` en `sr-only`) — une visite dans
 *   le même onglet perdrait la saisie. Rien n'est stocké : le jeton ne porte
 *   aucun consentement (40 § 2.1).
 * - Cibles d'au moins 44 px.
 */
export function SeatForm({
    form,
    avatars,
    nickname,
    submitLabel,
    nicknameHintKey = 'room.identity.nickname_hint',
    children,
    onError,
}: SeatFormProps) {
    const { t, locale } = useTranslations();
    const id = useId();
    const nicknameId = `${id}-nickname`;
    const hintId = `${id}-nickname-hint`;
    const nicknameErrorId = `${id}-nickname-error`;
    const nicknameInput = useRef<HTMLInputElement>(null);

    const options: AvatarPickerOption[] = avatars.options
        .filter((option) => isAvatarPresetKey(option.key))
        .map((option) => ({
            key: option.key,
            url: option.url,
            label: t(AVATAR_PRESET_LABEL_KEYS[option.key]),
            taken: avatars.taken.includes(option.key),
        }));

    const [avatar, setAvatar] = useState<AvatarPresetKey | null>(() =>
        isAvatarPresetKey(avatars.suggested)
            ? avatars.suggested
            : (options[0]?.key ?? null),
    );

    const number = new Intl.NumberFormat(locale);

    return (
        <Form
            {...form}
            noValidate
            options={{ preserveScroll: true, preserveState: 'errors' }}
            onError={(errors) => {
                if (NICKNAME_FIELD in errors) {
                    nicknameInput.current?.focus();
                }

                onError?.(errors);
            }}
            className="flex flex-col gap-6"
        >
            {({ processing, errors }) => (
                <>
                    {children}

                    <div className="grid gap-2">
                        <Label htmlFor={nicknameId}>
                            {t('room.identity.nickname_label')}
                        </Label>
                        <Input
                            ref={nicknameInput}
                            id={nicknameId}
                            name={NICKNAME_FIELD}
                            required
                            minLength={nickname.min}
                            maxLength={nickname.max}
                            autoComplete="nickname"
                            spellCheck={false}
                            className="min-h-11"
                            aria-invalid={errors.nickname ? true : undefined}
                            aria-describedby={
                                errors.nickname
                                    ? `${hintId} ${nicknameErrorId}`
                                    : hintId
                            }
                        />
                        <p
                            id={hintId}
                            className="text-sm text-muted-foreground"
                        >
                            {t(nicknameHintKey, {
                                min: number.format(nickname.min),
                                max: number.format(nickname.max),
                            })}
                        </p>
                        {errors.nickname && (
                            <p
                                id={nicknameErrorId}
                                className="text-sm text-destructive"
                            >
                                {errors.nickname}
                            </p>
                        )}
                    </div>

                    {avatar !== null && (
                        <div className="grid gap-2">
                            <AvatarPicker
                                name={AVATAR_FIELD}
                                options={options}
                                value={avatar}
                                onValueChange={setAvatar}
                                legend={t('common.avatar.picker.label')}
                                takenLabel={t('common.avatar.picker.taken')}
                            />
                            {errors.avatar && (
                                <p className="text-sm text-destructive">
                                    {errors.avatar}
                                </p>
                            )}
                        </div>
                    )}

                    <div className="flex flex-col gap-3">
                        <Button
                            type="submit"
                            disabled={processing}
                            aria-busy={processing}
                            className="min-h-11 w-full sm:w-auto sm:self-start"
                        >
                            {processing && (
                                <Spinner
                                    aria-hidden="true"
                                    role="presentation"
                                    aria-label={undefined}
                                    className="motion-reduce:animate-none"
                                />
                            )}
                            {submitLabel}
                        </Button>

                        <p className="text-sm text-muted-foreground">
                            <a
                                href={terms().url}
                                target="_blank"
                                rel="noopener"
                                className="inline-flex min-h-11 items-center rounded-sm underline underline-offset-4 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {t('legal.terms_notice')}
                                <span className="sr-only">
                                    {' '}
                                    {t('legal.new_tab')}
                                </span>
                            </a>
                        </p>
                    </div>
                </>
            )}
        </Form>
    );
}
