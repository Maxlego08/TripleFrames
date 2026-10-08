type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    /**
     * Identifiant du titre, pour qu'une section le prenne comme nom
     * accessible (`aria-labelledby`, spec 90 § 11.2).
     */
    id?: string;
};

/**
 * En-tête d'une section des écrans de compte. Les tailles sont aux
 * utilitaires, les couleurs aux tokens ; l'habillage propre aux réglages vit
 * dans `settings.scss` (`.settings-panel header`).
 */
export default function Heading({
    title,
    description,
    variant = 'default',
    id,
}: Props) {
    return (
        <header className={variant === 'small' ? '' : 'mb-8 space-y-0.5'}>
            <h2
                id={id}
                className={
                    variant === 'small'
                        ? 'mb-0.5 text-base font-medium text-foreground'
                        : 'text-xl font-semibold tracking-tight text-foreground'
                }
            >
                {title}
            </h2>
            {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
        </header>
    );
}
