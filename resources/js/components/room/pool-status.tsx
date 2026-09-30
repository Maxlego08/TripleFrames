import { Link } from '@inertiajs/react';
import { CircleAlert, Info, TriangleAlert } from 'lucide-react';
import { useId } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { create } from '@/routes/room';
import type {
    PoolFault,
    PoolRemedy,
    PoolRemedyKind,
    PoolReport,
} from '@/types/pool';
import type { TranslationKey } from '@/types/translations';

type PoolStatusProps = {
    /** `settings.pool` : le rapport de 30, identique pour tous les sièges. */
    pool: PoolReport;
    /**
     * Remèdes proposés à ce siège : l'hôte seul les voit et les clique
     * (§ 8.1) ; `null` pour les autres sièges, qui voient le même compteur et
     * le même blocage, sans bouton.
     */
    remedies: {
        /** Écriture en cours, onglet supplanté ou connexion perdue. */
        disabled: boolean;
        /** Applique un remède d'écriture (tout remède sauf `open_new_room`). */
        onApply: (remedy: PoolRemedy) => void;
        /**
         * Erreur, déjà traduite, de la dernière application refusée : rendue
         * en `Alert` au rôle `note`, liée à chaque remède d'écriture par
         * `aria-describedby` (§ 8.1, état « erreur ») ; la page l'annonce.
         */
        error: string | null;
    } | null;
};

/** Le réglage fautif, nommé — une ligne par cas de `PoolFault` (§ 9.2). */
const CAUSE_KEYS: Record<PoolFault, TranslationKey> = {
    noRepeatMovies: 'room.pool.cause.noRepeatMovies',
    themeKeys: 'room.pool.cause.themeKeys',
    framesPerRound: 'room.pool.cause.framesPerRound',
    roundsCount: 'room.pool.cause.roundsCount',
};

/** Le geste proposé — une clé par cas de `PoolRemedyKind` (§ 9.2). */
const REMEDY_KEYS: Record<PoolRemedyKind, TranslationKey> = {
    open_new_room: 'room.pool.remedy.open_new_room',
    disable_no_repeat: 'room.pool.remedy.disable_no_repeat',
    clear_themes: 'room.pool.remedy.clear_themes',
    lower_frames_per_round: 'room.pool.remedy.lower_frames_per_round',
    reduce_rounds_count: 'room.pool.remedy.reduce_rounds_count',
};

/**
 * Compteur de vivier et blocage du lancement (spec 50 § 9.2, D28 du 23/09),
 * rendus par chaque client à partir du rapport en DONNÉES de 30 — jamais une
 * chaîne composée par le serveur.
 *
 * - **Compteur** pour tous : `room.pool.counter` (films jouables, en œuvres,
 *   pour `M` manches).
 * - **Blocage** pour tous, même contenu pour tous : `room.pool.blocked`, puis
 *   **le réglage fautif nommé**, une ligne par cause, dans l'ordre fixe de
 *   30 — non-répétition, thèmes, images par manche, manches. Passer de N = 3
 *   à N = 5 peut faire tomber le vivier de 47 œuvres à 4 : l'hôte doit
 *   comprendre que ce n'est pas un problème de thèmes. Aucune cause alors que
 *   le vivier est bloqué : `room.pool.no_remedy`, le catalogue est
 *   insuffisant.
 * - **Thème élagué** : `room.pool.themes_pruned` pour tous, bloqué ou non.
 * - **Remèdes**, hôte seul, chacun débloquant à lui seul : « nouveau salon »
 *   est un lien vers la création (un nouveau salon a une mémoire vide) — le
 *   remède proposé au J1 quand la non-répétition est seule en cause (D28 du
 *   23/09) ; les autres écrivent les réglages par la page. Le serveur relit
 *   tout à l'écriture et au lancement : ce composant n'est qu'une aide.
 *
 * Les nombres sont formatés dans la langue du joueur (`Intl.NumberFormat`).
 * Aucun rôle vivant : les `Alert` portent le rôle `note`, l'annonceur de la
 * coquille reste la seule région qui parle (C16 § 4).
 */
export function PoolStatus({ pool, remedies }: PoolStatusProps) {
    const { t, locale } = useTranslations();
    const number = new Intl.NumberFormat(locale);
    const errorId = useId();
    const remedyError = remedies?.error ?? null;

    const remedyLabel = (remedy: PoolRemedy): string =>
        t(REMEDY_KEYS[remedy.kind], {
            count: number.format(remedy.count),
            value: remedy.value === null ? '' : number.format(remedy.value),
        });

    return (
        <section className="flex flex-col gap-3">
            <p className="font-medium">
                {t('room.pool.counter', {
                    playable: number.format(pool.count),
                    required: number.format(pool.roundsCount),
                })}
            </p>

            {pool.themesPruned && (
                <Alert role="note">
                    <Info aria-hidden="true" />
                    <AlertDescription className="text-foreground">
                        {t('room.pool.themes_pruned')}
                    </AlertDescription>
                </Alert>
            )}

            {pool.blocked && (
                <Alert role="note">
                    <TriangleAlert aria-hidden="true" />
                    {/* Le titre porte le blocage entier : jamais rogné. */}
                    <AlertTitle className="line-clamp-none">
                        {t('room.pool.blocked')}
                    </AlertTitle>
                    <AlertDescription className="text-foreground">
                        {pool.causes.length === 0 ? (
                            <p>{t('room.pool.no_remedy')}</p>
                        ) : (
                            <ul className="flex list-disc flex-col gap-1 ps-5">
                                {pool.causes.map((cause) => (
                                    <li key={cause}>{t(CAUSE_KEYS[cause])}</li>
                                ))}
                            </ul>
                        )}
                    </AlertDescription>
                </Alert>
            )}

            {remedies !== null && pool.blocked && pool.remedies.length > 0 && (
                <ul className="flex flex-col gap-2">
                    {pool.remedies.map((remedy) => (
                        <li key={remedy.kind}>
                            {remedy.kind === 'open_new_room' ? (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="h-auto min-h-11 w-full justify-start text-start whitespace-normal"
                                >
                                    <Link href={create()}>
                                        {remedyLabel(remedy)}
                                    </Link>
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={remedies.disabled}
                                    aria-describedby={
                                        remedyError !== null
                                            ? errorId
                                            : undefined
                                    }
                                    onClick={() => remedies.onApply(remedy)}
                                    className="h-auto min-h-11 w-full justify-start text-start whitespace-normal"
                                >
                                    {remedyLabel(remedy)}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {remedyError !== null && (
                <Alert role="note" id={errorId}>
                    <CircleAlert aria-hidden="true" />
                    <AlertDescription className="text-foreground">
                        {remedyError}
                    </AlertDescription>
                </Alert>
            )}
        </section>
    );
}
