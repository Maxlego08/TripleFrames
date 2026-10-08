import { Head } from '@inertiajs/react';
import { ExternalLink, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminSelect } from '@/components/admin/admin-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import {
    isFixtureScenarioKey,
    isLiveScenarioKey,
} from '@/lib/design/scenario-keys';
import type { LiveScenarioKey } from '@/lib/design/scenario-keys';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as designIndex } from '@/routes/admin/design';
import { edit as avatarEdit } from '@/routes/avatar';
import { frame as designFrame } from '@/routes/design';
import { index as historyIndex } from '@/routes/history';
import { notice, privacy, terms } from '@/routes/legal';
import { edit as linkedAccountsEdit } from '@/routes/linked_accounts';
import { edit as profileEdit } from '@/routes/profile';
import { create as roomCreate } from '@/routes/room';
import { edit as securityEdit } from '@/routes/security';
import { create as soloCreate } from '@/routes/solo';
import { create as takedownCreate } from '@/routes/takedown';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type ScenarioLabelKey = Extract<
    TranslationKey,
    `admin.design.scenario.${string}`
>;
type GroupLabelKey = Extract<TranslationKey, `admin.design.group.${string}`>;

/** Un scénario du registre serveur (`DesignScenarios::forIndex()`). */
type Scenario = {
    key: string;
    kind: 'live' | 'fixture';
    group: string;
    labelKey: ScenarioLabelKey;
};

type Props = {
    scenarios: Scenario[];
    groups: { key: string; labelKey: GroupLabelKey }[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.design', href: designIndex() },
];

/** La vraie URL de chaque scénario « live », par Wayfinder. */
const LIVE_SCENARIO_URLS: Record<LiveScenarioKey, () => string> = {
    'live.home': () => home.url(),
    'live.room_create': () => roomCreate.url(),
    'live.solo_create': () => soloCreate.url(),
    'live.settings_profile': () => profileEdit.url(),
    'live.settings_security': () => securityEdit.url(),
    'live.settings_avatar': () => avatarEdit.url(),
    'live.settings_accounts': () => linkedAccountsEdit.url(),
    'live.settings_history': () => historyIndex.url(),
    'live.legal_notice': () => notice.url(),
    'live.legal_terms': () => terms.url(),
    'live.legal_privacy': () => privacy.url(),
    'live.takedown': () => takedownCreate.url(),
};

/**
 * Largeurs d'affichage de l'aperçu, en rem (1 rem = 16 unités CSS) : jamais
 * en `px` (règle 5). `null` : toute la largeur disponible.
 */
type ViewportKey = 'mobile_small' | 'mobile' | 'tablet' | 'desktop' | 'full';

const VIEWPORTS: Record<
    ViewportKey,
    { label: TranslationKey; size: { width: string; height: string } | null }
> = {
    mobile_small: {
        label: 'admin.design.viewport.mobile_small',
        size: { width: '22.5rem', height: '40rem' },
    },
    mobile: {
        label: 'admin.design.viewport.mobile',
        size: { width: '24.375rem', height: '52.75rem' },
    },
    tablet: {
        label: 'admin.design.viewport.tablet',
        size: { width: '48rem', height: '64rem' },
    },
    desktop: {
        label: 'admin.design.viewport.desktop',
        size: { width: '90rem', height: '56.25rem' },
    },
    full: { label: 'admin.design.viewport.full', size: null },
};

const VIEWPORT_ORDER: readonly ViewportKey[] = [
    'mobile_small',
    'mobile',
    'tablet',
    'desktop',
    'full',
];

type PreviewLocale = 'fr' | 'en';

const LOCALES: Record<PreviewLocale, TranslationKey> = {
    fr: 'admin.design.locale.fr',
    en: 'admin.design.locale.en',
};

const KIND_KEYS: Record<Scenario['kind'], TranslationKey> = {
    live: 'admin.design.kind.live',
    fixture: 'admin.design.kind.fixture',
};

function isViewportKey(value: string | null): value is ViewportKey {
    return (
        value !== null && (VIEWPORT_ORDER as readonly string[]).includes(value)
    );
}

/** L'état de l'écran, lu dans l'adresse : un rechargement le garde. */
type Selection = {
    scenario: string;
    viewport: ViewportKey;
    locale: PreviewLocale;
};

function initialSelection(scenarios: Scenario[]): Selection {
    const params = new URLSearchParams(window.location.search);
    const scenario = params.get('scenario');
    const viewport = params.get('viewport');
    const locale = params.get('locale');

    return {
        scenario:
            scenarios.find((each) => each.key === scenario)?.key ??
            scenarios[0]?.key ??
            '',
        viewport: isViewportKey(viewport) ? viewport : 'mobile',
        locale: locale === 'en' ? 'en' : 'fr',
    };
}

/** L'URL que l'aperçu charge pour un scénario. */
function previewUrl(scenario: string, locale: PreviewLocale): string | null {
    if (isLiveScenarioKey(scenario)) {
        return LIVE_SCENARIO_URLS[scenario]();
    }

    if (isFixtureScenarioKey(scenario)) {
        return designFrame.url({ scenario }, { query: { locale } });
    }

    return null;
}

/**
 * Le banc d'essai du design (spec 20 § 13.8, ligne 50 ; demande du porteur du
 * 08/10) : administrateur seul, jamais en production. À gauche les
 * scénarios groupés ; à droite la largeur d'affichage, la langue, « Rejouer
 * le scénario », « Ouvrir dans un onglet », et une `iframe` qui montre le
 * scénario choisi. Le choix vit dans l'adresse (`?scenario=&viewport=&locale=`),
 * remplacée sans visite.
 *
 * Un scénario « réel » charge la vraie URL de la page, avec les données du
 * compte connecté ; un scénario « fictif » charge `design.frame`, qui rend
 * la vraie page avec des données de test, sans aucune écriture.
 */
export default function AdminDesignIndex({ scenarios, groups }: Props) {
    const { t } = useTranslations();
    const [selection, setSelection] = useState<Selection>(() =>
        initialSelection(scenarios),
    );
    const [reloads, setReloads] = useState(0);

    const choose = (next: Partial<Selection>): void => {
        const merged = { ...selection, ...next };
        const url = new URL(window.location.href);

        url.searchParams.set('scenario', merged.scenario);
        url.searchParams.set('viewport', merged.viewport);
        url.searchParams.set('locale', merged.locale);
        window.history.replaceState(window.history.state, '', url);
        setSelection(merged);
    };

    const current = scenarios.find((each) => each.key === selection.scenario);
    const src = previewUrl(selection.scenario, selection.locale);
    const size = VIEWPORTS[selection.viewport].size;
    const label = current === undefined ? '' : t(current.labelKey);

    return (
        <>
            <Head title={t('admin.design.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.design.title')}
                    description={t('admin.design.description')}
                />

                <div className="grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
                    <nav
                        aria-label={t('admin.design.scenarios')}
                        className="flex flex-col gap-4"
                    >
                        {groups.map((group) => (
                            <section key={group.key} className="space-y-1">
                                <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {t(group.labelKey)}
                                </h2>
                                <ul className="space-y-0.5">
                                    {scenarios
                                        .filter(
                                            (each) => each.group === group.key,
                                        )
                                        .map((each) => {
                                            const active =
                                                each.key === selection.scenario;

                                            return (
                                                <li key={each.key}>
                                                    <button
                                                        type="button"
                                                        aria-current={
                                                            active
                                                                ? 'true'
                                                                : undefined
                                                        }
                                                        onClick={() =>
                                                            choose({
                                                                scenario:
                                                                    each.key,
                                                            })
                                                        }
                                                        className={cn(
                                                            'flex min-h-9 w-full items-center justify-between gap-2 rounded-md px-2 py-1 text-left text-sm text-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                                            active &&
                                                                'bg-muted font-medium',
                                                        )}
                                                    >
                                                        <span>
                                                            {t(each.labelKey)}
                                                        </span>
                                                        {each.kind ===
                                                            'live' && (
                                                            <Badge variant="outline">
                                                                {t(
                                                                    KIND_KEYS.live,
                                                                )}
                                                            </Badge>
                                                        )}
                                                    </button>
                                                </li>
                                            );
                                        })}
                                </ul>
                            </section>
                        ))}
                    </nav>

                    <div className="flex min-w-0 flex-col gap-4">
                        <Card>
                            <CardContent className="flex flex-wrap items-end gap-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="design-viewport">
                                        {t('admin.design.toolbar.viewport')}
                                    </Label>
                                    <AdminSelect
                                        id="design-viewport"
                                        value={selection.viewport}
                                        onChange={(event) => {
                                            const value = event.target.value;

                                            if (isViewportKey(value)) {
                                                choose({ viewport: value });
                                            }
                                        }}
                                        options={VIEWPORT_ORDER.map((key) => ({
                                            value: key,
                                            label: t(VIEWPORTS[key].label),
                                        }))}
                                    />
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="design-locale">
                                        {t('admin.design.toolbar.locale')}
                                    </Label>
                                    <AdminSelect
                                        id="design-locale"
                                        value={selection.locale}
                                        onChange={(event) =>
                                            choose({
                                                locale:
                                                    event.target.value === 'en'
                                                        ? 'en'
                                                        : 'fr',
                                            })
                                        }
                                        options={(
                                            Object.keys(
                                                LOCALES,
                                            ) as PreviewLocale[]
                                        ).map((key) => ({
                                            value: key,
                                            label: t(LOCALES[key]),
                                        }))}
                                    />
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setReloads((count) => count + 1)
                                        }
                                    >
                                        <RotateCcw aria-hidden="true" />
                                        {t('admin.design.toolbar.reload')}
                                    </Button>

                                    {src !== null && (
                                        <Button variant="outline" asChild>
                                            <a
                                                href={src}
                                                target="_blank"
                                                rel="noopener"
                                            >
                                                <ExternalLink aria-hidden="true" />
                                                {t('admin.design.toolbar.open')}
                                            </a>
                                        </Button>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {current !== undefined && (
                            <p className="text-sm text-muted-foreground">
                                {current.kind === 'live'
                                    ? t('admin.design.live_notice')
                                    : t('admin.design.fixture_notice')}
                            </p>
                        )}

                        <div className="overflow-auto rounded-lg border border-border bg-muted p-2">
                            {src !== null && (
                                <iframe
                                    key={`${src}#${reloads}`}
                                    src={src}
                                    title={t('admin.design.frame_title', {
                                        scenario: label,
                                    })}
                                    className={cn(
                                        'mx-auto block rounded-md border border-border bg-background',
                                        size === null && 'h-[75svh] w-full',
                                    )}
                                    style={size ?? undefined}
                                />
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

AdminDesignIndex.layout = { breadcrumbs };
