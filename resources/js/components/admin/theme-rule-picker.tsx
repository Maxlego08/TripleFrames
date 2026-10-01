import { router } from '@inertiajs/react';
import { PlusIcon, XIcon } from 'lucide-react';
import { useEffect, useEffectEvent, useId, useRef, useState } from 'react';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminSelect } from '@/components/admin/admin-select';
import type { AdminSelectOption } from '@/components/admin/admin-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import type { Translator } from '@/hooks/use-translations';
import { MOVIE_DIFFICULTY_KEYS } from '@/lib/admin-enum-keys';
import type {
    AdminThemeRuleOption,
    AdminThemeRuleOptions,
    MovieDifficulty,
    ThemeKind,
} from '@/types/admin';

/** La prop optionnelle servie au seul rechargement partiel (`ThemeController::index`). */
const RULE_OPTIONS_PROP = 'rule_options';

/** Le paramètre qui dit au serveur quelle nature charger. */
const RULE_KIND_PARAMETER = 'rule_kind';

/** `ThemeRules::STUDIO_MAX_COMPANIES` (spec 30 § 12.1). */
export const STUDIO_MAX_COMPANIES = 8;

type LoadStatus = 'loading' | 'ready' | 'failed';

/**
 * Les valeurs de règle présentes au catalogue pour une nature, chargées par
 * un rechargement partiel de `rule_options` — jamais un appel TMDB. Une
 * réponse qui ne porte pas la nature demandée ne se lit jamais comme la
 * sienne (deux changements de nature rapprochés).
 */
function useRuleOptions(kind: ThemeKind): {
    status: LoadStatus;
    options: AdminThemeRuleOption[];
    retry: () => void;
} {
    const [state, setState] = useState<{
        status: LoadStatus;
        kind: ThemeKind;
        options: AdminThemeRuleOption[];
    }>({ status: 'loading', kind, options: [] });
    const latest = useRef(0);

    const load = useEffectEvent((requested: ThemeKind): void => {
        const ticket = latest.current + 1;
        latest.current = ticket;
        setState({ status: 'loading', kind: requested, options: [] });

        const settle = (
            status: LoadStatus,
            options: AdminThemeRuleOption[],
        ): void => {
            if (latest.current === ticket) {
                setState({ status, kind: requested, options });
            }
        };

        router.reload({
            only: [RULE_OPTIONS_PROP],
            data: { [RULE_KIND_PARAMETER]: requested },
            preserveUrl: true,
            onSuccess: (page) => {
                const result = page.props[RULE_OPTIONS_PROP] as
                    | AdminThemeRuleOptions
                    | null
                    | undefined;

                if (result?.kind === requested) {
                    settle('ready', result.options);
                } else {
                    settle('failed', []);
                }
            },
            onHttpException: () => settle('failed', []),
            onNetworkError: () => settle('failed', []),
        });
    });

    useEffect(() => {
        // La difficulté se choisit parmi les cinq cas connus : rien à charger.
        if (kind !== 'difficulty') {
            load(kind);
        }
    }, [kind]);

    return {
        status:
            kind === 'difficulty'
                ? 'ready'
                : state.kind === kind
                  ? state.status
                  : 'loading',
        options: state.kind === kind ? state.options : [],
        retry: () => load(kind),
    };
}

/** Le libellé d'une valeur : « Marvel Studios (420) », « Genre 878 », « 1990 ». */
export function ruleValueLabel(
    kind: ThemeKind,
    value: string,
    name: string | null,
    t: Translator['t'],
): string {
    if (name !== null) {
        return t('admin.themes.rule.named', { name, value });
    }

    if (kind === 'genre') {
        return t('admin.themes.picker.genre', { id: value });
    }

    if (kind === 'difficulty' && value in MOVIE_DIFFICULTY_KEYS) {
        return t(MOVIE_DIFFICULTY_KEYS[value as MovieDifficulty]);
    }

    return value;
}

type Props = {
    kind: ThemeKind;
    /** La règle en place (correction) ou préremplie (saga depuis la fiche). */
    initialValue: string | null;
    initialCompanyIds: number[];
    /** Nom de la valeur en place, pour l'afficher même absente des options. */
    initialName: string | null;
    errors: Partial<Record<string, string>>;
};

/**
 * Le choix de la valeur d'une règle, sous le champ de sa nature (spec 20
 * § 9.6) : `collection_id` pour une saga, `company_ids[]` pour un studio,
 * `rule_value` sinon. Les valeurs proposées sont **présentes au catalogue**,
 * avec leur nombre de films. Une collection ou une société déjà désignée par
 * un autre thème n'est pas proposée (le serveur la refuserait sous verrou) ;
 * un genre, une décennie ou une langue déjà portés restent proposés, avec la
 * clé du thème qui les porte : seules saga et studio sont uniques.
 *
 * Un studio retient une à huit sociétés : celles du catalogue, ou un
 * identifiant TMDB saisi — pour corriger un thème livré vers une société
 * encore absente (`studio.disney`, spec 30 § 12.3) ; à la création, le
 * serveur exige qu'un film la porte.
 */
export function ThemeRulePicker({
    kind,
    initialValue,
    initialCompanyIds,
    initialName,
    errors,
}: Props) {
    const { t } = useTranslations();
    const fieldId = useId();
    const errorId = useId();
    const { status, options, retry } = useRuleOptions(kind);

    const optionLabel = (option: AdminThemeRuleOption): string =>
        t('admin.themes.picker.option', {
            label: ruleValueLabel(kind, option.value, option.name, t),
            count: option.films,
        });

    if (kind === 'studio') {
        return (
            <StudioPicker
                status={status}
                options={options}
                retry={retry}
                initialCompanyIds={initialCompanyIds}
                optionLabel={optionLabel}
                errors={errors}
            />
        );
    }

    const field = kind === 'saga' ? 'collection_id' : 'rule_value';

    let choices: AdminSelectOption[];

    if (kind === 'difficulty') {
        choices = (Object.keys(MOVIE_DIFFICULTY_KEYS) as MovieDifficulty[]).map(
            (value) => ({ value, label: t(MOVIE_DIFFICULTY_KEYS[value]) }),
        );
    } else {
        // Seule une collection se désigne une fois (saga) : un genre, une
        // décennie ou une langue déjà portés par un autre thème restent
        // choisissables (un thème nié, un second regroupement), signalés.
        choices = options
            .filter(
                (option) =>
                    kind !== 'saga' ||
                    option.taken_by === null ||
                    option.value === initialValue,
            )
            .map((option) => ({
                value: option.value,
                label:
                    option.taken_by === null || option.value === initialValue
                        ? optionLabel(option)
                        : t('admin.themes.picker.taken', {
                              label: optionLabel(option),
                              key: option.taken_by,
                          }),
            }));

        if (
            initialValue !== null &&
            !choices.some((choice) => choice.value === initialValue)
        ) {
            choices.unshift({
                value: initialValue,
                label: t('admin.themes.picker.current', {
                    label: ruleValueLabel(kind, initialValue, initialName, t),
                }),
            });
        }
    }

    return (
        <div className="flex flex-col gap-1.5">
            <Label htmlFor={fieldId}>{t('admin.themes.form.rule')}</Label>
            <RuleOptionsStatus status={status} retry={retry} />
            {status !== 'loading' && (
                <AdminSelect
                    id={fieldId}
                    name={field}
                    defaultValue={initialValue ?? ''}
                    options={[
                        { value: '', label: t('admin.themes.picker.choose') },
                        ...choices,
                    ]}
                    aria-invalid={errors[field] ? true : undefined}
                    aria-describedby={errorId}
                    className="min-h-11"
                />
            )}
            {status === 'ready' &&
                kind !== 'difficulty' &&
                options.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.themes.picker.empty')}
                    </p>
                )}
            <AdminInputError id={errorId} message={errors[field]} />
        </div>
    );
}

/** Chargement et échec du rechargement des options, avec reprise. */
function RuleOptionsStatus({
    status,
    retry,
}: {
    status: LoadStatus;
    retry: () => void;
}) {
    const { t } = useTranslations();

    return (
        <div role="status" aria-live="polite">
            {status === 'loading' && (
                <p className="text-sm text-muted-foreground">
                    {t('admin.themes.picker.loading')}
                </p>
            )}
            {status === 'failed' && (
                <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm text-destructive">
                        {t('admin.themes.picker.failed')}
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="min-h-11"
                        onClick={retry}
                    >
                        {t('admin.themes.picker.retry')}
                    </Button>
                </div>
            )}
        </div>
    );
}

/** Une à huit sociétés : choisies au catalogue ou saisies par identifiant. */
function StudioPicker({
    status,
    options,
    retry,
    initialCompanyIds,
    optionLabel,
    errors,
}: {
    status: LoadStatus;
    options: AdminThemeRuleOption[];
    retry: () => void;
    initialCompanyIds: number[];
    optionLabel: (option: AdminThemeRuleOption) => string;
    errors: Partial<Record<string, string>>;
}) {
    const { t } = useTranslations();
    const selectId = useId();
    const manualId = useId();
    const manualHintId = useId();
    const errorId = useId();
    const [selected, setSelected] = useState<number[]>(initialCompanyIds);
    const [manual, setManual] = useState('');
    const full = selected.length >= STUDIO_MAX_COMPANIES;

    const byId = new Map(options.map((option) => [option.value, option]));
    const label = (id: number): string => {
        const option = byId.get(String(id));

        return ruleValueLabel('studio', String(id), option?.name ?? null, t);
    };

    function add(id: number): void {
        if (
            !Number.isInteger(id) ||
            id < 1 ||
            selected.includes(id) ||
            selected.length >= STUDIO_MAX_COMPANIES
        ) {
            return;
        }

        setSelected([...selected, id]);
    }

    const available = options.filter(
        (option) =>
            option.taken_by === null &&
            !selected.includes(Number(option.value)),
    );

    const itemErrors = Object.entries(errors)
        .filter(([field]) => field.startsWith('company_ids.'))
        .map(([, message]) => message);

    return (
        <fieldset className="flex flex-col gap-3">
            <legend className="mb-1.5 text-sm font-medium">
                {t('admin.themes.form.rule')}
            </legend>

            {selected.map((id) => (
                <input key={id} type="hidden" name="company_ids[]" value={id} />
            ))}

            <div className="flex flex-col gap-1.5">
                <p className="text-sm font-medium">
                    {t('admin.themes.picker.selected')}
                </p>
                {selected.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.themes.picker.none_selected')}
                    </p>
                ) : (
                    <ul className="flex flex-wrap gap-2">
                        {selected.map((id) => (
                            <li
                                key={id}
                                className="flex items-center gap-1 rounded-md border border-border py-1 pr-1 pl-2 text-sm"
                            >
                                <span>{label(id)}</span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="min-h-11 min-w-11"
                                    aria-label={t(
                                        'admin.themes.picker.remove',
                                        {
                                            label: label(id),
                                        },
                                    )}
                                    onClick={() =>
                                        setSelected(
                                            selected.filter(
                                                (other) => other !== id,
                                            ),
                                        )
                                    }
                                >
                                    <XIcon aria-hidden />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-sm text-muted-foreground">
                    {t('admin.themes.picker.limit', {
                        max: STUDIO_MAX_COMPANIES,
                    })}
                </p>
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor={selectId}>
                    {t('admin.themes.picker.add_company')}
                </Label>
                <RuleOptionsStatus status={status} retry={retry} />
                {status === 'ready' && options.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.themes.picker.empty')}
                    </p>
                )}
                {status === 'ready' && available.length > 0 && (
                    <AdminSelect
                        id={selectId}
                        value=""
                        disabled={full}
                        onChange={(event) => add(Number(event.target.value))}
                        options={[
                            {
                                value: '',
                                label: t('admin.themes.picker.choose'),
                            },
                            ...available.map((option) => ({
                                value: option.value,
                                label: optionLabel(option),
                            })),
                        ]}
                        className="min-h-11"
                    />
                )}
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor={manualId}>
                    {t('admin.themes.picker.company_id')}
                </Label>
                <div className="flex gap-2">
                    <Input
                        id={manualId}
                        inputMode="numeric"
                        value={manual}
                        disabled={full}
                        autoComplete="off"
                        aria-describedby={manualHintId}
                        onChange={(event) => setManual(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                add(Number(manual.trim()));
                                setManual('');
                            }
                        }}
                        className="min-h-11"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        disabled={full}
                        onClick={() => {
                            add(Number(manual.trim()));
                            setManual('');
                        }}
                    >
                        <PlusIcon aria-hidden />
                        {t('admin.themes.picker.add')}
                    </Button>
                </div>
                <p id={manualHintId} className="text-sm text-muted-foreground">
                    {t('admin.themes.picker.company_id_help')}
                </p>
            </div>

            <AdminInputError id={errorId} message={errors.company_ids} />
            {itemErrors.map((message, index) => (
                <AdminInputError key={index} message={message} />
            ))}
        </fieldset>
    );
}
