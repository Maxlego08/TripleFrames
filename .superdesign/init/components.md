# Shared UI components

## Button

- Source: `resources/js/components/ui/button.tsx`
- Radix Slot + CVA primitive used throughout the React application.

```tsx
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
    "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-[color,box-shadow] disabled:pointer-events-none disabled:opacity-50 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]",
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground shadow-xs hover:bg-primary/90',
                destructive: 'bg-destructive text-white shadow-xs hover:bg-destructive/90',
                outline: 'border border-input bg-background shadow-xs hover:bg-accent',
                secondary: 'bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/80',
                ghost: 'hover:bg-accent hover:text-accent-foreground',
                link: 'text-primary underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-9 px-4 py-2 has-[>svg]:px-3',
                sm: 'h-8 rounded-md px-3 has-[>svg]:px-2.5',
                lg: 'h-10 rounded-md px-6 has-[>svg]:px-4',
                icon: 'size-9',
            },
        },
        defaultVariants: { variant: 'default', size: 'default' },
    },
);

function Button({ className, variant, size, asChild = false, ...props }: React.ComponentProps<'button'> & VariantProps<typeof buttonVariants> & { asChild?: boolean }) {
    const Comp = asChild ? Slot : 'button';
    return <Comp data-slot="button" className={cn(buttonVariants({ variant, size, className }))} {...props} />;
}

export { Button, buttonVariants };
```

## Input

- Source: `resources/js/components/ui/input.tsx`
- Shared accessible text input.

```tsx
import * as React from 'react';
import { cn } from '@/lib/utils';

function Input({ className, type, ...props }: React.ComponentProps<'input'>) {
    return (
        <input
            type={type}
            data-slot="input"
            className={cn(
                'border-input placeholder:text-muted-foreground flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] disabled:opacity-50 md:text-sm',
                className,
            )}
            {...props}
        />
    );
}

export { Input };
```

## PlayerAvatar

- Source: `resources/js/components/game/player-avatar.tsx`
- Game avatar using the shared Radix avatar primitive and a server-resolved fallback.

```tsx
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { AvatarData } from '@/types/player';

export type PlayerAvatarProps = { avatar: AvatarData; alt: string; className?: string };

export function PlayerAvatar({ avatar, alt, className }: PlayerAvatarProps) {
    const decorative = alt === '';
    return (
        <Avatar aria-hidden={decorative ? true : undefined} className={className}>
            {avatar.url !== null ? <AvatarImage src={avatar.url} alt={alt} draggable={false} /> : null}
            <AvatarFallback role={decorative ? undefined : 'img'} aria-label={decorative ? undefined : alt} className="select-none">
                {avatar.initials}
            </AvatarFallback>
        </Avatar>
    );
}
```

## Standalone mock primitives

- Sources: `design-test/html/scss/_tokens.scss`, `design-test/html/scss/_mixins.scss`
- The HTML mock uses BEM classes, OKLCH tokens, thick ink outlines, hard offset shadows, rounded display type, and coral/cyan/yellow interaction colors.
