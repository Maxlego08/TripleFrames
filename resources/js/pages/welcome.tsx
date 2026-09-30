import { Head, Link, router } from '@inertiajs/react';
import { useId, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { isWellFormedRoomCode, normalizeRoomCode } from '@/lib/game/room-code';
import { create as createRoom, show as showRoom } from '@/routes/room';
import { create as createSolo } from '@/routes/solo';
import type { TranslationKey } from '@/types/translations';

/**
 * Pourquoi l'envoi du code n'a pas abouti : un code mal formé, refusé ici
 * sans requête, ou une visite qui n'a reçu aucune réponse (réseau coupé).
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

/**
 * L'accueil (spec 90 § 4.7 et § 10), dans `PublicLayout` et dans
 * l'apparence du visiteur : le sélecteur de langue et la bascule d'apparence
 * sont dans l'en-tête de la coquille, le bandeau de maintenance au-dessus du
 * contenu, le pied de page en dessous. Domaines `common` et `legal`, et rien
 * d'autre : cette page n'appelle que des clés `common.*`.
 *
 * Le jeu en une phrase, puis trois entrées, sans compte (principe 10) :
 * - **Créer un salon** : lien Wayfinder `room.create` (spec 50) ;
 * - **Rejoindre** : un champ de code, pas un lien, puisqu'aucune page
 *   d'entrée n'existe sans code. La saisie passe par `normalizeRoomCode()` et
 *   `isWellFormedRoomCode()`, miroirs client de `RoomCode` : un code mal formé
 *   est refusé sous le champ, SANS requête ; un code bien formé part en visite
 *   `GET` vers `room.show` (`router.visit`, jamais `<Form>`), qui rend le
 *   lobby à un siège tenu, renvoie à la page d'entrée un visiteur sans siège,
 *   rend le salon expiré pour un salon archivé et la page `error` 404 pour un
 *   code inconnu. Aucune longueur ni aucun alphabet de code n'est écrit ici,
 *   et aucune route de recherche de salon n'existe ;
 * - **Jouer en solo** : lien Wayfinder `solo.create` (spec 60 § 16.4), où se
 *   choisit le preset, dont les libellés sont du domaine `room`.
 *
 * Aucun lien de connexion ni d'inscription dans la page : ils relèvent de
 * l'en-tête public, qui ne les rend que si `accountsOpen` est vrai (40 § 8.2),
 * c'est-à-dire jamais en production au jalon 1.
 *
 * États du champ de code : repos ; code mal formé (message sous le champ,
 * lié par `aria-describedby`, `aria-invalid`, focus rendu au champ) ;
 * navigation en cours (bouton désactivé par `aria-disabled` — il garde le
 * focus —, `aria-busy`, `Spinner` neutralisé ; un second envoi est ignoré) ;
 * réseau coupé (message générique sous le champ, bouton rendu). Les refus du
 * serveur sont des pages, rendues par la visite elle-même : `error` 404 pour
 * un code inconnu, `error` 429 au-delà du limiteur, salon expiré en 410.
 * Le message est une alerte, remontée à chaque envoi pour être relue : la
 * page n'est pas une page de jeu, où seul l'annonceur parle (C16 § 4). Il
 * s'efface dès que la saisie change, et suit la langue du visiteur, puisque
 * seule la nature de l'échec est gardée.
 *
 * Clavier : `Entrée` dans le champ envoie le code ; l'ordre de tabulation est
 * celui du document (créer, code, rejoindre, solo).
 */
export default function Welcome() {
    const { t } = useTranslations();
    const id = useId();
    const headingId = `${id}-heading`;
    const codeId = `${id}-room-code`;
    const failureId = `${id}-room-code-failure`;
    const codeInput = useRef<HTMLInputElement>(null);
    const attempts = useRef(0);
    const [code, setCode] = useState('');
    const [navigating, setNavigating] = useState(false);
    const [failure, setFailure] = useState<{
        kind: JoinFailure;
        attempt: number;
    } | null>(null);

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
        // serveur est une PAGE (`error` 404 ou 429, salon expiré en 410) :
        // Inertia 3 la passe aussi par `onHttpException`, et la retenir
        // empêcherait son rendu.
        router.visit(showRoom.url({ room: normalizeRoomCode(code) }), {
            onNetworkError: () => {
                setFailure({ kind: 'unreachable', attempt });

                return false;
            },
            onCancel: () => setNavigating(false),
            onFinish: () => setNavigating(false),
        });
    };

    return (
        <>
            <Head title={t('common.nav.home')} />

            <section
                aria-labelledby={headingId}
                className="mx-auto flex w-full max-w-2xl flex-col gap-10 px-4 py-12"
            >
                <header className="flex flex-col gap-3">
                    <h1
                        id={headingId}
                        className="text-3xl font-semibold tracking-tight text-balance"
                    >
                        {t('common.home.heading')}
                    </h1>
                    <p className="max-w-prose text-lg text-muted-foreground">
                        {t('common.home.tagline')}
                    </p>
                </header>

                <div className="flex flex-col gap-8">
                    <Button
                        asChild
                        size="lg"
                        className="min-h-11 w-full sm:w-auto sm:self-start"
                    >
                        <Link href={createRoom()}>
                            {t('common.home.create_room')}
                        </Link>
                    </Button>

                    <form
                        noValidate
                        onSubmit={join}
                        aria-busy={navigating}
                        className="flex flex-col gap-2"
                    >
                        <Label htmlFor={codeId}>
                            {t('common.home.room_code_label')}
                        </Label>

                        <div className="flex gap-2">
                            <Input
                                ref={codeInput}
                                id={codeId}
                                type="text"
                                value={code}
                                onChange={(event) => {
                                    setCode(event.target.value);
                                    setFailure(null);
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
                                    failure === null ? undefined : failureId
                                }
                                className="min-h-11 min-w-0 flex-1 font-mono uppercase sm:max-w-xs"
                            />

                            <Button
                                type="submit"
                                variant="secondary"
                                aria-disabled={navigating}
                                aria-busy={navigating}
                                className="min-h-11 shrink-0 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                            >
                                {navigating && (
                                    <Spinner
                                        aria-hidden="true"
                                        role="presentation"
                                        aria-label={undefined}
                                        className="motion-reduce:animate-none"
                                    />
                                )}
                                {t('common.home.join_room')}
                            </Button>
                        </div>

                        {failure !== null && (
                            <p
                                key={failure.attempt}
                                id={failureId}
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {t(JOIN_FAILURE_KEYS[failure.kind])}
                            </p>
                        )}
                    </form>

                    <Button
                        asChild
                        size="lg"
                        variant="outline"
                        className="min-h-11 w-full sm:w-auto sm:self-start"
                    >
                        <Link href={createSolo()}>
                            {t('common.home.play_solo')}
                        </Link>
                    </Button>
                </div>
            </section>
        </>
    );
}
