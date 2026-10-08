import { Form } from '@inertiajs/react';
import { SaveIcon } from 'lucide-react';
import { useId } from 'react';
import MovieDifficultyController from '@/actions/App/Http/Controllers/Admin/MovieDifficultyController';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminSelect } from '@/components/admin/admin-select';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import { MOVIE_DIFFICULTY_KEYS } from '@/lib/admin-enum-keys';
import type { MovieDifficulty } from '@/types/admin';

/** Les cas nommés, du plus facile au plus difficile — miroir de `MovieDifficulty`. */
const DIFFICULTIES: MovieDifficulty[] = [
    'very_easy',
    'easy',
    'medium',
    'hard',
    'very_hard',
];

/**
 * Corriger la difficulté d'un film (spec 20 § 9.6, L20-28b) : la correction
 * survit au réimport et met à jour les thèmes de difficulté du film dans la
 * transaction du geste ; « Aucune correction » rend la main à la valeur
 * dérivée de la notoriété. Le serveur journalise la correction quand elle
 * change (`movie.difficulty_corrected`).
 */
export function MovieDifficultyPanel({
    movieId,
    override,
    derived,
}: {
    movieId: number;
    override: MovieDifficulty | null;
    derived: MovieDifficulty | null;
}) {
    const { t } = useTranslations();
    const fieldId = useId();
    const hintId = useId();
    const errorId = useId();

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>
                    {t('admin.movie.difficulty.heading')}
                </AdminCardTitle>
                <CardDescription>
                    {t('admin.movie.difficulty.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    {...MovieDifficultyController.update.form(movieId)}
                    options={{ preserveScroll: true }}
                    className="flex max-w-md flex-col gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor={fieldId}>
                                    {t('admin.movie.difficulty.label')}
                                </Label>
                                <AdminSelect
                                    id={fieldId}
                                    name="movie_difficulty_override"
                                    defaultValue={override ?? ''}
                                    aria-describedby={`${hintId} ${errorId}`}
                                    aria-invalid={
                                        errors.movie_difficulty_override !==
                                        undefined
                                            ? true
                                            : undefined
                                    }
                                    className="min-h-11"
                                    options={[
                                        {
                                            value: '',
                                            label: t(
                                                'admin.movie.difficulty.derived_option',
                                            ),
                                        },
                                        ...DIFFICULTIES.map((value) => ({
                                            value,
                                            label: t(
                                                MOVIE_DIFFICULTY_KEYS[value],
                                            ),
                                        })),
                                    ]}
                                />
                                <p
                                    id={hintId}
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('admin.movie.difficulty.derived_hint', {
                                        value:
                                            derived === null
                                                ? t('admin.common.none')
                                                : t(
                                                      MOVIE_DIFFICULTY_KEYS[
                                                          derived
                                                      ],
                                                  ),
                                    })}
                                </p>
                                <AdminInputError
                                    id={errorId}
                                    message={errors.movie_difficulty_override}
                                />
                            </div>

                            <Button
                                type="submit"
                                variant="outline"
                                aria-disabled={processing || undefined}
                                aria-busy={processing || undefined}
                                onClick={(event) => {
                                    if (processing) {
                                        event.preventDefault();
                                    }
                                }}
                                className="min-h-11 self-start aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                            >
                                <SaveIcon aria-hidden />
                                {t('admin.movie.difficulty.submit')}
                            </Button>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}
