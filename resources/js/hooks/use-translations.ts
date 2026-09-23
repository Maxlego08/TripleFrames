import { usePage } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';
import type {
    LocaleOption,
    Replacements,
    TranslationSnapshot,
} from '@/lib/i18n';
import {
    applyDocumentLocale,
    setActiveTranslations,
    translate,
    translateChoice,
} from '@/lib/i18n';
import type { TranslationKey } from '@/types/translations';

export type Translator = {
    /** Code de la locale active, ex. `'fr'`. */
    locale: string;
    /** Registre des locales activées, libellés natifs compris. */
    locales: LocaleOption[];
    t: (key: TranslationKey, replacements?: Replacements) => string;
    tChoice: (
        key: TranslationKey,
        count: number,
        replacements?: Replacements,
    ) => string;
};

/**
 * Accès au dictionnaire depuis un composant.
 *
 * Les fonctions rendues **changent d'identité** à chaque bascule de langue :
 * c'est ce qui force React Compiler à re-rendre les sous-arbres traduits. Un
 * appel au `t()` de module, dont l'identité est stable, laisserait un écran
 * figé dans l'ancienne langue après un `router.reload` partiel.
 *
 * Le hook tient aussi à jour le dictionnaire du module (pour les appelants
 * hors rendu) et `<html lang>` / `<html dir>`, sans rechargement de page.
 */
export function useTranslations(): Translator {
    const { locale, locales, translations } = usePage().props;

    const snapshot = useMemo<TranslationSnapshot>(
        () => ({ locale, messages: translations }),
        [locale, translations],
    );

    useEffect(() => {
        setActiveTranslations(snapshot);

        applyDocumentLocale(
            snapshot.locale,
            locales.find((option) => option.value === snapshot.locale),
        );
    }, [snapshot, locales]);

    return useMemo<Translator>(
        () => ({
            locale: snapshot.locale,
            locales,
            t: (key, replacements) => translate(snapshot, key, replacements),
            tChoice: (key, count, replacements) =>
                translateChoice(snapshot, key, count, replacements),
        }),
        [snapshot, locales],
    );
}
