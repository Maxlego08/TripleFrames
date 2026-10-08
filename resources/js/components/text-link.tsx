import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type Props = ComponentProps<typeof Link>;

/**
 * Lien de texte des écrans de compte (spec 90 § 11.2) : soulignement aux
 * tokens, anneau de focus visible. La teinte de soulignement propre à une
 * coquille vit dans sa feuille (`.text-link`).
 */
export default function TextLink({ className, children, ...props }: Props) {
    return (
        <Link
            className={cn(
                'text-link rounded-sm text-foreground underline decoration-muted-foreground underline-offset-4 transition-colors hover:decoration-current focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
