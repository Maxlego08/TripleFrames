import { CircleHelp } from 'lucide-react';
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

type GameHelpProps = {
    /**
     * Plafond du bonus de rapidité en pourcentage de la valeur du palier,
     * pour le `N` affiché : `PlatformLimits::toArray().speedBonusMaxPercent`
     * indexé par `N` (C0, D22 du 23/09), prop de page `limits` du lobby et du
     * solo — jamais un littéral.
     */
    speedBonusMaxPercent: number;
};

/**
 * Écran d'aide du jeu (spec 90 § 7.7) : un bouton `game.help.open` ouvre une
 * feuille qui explique, une fois pour toutes, la règle du préfixe
 * (`game.help.prefix`, texte de 70) et le barème (`game.help.scoring.*`,
 * textes de 80).
 *
 * - **La règle du préfixe est dite ici, jamais en manche** : un refus reste
 *   neutre, sans « presque ! » (décision 13). Le texte ne parle jamais du
 *   salon, du tirage ni de films « jouables » : l'ambiguïté d'un préfixe se
 *   mesure sur le catalogue publié entier, et un texte qui la rattacherait au
 *   salon ferait lire une acceptation comme un renseignement sur le tirage.
 * - Le déclencheur est rendu par le lobby et par le solo, **jamais pendant
 *   qu'un palier est ouvert** : c'est la page qui décide où le monter.
 * - `:percent` est formaté par `Intl.NumberFormat` dans la locale du joueur
 *   (spec 05) ; la valeur vient de la prop, pour le `N` affiché.
 *
 * Barème : les cinq lignes de `game.help.scoring.*` dans l'ordre de la spec
 * 90 § 6.5 (`game.help.scoring` est un nœud, jamais une feuille, R-38).
 *
 * Clavier (spec 90 § 7.5) : la zone défilante est focalisable et nommée
 * (`role="region"`, titre de l'aide) — Safari ne rend jamais focalisable une
 * zone défilante sans élément focalisable, et « Fermer » est le seul de la
 * feuille : sans cela, la fin de l'aide resterait hors d'atteinte au clavier.
 *
 * Fermeture (spec 90 § 2.5) : le « Close » généré par `SheetContent`, en dur
 * et en anglais, est masqué (`[&>button:last-child]:hidden`) et remplacé par
 * une fermeture traduite (`common.action.close`) ; `Échap` ferme aussi la
 * feuille (Radix). Titre et description traduits. Aucun portail ouvert sous
 * `GameThemeScope` : cette feuille ne sert que les pages de jeu, sombres par
 * la classe de la racine (D56 du 02/10). Mouvement réduit : `motion-reduce:animate-none!`,
 * l'important étant requis contre `data-[state=open]:animate-in`.
 */
export function GameHelp({ speedBonusMaxPercent }: GameHelpProps) {
    const { t, locale } = useTranslations();
    const percent = new Intl.NumberFormat(locale).format(speedBonusMaxPercent);

    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button variant="outline" className="min-h-11">
                    <CircleHelp aria-hidden="true" />
                    {t('game.help.open')}
                </Button>
            </SheetTrigger>

            <SheetContent
                side="bottom"
                className="max-h-[90dvh] motion-reduce:animate-none! [&>button:last-child]:hidden"
            >
                <SheetHeader className="mx-auto w-full max-w-prose">
                    <SheetTitle>{t('game.help.title')}</SheetTitle>
                    <SheetDescription>
                        {t('game.help.description')}
                    </SheetDescription>
                </SheetHeader>

                <div
                    role="region"
                    aria-label={t('game.help.title')}
                    tabIndex={0}
                    className="mx-auto flex min-h-0 w-full max-w-prose flex-col gap-3 overflow-y-auto px-4 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <p>{t('game.help.prefix')}</p>

                    <ul className="flex list-disc flex-col gap-2 ps-5">
                        <li>{t('game.help.scoring.tier_values')}</li>
                        <li>
                            {t('game.help.scoring.speed_bonus', { percent })}
                        </li>
                        <li>{t('game.help.scoring.tie_break')}</li>
                        <li>{t('game.help.scoring.no_penalty')}</li>
                        <li>{t('game.help.scoring.cancelled_round')}</li>
                    </ul>
                </div>

                <SheetFooter className="mx-auto w-full max-w-prose">
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
