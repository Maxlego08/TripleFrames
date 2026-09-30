import type { ReactNode } from 'react';

type Props = {
    /** Texte DÉJÀ traduit : l'écran possède sa copie, ce composant la forme. */
    title: string;
    description?: string;
    /** Retour, bouton d'action, marqueur « lecture seule »… */
    actions?: ReactNode;
};

/**
 * Le titre d'un écran d'administration.
 *
 * C'est le `<h1>` de la page, et il n'y en a qu'un : la coquille du
 * back-office n'en pose pas, elle porte un fil d'Ariane. Sans ce niveau, un
 * lecteur d'écran arriverait sur un document dont le premier titre est celui
 * d'une carte.
 *
 * Ce n'est pas `<Heading>` du site joueur, qui rend un `<h2>` et impose ses
 * marges : le back-office a sa propre composition pour qu'un re-skin de l'un
 * ne touche pas l'autre (règle 5).
 */
export function AdminPageHeading({ title, description, actions }: Props) {
    return (
        <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0 space-y-1">
                <h1 className="text-xl font-semibold tracking-tight text-foreground">
                    {title}
                </h1>
                {description && (
                    <p className="max-w-prose text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </header>
    );
}
