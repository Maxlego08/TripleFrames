import { setLayoutProps } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';
import type { NicknameBounds } from '@/components/room/seat-form';
import { DESIGN_ROOM_CODE } from '@/lib/design/game-fixtures';
import { useDesignSandbox } from '@/lib/design/sandbox';
import { isPublicScenarioKey } from '@/lib/design/scenario-keys';
import type { PublicScenarioKey } from '@/lib/design/scenario-keys';
import ErrorPage from '@/pages/error';
import ReportCreate from '@/pages/report/create';
import RoomJoin from '@/pages/room/join';

type ReportProps = ComponentProps<typeof ReportCreate>;

/** Props de `design.frame` lues par cet hôte (`DesignPreviewController`). */
type PublicDesignPreviewProps = {
    scenario: string;
    images: string[];
    nickname: NicknameBounds;
    report: {
        scopes: ReportProps['scopes'];
        reasons: ReportProps['reasons'];
        commentMaxLength: number;
    };
};

/** Ce que l'hôte monte : la page et son nom, pour la variante de coquille. */
type PublicScene = { component: string; page: ReactNode };

/** Le film fictif de la page « Signaler » : une donnée de test. */
const SAMPLE_MOVIE: ReportProps['movie'] = {
    title: 'Le Voyage de Lumière',
    titleLang: 'fr',
    year: 1997,
    tmdb: 100_001,
};

function sceneOf(
    key: PublicScenarioKey,
    props: PublicDesignPreviewProps,
): PublicScene {
    const join = (entry: ComponentProps<typeof RoomJoin>['entry']) => ({
        component: 'room/join',
        page: (
            <RoomJoin
                room={{ code: DESIGN_ROOM_CODE }}
                entry={entry}
                nickname={props.nickname}
            />
        ),
    });
    const report = (withFrame: boolean): PublicScene => ({
        component: 'report/create',
        page: (
            <ReportCreate
                movie={SAMPLE_MOVIE}
                frame={
                    withFrame
                        ? {
                              publicId: 'DSGNFRAME001',
                              imageUrl: props.images[0] ?? null,
                          }
                        : null
                }
                scopes={
                    withFrame
                        ? props.report.scopes
                        : props.report.scopes.filter(
                              (scope) => scope === 'movie',
                          )
                }
                reasons={props.report.reasons}
                commentMaxLength={props.report.commentMaxLength}
                canReport
                alreadyReported={{
                    movie: false,
                    frame: withFrame ? false : null,
                }}
            />
        ),
    });
    const error = (status: ComponentProps<typeof ErrorPage>['status']) => ({
        component: 'error',
        page: <ErrorPage status={status} />,
    });

    switch (key) {
        case 'public.room_join_open':
            return join('open');
        case 'public.room_join_late_join':
            return join('late_join');
        case 'public.room_join_in_progress':
            return join('in_progress');
        case 'public.room_join_full':
            return join('full');
        case 'public.room_join_kicked':
            return join('kicked');
        case 'public.report_movie':
            return report(false);
        case 'public.report_frame':
            return report(true);
        case 'public.error_403':
            return error(403);
        case 'public.error_404':
            return error(404);
        case 'public.error_419':
            return error(419);
        case 'public.error_429':
            return error(429);
        case 'public.error_500':
            return error(500);
        case 'public.error_503':
            return error(503);
    }
}

/**
 * Hôte des scénarios `public.*` du banc d'essai du design (spec 20 § 13.8,
 * demande du porteur du 08/10), sous `PublicLayout` par son nom de page
 * (`legal/*`) : il monte la VRAIE page — entrée d'un salon fictif dans ses
 * cinq états, « Signaler » un film ou une image, page d'erreur de chaque
 * statut — et passe à la coquille le nom de la page montée, pour sa
 * variante. Le bac à sable annule tout envoi.
 */
export default function PublicDesignPreview(props: PublicDesignPreviewProps) {
    useDesignSandbox();

    const scene = isPublicScenarioKey(props.scenario)
        ? sceneOf(props.scenario, props)
        : null;

    setLayoutProps({ previewComponent: scene?.component });

    return scene?.page ?? null;
}
