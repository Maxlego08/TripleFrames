import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye, EyeOff, Sparkles } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import RoomEntryController from '@/actions/App/Http/Controllers/Room/RoomEntryController';
import { BrandMark } from '@/components/auth/auth-brand';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { isWellFormedRoomCode, normalizeRoomCode } from '@/lib/game/room-code';
import { terms } from '@/routes/legal';
import { create as createRoom } from '@/routes/room';
import { create as createSolo } from '@/routes/solo';
import type { TranslationKey } from '@/types/translations';

/**
 * Pourquoi l'envoi n'a pas abouti côté client : un code mal formé, refusé ici
 * sans requête, ou un envoi qui n'a reçu aucune réponse (réseau coupé).
 */
type JoinFailure = 'invalid' | 'unreachable';

/**
 * Le message de chaque échec, par une table écrite ici et jamais par une clé
 * construite à l'exécution (spec 90 § 6.7).
 */
const JOIN_FAILURE_KEYS: Record<JoinFailure, TranslationKey> = {
    invalid: 'common.home.room_code_invalid',
    unreachable: 'common.state.error',
};

/** Champ du pseudo, tel que `JoinRoomRequest` le valide. */
const NICKNAME_FIELD = 'nickname';

/**
 * L'accueil (spec 90 § 4.7 et § 10), dans `PublicLayout` : le sélecteur de
 * langue est dans l'en-tête de la coquille, le bandeau de maintenance
 * au-dessus du contenu, le pied de page en dessous. Domaines `common` et `legal`, et rien
 * d'autre : cette page n'appelle que des clés `common.*` et `legal.*`.
 *
 * Le jeu en une phrase, puis trois entrées, sans compte (principe 10) :
 * - **Créer un salon** : lien Wayfinder `room.create` (spec 50) ;
 * - **Rejoindre** (D55 du 02/10) : le code du salon ET le pseudo, en un seul
 *   envoi. Le code passe par `normalizeRoomCode()` et
 *   `isWellFormedRoomCode()`, miroirs client de `RoomCode` : un code mal
 *   formé est refusé sous son champ, SANS requête ; un code bien formé part
 *   en `POST` vers `room.join` (`RoomEntryController@store`, Wayfinder), avec
 *   le seul pseudo — aucun avatar : le serveur l'attribue à la prise de
 *   siège, et il se change au lobby. Le serveur rend le lobby au siège pris ;
 *   un pseudo refusé revient sous `errors.nickname`, un salon complet ou un
 *   jeton expulsé sous `errors.room` (alerte de la carte), un salon expiré
 *   en page de salon expiré, un code inconnu en page `error` 404 (le pseudo
 *   saisi est alors perdu). Un visiteur qui tient DÉJÀ un siège de ce salon
 *   y est repris sous son pseudo d'origine : le pseudo tapé est ignoré.
 *   Aucune longueur ni aucun alphabet de code, aucune borne de pseudo n'est
 *   écrite ici : le serveur valide et rend le message traduit ;
 * - **Jouer en solo** : lien Wayfinder `solo.create` (spec 60 § 16.4), où se
 *   choisit le preset, dont les libellés sont du domaine `room`.
 *
 * La mention des CGU (`legal.terms_notice`) est sous le bouton, comme au
 * formulaire de siège : un lien Wayfinder vers `legal.terms` ouvert dans un
 * NOUVEL onglet (`legal.new_tab` en `sr-only`), pour ne pas perdre la saisie.
 *
 * Aucun lien de connexion ni d'inscription dans la page : ils relèvent de
 * l'en-tête public, qui ne les rend que si `accountsOpen` est vrai (40 § 8.2),
 * c'est-à-dire jamais en production au jalon 1.
 *
 * États : repos ; code mal formé (message sous le champ, lié par
 * `aria-describedby`, `aria-invalid`, focus rendu au champ) ; envoi en cours
 * (bouton désactivé par `aria-disabled` — il garde le focus —, `aria-busy`,
 * `Spinner` neutralisé ; un second envoi est ignoré) ; réseau coupé (message
 * générique sous le code, bouton rendu) ; pseudo refusé (message du serveur
 * sous le pseudo, focus rendu au pseudo) ; refus du salon (alerte). Un
 * message s'efface dès que la saisie de son champ change. L'état de la page
 * est préservé sur erreur : code et pseudo restent en place. Les autres
 * réponses du serveur sont des pages (`error` 404 ou 429, salon expiré) :
 * elles ne sont jamais retenues ici.
 *
 * Clavier : `Entrée` dans un champ envoie ; l'ordre de tabulation est celui
 * du document (code, pseudo, rejoindre, CGU, créer, solo).
 */
export default function Welcome() {
    const { t } = useTranslations();
    const { errors } = usePage().props;
    const id = useId();
    const headingId = `${id}-heading`;
    const codeId = `${id}-room-code`;
    const codeHelpId = `${id}-room-code-help`;
    const failureId = `${id}-room-code-failure`;
    const nicknameId = `${id}-nickname`;
    const nicknameErrorId = `${id}-nickname-error`;
    const codeInput = useRef<HTMLInputElement>(null);
    const nicknameInput = useRef<HTMLInputElement>(null);
    const attempts = useRef(0);
    const [code, setCode] = useState('');
    // Code masqué par défaut, pour qu'un joueur qui diffuse son écran ne
    // le montre pas à ses spectateurs ; le bouton œil l'affiche à la demande.
    const [codeVisible, setCodeVisible] = useState(false);
    const [nickname, setNickname] = useState('');
    const [navigating, setNavigating] = useState(false);
    const [failure, setFailure] = useState<{
        kind: JoinFailure;
        attempt: number;
    } | null>(null);
    // Un refus du serveur reste dans les props jusqu'à la visite suivante :
    // il est tu dès que la saisie de son champ change.
    const [edited, setEdited] = useState({ nickname: false, room: false });
    // L'essai qui a reçu le dernier refus du serveur : l'alerte est remontée
    // à chaque refus, pour qu'un refus identique soit annoncé de nouveau.
    const [refusedAttempt, setRefusedAttempt] = useState(0);

    const nicknameError =
        !edited.nickname && typeof errors.nickname === 'string'
            ? errors.nickname
            : null;
    const roomError =
        !edited.room && typeof errors.room === 'string' ? errors.room : null;

    const join = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (navigating) {
            return;
        }

        attempts.current += 1;
        const attempt = attempts.current;

        if (!isWellFormedRoomCode(code)) {
            setFailure({ kind: 'invalid', attempt });
            codeInput.current?.focus();

            return;
        }

        setFailure(null);
        setNavigating(true);

        // Seule la coupure réseau est rattrapée ici. Une réponse d'erreur du
        // serveur est une PAGE (`error` 404 ou 429, salon expiré) : Inertia 3
        // la passe aussi par `onHttpException`, et la retenir empêcherait son
        // rendu. Les refus de validation, eux, reviennent en `errors`.
        router.post(
            RoomEntryController.store.url({ room: normalizeRoomCode(code) }),
            { [NICKNAME_FIELD]: nickname },
            {
                preserveScroll: 'errors',
                preserveState: 'errors',
                onStart: () => setEdited({ nickname: false, room: false }),
                onError: (failed) => {
                    setRefusedAttempt(attempt);

                    if (NICKNAME_FIELD in failed) {
                        nicknameInput.current?.focus();
                    }
                },
                onNetworkError: () => {
                    setFailure({ kind: 'unreachable', attempt });

                    return false;
                },
                onCancel: () => setNavigating(false),
                onFinish: () => setNavigating(false),
            },
        );
    };

    return (
        <>
            <Head title={t('common.nav.home')} />

            <section aria-labelledby={headingId} className="home-stage">
                <div className="home-hero">
                    <div className="home-hero__content">
                        <BrandMark className="home-hero__logo" />

                        <header className="home-hero__text">
                            <h1 id={headingId}>{t('common.home.heading')}</h1>
                            <p>{t('common.home.tagline')}</p>
                        </header>
                    </div>

                    <form
                        noValidate
                        onSubmit={join}
                        aria-busy={navigating}
                        className="home-join-card"
                    >
                        <header className="home-join-card__heading">
                            <h2>{t('common.home.join_heading')}</h2>
                        </header>

                        <p className="home-join-card__mobile-intro">
                            {t('common.home.tagline')}
                        </p>

                        <div className="home-field">
                            <Label htmlFor={codeId}>
                                {t('common.home.room_code_label')}
                            </Label>
                            <div className="home-field__control">
                                <Input
                                    ref={codeInput}
                                    id={codeId}
                                    // Masqué : `password` sans en être un — les
                                    // gestionnaires de mots de passe sont priés
                                    // de l'ignorer, et rien n'est mémorisé.
                                    type={codeVisible ? 'text' : 'password'}
                                    data-1p-ignore
                                    data-lpignore="true"
                                    data-bwignore
                                    data-form-type="other"
                                    value={code}
                                    placeholder={t(
                                        'common.home.room_code_placeholder',
                                    )}
                                    onChange={(event) => {
                                        setCode(event.target.value);
                                        setFailure(null);
                                        setEdited((before) => ({
                                            ...before,
                                            room: true,
                                        }));
                                    }}
                                    autoComplete="off"
                                    autoCorrect="off"
                                    autoCapitalize="characters"
                                    spellCheck={false}
                                    enterKeyHint="go"
                                    aria-invalid={
                                        failure?.kind === 'invalid'
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={
                                        failure === null
                                            ? codeHelpId
                                            : `${codeHelpId} ${failureId}`
                                    }
                                    className="home-field__input home-field__input--code"
                                />
                                <button
                                    type="button"
                                    className="home-field__reveal"
                                    aria-controls={codeId}
                                    aria-pressed={codeVisible}
                                    aria-label={
                                        codeVisible
                                            ? t('common.home.room_code_hide')
                                            : t('common.home.room_code_show')
                                    }
                                    onClick={() =>
                                        setCodeVisible((visible) => !visible)
                                    }
                                >
                                    {codeVisible ? (
                                        <EyeOff aria-hidden="true" />
                                    ) : (
                                        <Eye aria-hidden="true" />
                                    )}
                                </button>
                            </div>

                            <p id={codeHelpId} className="home-field__help">
                                {t('common.home.room_code_help')}
                            </p>

                            {failure !== null && (
                                <p
                                    key={failure.attempt}
                                    id={failureId}
                                    role="alert"
                                    className="home-field__error"
                                >
                                    {t(JOIN_FAILURE_KEYS[failure.kind])}
                                </p>
                            )}
                        </div>

                        <div className="home-field">
                            <Label htmlFor={nicknameId}>
                                {t('common.home.nickname_label')}
                            </Label>
                            <Input
                                ref={nicknameInput}
                                id={nicknameId}
                                type="text"
                                name={NICKNAME_FIELD}
                                value={nickname}
                                placeholder={t(
                                    'common.home.nickname_placeholder',
                                )}
                                onChange={(event) => {
                                    setNickname(event.target.value);
                                    setEdited((before) => ({
                                        ...before,
                                        nickname: true,
                                    }));
                                }}
                                required
                                autoComplete="nickname"
                                spellCheck={false}
                                enterKeyHint="go"
                                aria-invalid={
                                    nicknameError === null ? undefined : true
                                }
                                aria-describedby={
                                    nicknameError === null
                                        ? undefined
                                        : nicknameErrorId
                                }
                                className="home-field__input home-field__input--nickname"
                            />

                            {nicknameError !== null && (
                                <p
                                    id={nicknameErrorId}
                                    className="home-field__error"
                                >
                                    {nicknameError}
                                </p>
                            )}
                        </div>

                        {roomError !== null && (
                            <p
                                key={refusedAttempt}
                                role="alert"
                                className="home-join-card__alert"
                            >
                                {roomError}
                            </p>
                        )}

                        <Button
                            type="submit"
                            aria-disabled={navigating}
                            aria-busy={navigating}
                            className="home-button home-button--primary"
                        >
                            {navigating ? (
                                <Spinner
                                    aria-hidden="true"
                                    role="presentation"
                                    aria-label={undefined}
                                    className="motion-reduce:animate-none"
                                />
                            ) : (
                                <Sparkles aria-hidden="true" />
                            )}
                            {t('common.home.join_room')}
                        </Button>

                        <p className="home-join-card__terms">
                            <a
                                href={terms().url}
                                target="_blank"
                                rel="noopener"
                            >
                                {t('legal.terms_notice')}
                                <span className="sr-only">
                                    {' '}
                                    {t('legal.new_tab')}
                                </span>
                            </a>
                        </p>
                    </form>
                </div>

                <div className="home-actions">
                    <div className="home-actions__copy">
                        <p>
                            <strong>
                                {t('common.home.create_prompt_lead')}
                            </strong>{' '}
                            {t('common.home.create_prompt')}
                        </p>
                    </div>

                    <div className="home-actions__buttons">
                        <Button
                            asChild
                            className="home-button home-button--secondary"
                        >
                            <Link href={createRoom()}>
                                {t('common.home.create_room')}
                            </Link>
                        </Button>

                        <Button
                            asChild
                            variant="outline"
                            className="home-button home-button--solo"
                        >
                            <Link href={createSolo()}>
                                {t('common.home.play_solo')}
                            </Link>
                        </Button>
                    </div>
                </div>
            </section>
        </>
    );
}
