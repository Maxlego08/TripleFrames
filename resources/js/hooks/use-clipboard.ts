import { useCallback, useState } from 'react';

export type CopiedValue = string | null;
export type CopyFn = (text: string) => Promise<boolean>;
export type UseClipboardReturn = [CopiedValue, CopyFn];

/**
 * Copie dans le presse-papiers (clé de configuration 2FA). Renvoie faux sans
 * bruit quand l'API manque ou refuse : l'appelant garde la valeur lisible et
 * sélectionnable à l'écran, seul repli utile pour un lecteur d'écran.
 */
export function useClipboard(): UseClipboardReturn {
    const [copiedText, setCopiedText] = useState<CopiedValue>(null);

    const copy = useCallback<CopyFn>(async (text) => {
        if (typeof navigator === 'undefined' || !navigator.clipboard) {
            return false;
        }

        try {
            await navigator.clipboard.writeText(text);
            setCopiedText(text);

            return true;
        } catch {
            setCopiedText(null);

            return false;
        }
    }, []);

    return [copiedText, copy];
}
