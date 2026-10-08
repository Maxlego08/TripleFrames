<?php

namespace App\Support\Catalog;

use App\Enums\AdminActionType;
use App\Enums\ContentFlag;
use App\Enums\ContentOrigin;
use App\Models\AdminAction;
use App\Models\Movie;

/**
 * Ce qu'une resynchronisation change à un film, borné à la liste close du
 * § 9.3 (spec 20 § 3.7) : la forme commune de l'écran de différences — une
 * lecture TMDB appliquée puis annulée — et de la ligne `movie.resynced` d'une
 * resynchronisation réelle.
 *
 * - `changed` : les champs écrasés, dans l'ordre de {@see ResyncSnapshot::FIELDS} ;
 * - `contentFlagBlocked` : la lecture découvre une certification restrictive,
 *   et le film passe `blocked` — sortie du vivier à la seconde (`10` A3). La
 *   resynchronisation **propose** alors « Dépublier », sans jamais la
 *   prononcer ;
 * - `reappearedAliases` : les alias TMDB qu'un curateur avait retirés à la
 *   main (ligne `movie.alias_removed`, origine `tmdb`) et que la lecture
 *   recrée — l'écran les signale « réapparu ».
 */
final readonly class ResyncDiff
{
    /**
     * @param  list<string>  $changed
     * @param  list<string>  $reappearedAliases  `locale : alias`, tels qu'affichés
     */
    private function __construct(
        public ResyncSnapshot $before,
        public ResyncSnapshot $after,
        public array $changed,
        public bool $contentFlagBlocked,
        public array $reappearedAliases,
    ) {}

    public static function between(Movie $movie, ResyncSnapshot $before, ResyncSnapshot $after): self
    {
        return new self(
            before: $before,
            after: $after,
            changed: $before->changedFields($after),
            contentFlagBlocked: $before->contentFlag !== ContentFlag::Blocked->value
                && $after->contentFlag === ContentFlag::Blocked->value,
            reappearedAliases: self::reappeared($movie, $before, $after),
        );
    }

    public function isEmpty(): bool
    {
        return $this->changed === [];
    }

    /**
     * Les lignes de l'écran : une par champ de la liste close, changé ou non.
     *
     * @return list<array{field: string, changed: bool, before: list<string>, after: list<string>}>
     */
    public function rows(): array
    {
        $rows = [];

        foreach (ResyncSnapshot::FIELDS as $field) {
            $rows[] = [
                'field' => $field,
                'changed' => in_array($field, $this->changed, true),
                'before' => $this->before->values[$field] ?? [],
                'after' => $this->after->values[$field] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * Les alias TMDB recréés par la lecture qu'un curateur avait retirés à la
     * main. Le journal est la seule mémoire d'un retrait (la ligne `alias` est
     * supprimée physiquement) : ses lignes sont lues par sujet et par action,
     * colonnes indexées, et leur `details` n'est lu qu'en PHP, jamais dans une
     * clause `WHERE` (spec 10 § 8.3).
     *
     * @return list<string>
     */
    private static function reappeared(Movie $movie, ResyncSnapshot $before, ResyncSnapshot $after): array
    {
        $created = array_values(array_diff($after->aliasKeys, $before->aliasKeys));

        if ($created === []) {
            return [];
        }

        $removedByHand = [];

        AdminAction::query()
            ->where('action', AdminActionType::MovieAliasRemoved->value)
            ->where('subject_id', $movie->id)
            ->orderBy('id')
            ->each(static function (AdminAction $line) use (&$removedByHand): void {
                $values = $line->details === null ? [] : $line->details->values;

                if (($values['origin'] ?? null) !== ContentOrigin::Tmdb->value) {
                    return;
                }

                $locale = $values['locale'] ?? null;
                $alias = $values['alias'] ?? null;

                if (is_string($locale) && is_string($alias)) {
                    $removedByHand[ResyncSnapshot::aliasKey($locale, $alias)] = $locale.' : '.$alias;
                }
            });

        $reappeared = [];

        foreach ($created as $key) {
            if (isset($removedByHand[$key])) {
                $reappeared[] = $removedByHand[$key];
            }
        }

        return $reappeared;
    }
}
