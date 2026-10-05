<?php

namespace App\Support\Curation;

use InvalidArgumentException;

/**
 * Un lot d'images illisible ou hors format — spec 20 § 5.10. Porte la CLÉ du
 * motif, du domaine `admin`, et ses substitutions, jamais une phrase : la
 * console et l'écran la traduisent chacun.
 */
final class FrameBatchException extends InvalidArgumentException
{
    /**
     * @param  array<string, int|string>  $replacements
     */
    public function __construct(
        public readonly string $key,
        public readonly array $replacements = [],
    ) {
        parent::__construct($key);
    }

    /** Le motif traduit. */
    public function translated(): string
    {
        return __($this->key, $this->replacements, 'fr');
    }
}
