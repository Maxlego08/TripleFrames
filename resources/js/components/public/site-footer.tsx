import { CONSENT_SETTINGS_ID } from '@/components/public/consent-banner';
import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useTranslations } from '@/hooks/use-translations';
import { notice, privacy, terms } from '@/routes/legal';
import { create as reportContent } from '@/routes/takedown';
import type { TranslationKey } from '@/types/translations';
import type { RouteDefinition } from '@/wayfinder';

type LegalLink = {
    label: TranslationKey;
    href: RouteDefinition<'get'>;
};

/**
 * Liens du pied de page, dans leur ordre d'affichage : mentions légales, CGU,
 * confidentialité, « signaler un contenu » (spec 90 § 3.1), libellés
 * `legal.footer.{notice,terms,privacy,report}`, URL toujours par Wayfinder
 * (routes de `routes/legal.php`, L90-4). `SiteFooterTest` échoue dès qu'une
 * de ces routes existe sans que son libellé soit appelé ici.
 */
const LEGAL_LINKS: readonly LegalLink[] = [
    { label: 'legal.footer.notice', href: notice() },
    { label: 'legal.footer.terms', href: terms() },
    { label: 'legal.footer.privacy', href: privacy() },
    // D62 du 06/10 : revenir sur son choix de consentement à tout moment.
    {
        label: 'legal.footer.cookies',
        href: { ...privacy(), url: `${privacy().url}#${CONSENT_SETTINGS_ID}` },
    },
    { label: 'legal.footer.report', href: reportContent() },
];

type SiteFooterProps = {
    variant: 'full' | 'collapsed';
};

/**
 * Pied de page joueur, présent sur TOUS les écrans, écran de jeu compris : les
 * pages légales y sont toujours atteignables.
 *
 * - `full` (`PublicLayout`, `AuthLayout`, `AppLayout`) : une rangée compacte de
 *   liens. Les liens sont des `<Link>` Inertia : on quitte une page ordinaire
 *   pour une autre.
 * - `collapsed` (`GameLayout`) : un seul bouton, qui ouvre une feuille basse
 *   contenant les liens. Une rangée complète coûterait deux lignes à 360 de
 *   large, prises sur l'image (principe 5). Les liens s'ouvrent
 *   dans un NOUVEL ONGLET, jamais par une visite Inertia : quitter la page de
 *   jeu démonterait la souscription et ferait courir le délai de grâce de
 *   déconnexion — on ne quitte jamais une partie pour lire des mentions
 *   légales.
 *
 * Domaines : `legal` et `common` seulement (spec 90 § 6.3), déclarés sur toute
 * route joueur. Le back-office a son propre pied, sur `admin.footer.*` (20).
 */
export function SiteFooter({ variant }: SiteFooterProps) {
    return variant === 'full' ? <FullFooter /> : <CollapsedFooter />;
}

function FullFooter() {
    const { t } = useTranslations();

    return (
        <footer className="site-footer mt-auto border-t border-border">
            <div className="site-footer__inner mx-auto flex w-full max-w-5xl items-center justify-center px-4 text-sm text-muted-foreground">
                <nav className="w-full" aria-label={t('legal.footer.label')}>
                    <ul className="flex flex-wrap items-center justify-center gap-x-3">
                        {LEGAL_LINKS.map((link) => (
                            <li key={link.label}>
                                <Link
                                    href={link.href}
                                    className="inline-flex min-h-10 items-center rounded-sm px-1 text-center underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {t(link.label)}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
            </div>
        </footer>
    );
}

/**
 * La fermeture générée par `SheetContent` porte le nom accessible « Close »,
 * en dur et en anglais, sans prop pour le remplacer : elle est masquée
 * (`[&>button:last-child]:hidden`, restreint au dernier enfant pour ne pas
 * masquer le bouton propre, qui vit dans `SheetFooter`) et remplacée par une
 * fermeture traduite (spec 90 § 2.5). `Échap` ferme aussi la feuille (Radix).
 *
 * Mouvement réduit (spec 90 § 8) : `motion-reduce:animate-none!`, avec
 * l'important, car `data-[state=open]:animate-in` du composant généré est plus
 * spécifique qu'une variante `motion-reduce:` nue. Le fondu du voile généré
 * (`SheetOverlay`) n'est pas atteignable sans éditer `components/ui/*`.
 */
function CollapsedFooter() {
    const { t } = useTranslations();

    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button variant="ghost" size="sm" className="min-h-11">
                    {t('legal.footer.label')}
                </Button>
            </SheetTrigger>

            <SheetContent
                side="bottom"
                className="motion-reduce:animate-none! [&>button:last-child]:hidden"
            >
                <SheetHeader>
                    <SheetTitle>{t('legal.footer.label')}</SheetTitle>
                    <SheetDescription className="sr-only">
                        {t('legal.footer.sheet_description')}
                    </SheetDescription>
                </SheetHeader>

                <div className="px-4 text-sm">
                    <nav aria-label={t('legal.footer.label')}>
                        <ul className="flex flex-col">
                            {LEGAL_LINKS.map((link) => (
                                <li key={link.label}>
                                    <a
                                        href={link.href.url}
                                        target="_blank"
                                        rel="noopener"
                                        className="inline-flex min-h-11 items-center rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        {t(link.label)}
                                        <span className="sr-only">
                                            {' '}
                                            {t('legal.new_tab')}
                                        </span>
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </nav>
                </div>

                <SheetFooter>
                    <SheetClose asChild>
                        <Button variant="outline" className="min-h-11">
                            {t('common.action.close')}
                        </Button>
                    </SheetClose>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
