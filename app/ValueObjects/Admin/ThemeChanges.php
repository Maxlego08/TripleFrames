<?php

namespace App\ValueObjects\Admin;

/**
 * L'état demandé d'un thème corrigé à l'écran des thèmes (spec 20 § 9.6) :
 * règle, négation, libellés et ordre — **jamais la nature ni la clé**, qui ne
 * changent pas. Le formulaire envoie l'état complet ; `UpdateTheme` compare
 * champ par champ et n'écrit, ne journalise et ne resynchronise que ce qui
 * change.
 */
final readonly class ThemeChanges
{
    /**
     * @param  string|null  $ruleValue  forme canonique de la règle ; `null` = thème manuel
     * @param  array<string, string>  $labels  locale d'interface => libellé rogné
     */
    public function __construct(
        public ?string $ruleValue,
        public bool $negated,
        public array $labels,
        public int $sortOrder,
    ) {}
}
