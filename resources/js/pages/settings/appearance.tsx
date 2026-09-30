import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { useTranslations } from '@/hooks/use-translations';
import { edit as editAppearance } from '@/routes/appearance';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'account.appearance.title',
        href: editAppearance(),
    },
];

export default function Appearance() {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('account.appearance.title')} />

            <h1 className="sr-only">{t('account.appearance.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('account.appearance.heading')}
                    description={t('account.appearance.description')}
                />
                <AppearanceTabs />
            </div>
        </>
    );
}

Appearance.layout = { breadcrumbs };
