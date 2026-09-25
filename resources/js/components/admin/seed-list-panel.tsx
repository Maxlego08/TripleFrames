import { Form, Link } from '@inertiajs/react';
import { InfoIcon } from 'lucide-react';
import ImportPreviewController from '@/actions/App/Http/Controllers/Admin/ImportPreviewController';
import ImportSeedListController from '@/actions/App/Http/Controllers/Admin/ImportSeedListController';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { useTranslations } from '@/hooks/use-translations';
import { formatInteger } from '@/lib/admin-format';
import { show as runShow } from '@/routes/admin/import';
import type { AdminSeedList } from '@/types/admin';

type Props = {
    seedList: AdminSeedList;
    tmdbConfigured: boolean;
};

/**
 * La liste d'amorçage — spec 20 § 3.5.
 *
 * Un fichier versionné, importé LOT PAR LOT : un clic ouvre un seul collage
 * des `paste_max_ids` premiers identifiants absents du catalogue, et le clic
 * suivant, le collage fini, reprend au premier manquant. Le bouton est
 * inactif — et dit pourquoi — tant que la liste est vide, entièrement
 * importée, ou qu'un collage tient le verrou, avec un lien vers ce collage.
 *
 * « Prévisualiser le lot suivant » envoie le prochain lot tel quel à
 * l'aperçu à blanc : un identifiant erroné importe silencieusement un autre
 * film, et l'aperçu est le geste normal avant chaque lot.
 */
export function SeedListPanel({ seedList, tmdbConfigured }: Props) {
    const { t, locale } = useTranslations();

    const ready = seedList.state === 'ready' && tmdbConfigured;
    const batch = seedList.next_batch.join('\n');

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>
                    {t('admin.import.seed_list.heading')}
                </AdminCardTitle>
                <CardDescription>
                    {t('admin.import.seed_list.description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {seedList.state === 'empty' ? (
                    <p
                        id="seed-list-state"
                        className="text-sm text-muted-foreground"
                    >
                        {t('admin.import.seed_list.empty')}
                    </p>
                ) : (
                    <div
                        id="seed-list-state"
                        className="space-y-1 text-sm text-foreground"
                    >
                        <p>
                            {t('admin.import.seed_list.total', {
                                count: formatInteger(seedList.total, locale),
                            })}
                        </p>
                        <p className="font-medium">
                            {t('admin.import.seed_list.remaining', {
                                count: formatInteger(
                                    seedList.remaining,
                                    locale,
                                ),
                            })}
                        </p>
                        {seedList.state === 'done' && (
                            <p className="text-muted-foreground">
                                {t('admin.import.seed_list.done')}
                            </p>
                        )}
                        {seedList.state === 'ready' && (
                            <p className="text-muted-foreground">
                                {t('admin.import.seed_list.batch', {
                                    count: seedList.next_batch.length,
                                })}
                            </p>
                        )}
                    </div>
                )}

                {seedList.state === 'busy' && (
                    <Alert>
                        <InfoIcon aria-hidden />
                        <AlertDescription>
                            <p>{t('admin.import.seed_list.busy')}</p>
                            {seedList.busy_run_id !== null && (
                                <Link
                                    href={runShow(seedList.busy_run_id)}
                                    className="underline underline-offset-4"
                                >
                                    {t('admin.import.seed_list.busy_link')}
                                </Link>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {seedList.state !== 'empty' && (
                    <p className="max-w-prose text-xs text-muted-foreground">
                        {t('admin.import.seed_list.caveat')}
                    </p>
                )}

                <div className="flex flex-wrap items-start gap-2">
                    <Form
                        {...ImportPreviewController.store.form()}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <>
                                <input type="hidden" name="ids" value={batch} />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="min-h-11"
                                    disabled={
                                        processing ||
                                        !tmdbConfigured ||
                                        seedList.next_batch.length === 0
                                    }
                                    aria-describedby="seed-list-state"
                                >
                                    {t('admin.import.seed_list.preview')}
                                </Button>
                                <AdminInputError message={errors.ids} />
                            </>
                        )}
                    </Form>

                    <Form
                        {...ImportSeedListController.store.form()}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={processing || !ready}
                                aria-describedby="seed-list-state"
                            >
                                {t('admin.import.seed_list.submit')}
                            </Button>
                        )}
                    </Form>
                </div>
            </CardContent>
        </Card>
    );
}
