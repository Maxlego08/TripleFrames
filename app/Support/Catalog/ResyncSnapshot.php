<?php

namespace App\Support\Catalog;

use App\Enums\ContentOrigin;
use App\Enums\TmdbTagKind;
use App\Models\Alias;
use App\Models\Collection;
use App\Models\Movie;
use App\Models\MovieCertification;
use App\Models\MovieTitle;
use App\Models\MovieTmdbTag;
use App\Models\TmdbCompany;

/**
 * L'état d'un film **restreint à la liste close** de ce qu'une
 * resynchronisation peut écraser (spec 10 § 9.3), lu en base.
 *
 * Deux instantanés — avant et après l'écriture — font l'écran de
 * différences (spec 20 § 3.7) et la ligne `movie.resynced` du journal
 * (`changed`) : la même comparaison pour les deux, de sorte que l'écran ne
 * montre jamais un écart que le journal tairait, ni l'inverse.
 *
 * Ce qui n'est **jamais** lu ici, parce que jamais écrasé : lignes `curator`,
 * `movie_difficulty_override`, `group_id`, appartenances manuelles,
 * `availability`, la coche de contenu, frames et revues. Les valeurs dérivées
 * reprojetées librement (`movie_projection`, `answer_key`, `movie_theme`
 * automatiques) n'y sont pas davantage : elles suivent les champs lus, et
 * l'écran ne compare que ce que TMDB fournit.
 *
 * `read_at` d'une certification n'entre pas dans la comparaison — il change à
 * chaque lecture — mais il est gardé pour l'affichage : l'écran compare la
 * nouvelle lecture à la précédente (§ 9.3).
 */
final readonly class ResyncSnapshot
{
    /** Les champs comparés, dans l'ordre d'affichage : la liste close du § 9.3, plus le verdict de contenu. */
    public const array FIELDS = [
        'title_original',
        'title_original_latin',
        'original_language',
        'release_year',
        'vote_count',
        'adult',
        'collection_id',
        'genres',
        'companies',
        'movie_certification',
        'movie_title',
        'alias',
        'content_flag',
    ];

    /**
     * @param  array<string, list<string>>  $values  une liste de valeurs affichables par champ de {@see self::FIELDS}, vide pour NULL
     * @param  list<string>  $aliasKeys  les alias TMDB, `locale|forme repliée`, pour la détection des alias réapparus
     * @param  list<string>  $curatorTitleLocales  les locales dont le titre est une correction de curateur, jamais réécrite
     */
    private function __construct(
        public array $values,
        public array $aliasKeys,
        public array $curatorTitleLocales,
        public string $contentFlag,
        public ?string $certificationsReadAt,
    ) {}

    /** L'instantané d'un film, relu en base (dans la transaction de l'appelant s'il y en a une). */
    public static function capture(Movie $movie): self
    {
        /** @var Movie $fresh */
        $fresh = Movie::query()->whereKey($movie->id)->firstOrFail();

        $tags = MovieTmdbTag::query()->where('movie_id', $fresh->id)->get();

        $genres = $tags->filter(static fn (MovieTmdbTag $tag): bool => $tag->tag_kind === TmdbTagKind::Genre)
            ->map(static fn (MovieTmdbTag $tag): int => $tag->tmdb_tag_id)
            ->sort()
            ->values()
            ->all();

        $companyIds = $tags->filter(static fn (MovieTmdbTag $tag): bool => $tag->tag_kind === TmdbTagKind::Company)
            ->map(static fn (MovieTmdbTag $tag): int => $tag->tmdb_tag_id)
            ->sort()
            ->values()
            ->all();

        /** @var array<int, string> $companyNames */
        $companyNames = $companyIds === []
            ? []
            : TmdbCompany::query()->whereIn('tmdb_id', $companyIds)->pluck('name', 'tmdb_id')->all();

        $certifications = MovieCertification::query()
            ->where('movie_id', $fresh->id)
            ->orderBy('country')
            ->get();

        $titles = MovieTitle::query()->where('movie_id', $fresh->id)->orderBy('locale')->get();

        $aliases = Alias::query()
            ->where('movie_id', $fresh->id)
            ->where('origin', ContentOrigin::Tmdb->value)
            ->orderBy('locale')
            ->orderBy('alias')
            ->get();

        $collection = $fresh->collection_id === null
            ? null
            : Collection::query()->whereKey($fresh->collection_id)->value('name');

        $readAt = $certifications->max(static fn (MovieCertification $row): string => $row->read_at->toIso8601String());

        return new self(
            values: [
                'title_original' => [$fresh->title_original],
                'title_original_latin' => self::optional($fresh->title_original_latin),
                'original_language' => [$fresh->original_language],
                'release_year' => self::optional($fresh->release_year === null ? null : (string) $fresh->release_year),
                'vote_count' => [(string) $fresh->vote_count],
                'adult' => [$fresh->adult ? '1' : '0'],
                'collection_id' => self::optional(is_string($collection) ? $collection : null),
                'genres' => array_values(array_map(strval(...), $genres)),
                'companies' => array_values(array_map(
                    static fn (int $id): string => isset($companyNames[$id]) ? $companyNames[$id].' ('.$id.')' : (string) $id,
                    $companyIds,
                )),
                'movie_certification' => array_values($certifications->map(
                    static fn (MovieCertification $row): string => $row->country->value.' : '.$row->certification,
                )->all()),
                'movie_title' => array_values($titles
                    ->filter(static fn (MovieTitle $title): bool => $title->origin === ContentOrigin::Tmdb)
                    ->map(static fn (MovieTitle $title): string => $title->locale.' : '.$title->title)
                    ->all()),
                'alias' => array_values($aliases->map(static fn (Alias $alias): string => $alias->locale.' : '.$alias->alias)->all()),
                'content_flag' => [$fresh->content_flag->value],
            ],
            aliasKeys: array_values($aliases->map(static fn (Alias $alias): string => self::aliasKey($alias->locale, $alias->alias))->all()),
            curatorTitleLocales: array_values($titles
                ->filter(static fn (MovieTitle $title): bool => $title->origin === ContentOrigin::Curator)
                ->map(static fn (MovieTitle $title): string => $title->locale)
                ->all()),
            contentFlag: $fresh->content_flag->value,
            certificationsReadAt: is_string($readAt) ? $readAt : null,
        );
    }

    /**
     * La clé de comparaison d'un alias : locale et forme repliée en
     * minuscules, celle de `MovieImporter::writeTmdbAliases()`.
     */
    public static function aliasKey(string $locale, string $alias): string
    {
        return $locale.'|'.mb_strtolower(trim($alias));
    }

    /**
     * Les champs dont la valeur diffère entre deux instantanés, dans l'ordre
     * de {@see self::FIELDS} : c'est le `changed` de `movie.resynced`.
     *
     * @return list<string>
     */
    public function changedFields(self $after): array
    {
        $changed = [];

        foreach (self::FIELDS as $field) {
            if (($this->values[$field] ?? []) !== ($after->values[$field] ?? [])) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /** @return list<string> */
    private static function optional(?string $value): array
    {
        return $value === null || $value === '' ? [] : [$value];
    }
}
