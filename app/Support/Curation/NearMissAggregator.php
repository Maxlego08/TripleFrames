<?php

namespace App\Support\Curation;

use App\Enums\AnswerKeyKind;
use App\Enums\GuessSource;
use App\Models\AnswerKey;
use App\Models\NearMiss;
use App\Support\Catalog\AnswerKeyNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reconstruit la file de suggestions d'alias depuis les réponses texte
 * refusées, sans jamais conserver de lien vers un joueur dans `near_miss`.
 *
 * Une forme n'entre dans la file qu'après trois manches distinctes. Les
 * compteurs sont recalculés depuis la source à chaque passage : relancer le
 * traitement ne peut donc jamais compter deux fois une réponse. Les clics QCM
 * sont exclus, puisqu'ils ne sont pas des formulations proposées par un joueur.
 */
final class NearMissAggregator
{
    public const int MIN_DISTINCT_ROUNDS = 3;

    public const int RETENTION_DAYS = 90;

    private const string LOCK_KEY = 'catalog:near-misses:aggregate';

    private const int LOCK_SECONDS = 600;

    private const int LOCK_WAIT_SECONDS = 5;

    /**
     * @return int nombre de suggestions actives après reconstruction
     */
    public function refresh(): int
    {
        return (int) Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS)->block(
            self::LOCK_WAIT_SECONDS,
            function (): int {
                $cutoff = CarbonImmutable::now()->subDays(self::RETENTION_DAYS);

                $groups = DB::table('wrong_answer')
                    ->join('round', 'round.id', '=', 'wrong_answer.round_id')
                    ->where('wrong_answer.source', GuessSource::Text->value)
                    ->where('wrong_answer.received_at', '>=', $cutoff)
                    ->where('wrong_answer.submitted_normalized', '!=', '')
                    ->groupBy('round.movie_id', 'wrong_answer.submitted_normalized')
                    ->havingRaw('COUNT(DISTINCT wrong_answer.round_id) >= ?', [self::MIN_DISTINCT_ROUNDS])
                    ->orderBy('round.movie_id')
                    ->orderBy('wrong_answer.submitted_normalized')
                    ->get([
                        'round.movie_id',
                        'wrong_answer.submitted_normalized',
                        DB::raw('COUNT(*) AS occurrences'),
                        DB::raw('COUNT(DISTINCT wrong_answer.round_id) AS distinct_rounds'),
                        DB::raw('MIN(wrong_answer.received_at) AS first_seen_at'),
                        DB::raw('MAX(wrong_answer.received_at) AS last_seen_at'),
                    ]);

                /** @var list<int> $movieIds */
                $movieIds = $groups
                    ->pluck('movie_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                /** @var array<int, list<AnswerKey>> $keysByMovie */
                $keysByMovie = [];

                foreach (AnswerKey::query()->whereIn('movie_id', $movieIds)->get() as $key) {
                    $keysByMovie[$key->movie_id][] = $key;
                }

                return DB::transaction(function () use ($groups, $keysByMovie): int {
                    $keptIds = [];

                    foreach ($groups as $group) {
                        $movieId = (int) $group->movie_id;
                        $normalized = (string) $group->submitted_normalized;
                        $keys = $keysByMovie[$movieId] ?? [];

                        // Une clé exacte déjà acceptée n'est plus une suggestion.
                        // Une clé dérivée ambiguë reste pertinente : sa promotion
                        // en alias exact est précisément un geste que le curateur
                        // peut vouloir accomplir.
                        $alreadyAcceptedExactly = array_filter(
                            $keys,
                            static fn (AnswerKey $key): bool => $key->normalized === $normalized
                                && in_array($key->key_kind, [
                                    AnswerKeyKind::TitleOriginal,
                                    AnswerKeyKind::TitleLatin,
                                    AnswerKeyKind::Title,
                                    AnswerKeyKind::Alias,
                                ], true),
                        ) !== [];

                        if ($alreadyAcceptedExactly) {
                            continue;
                        }

                        $distance = $this->bestDistance($normalized, $keys);

                        /** @var NearMiss|null $line */
                        $line = NearMiss::query()
                            ->where('movie_id', $movieId)
                            ->where('normalized_text', $normalized)
                            ->first();

                        $line ??= new NearMiss;
                        $line->movie_id = $movieId;
                        $line->normalized_text = $normalized;
                        $line->occurrences = (int) $group->occurrences;
                        $line->distinct_rounds = (int) $group->distinct_rounds;
                        $line->best_distance = $distance;
                        $line->first_seen_on = CarbonImmutable::parse((string) $group->first_seen_at)->startOfMonth();
                        $line->last_seen_on = CarbonImmutable::parse((string) $group->last_seen_at)->startOfMonth();
                        $line->save();

                        $keptIds[] = $line->id;
                    }

                    $stale = NearMiss::query();

                    if ($keptIds !== []) {
                        $stale->whereNotIn('id', $keptIds);
                    }

                    $stale->delete();

                    return NearMiss::query()->whereNull('dismissed_at')->count();
                });
            },
        );
    }

    /**
     * @param  list<AnswerKey>  $keys
     */
    private function bestDistance(string $submitted, array $keys): int
    {
        $best = AnswerKeyNormalizer::MAX_NORMALIZED_LENGTH;

        foreach ($keys as $key) {
            $best = min($best, AnswerKeyNormalizer::distance($submitted, (string) $key->normalized));
        }

        return min(255, $best);
    }
}
