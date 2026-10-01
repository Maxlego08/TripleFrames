<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use App\Enums\TmdbTagKind;
use App\Models\TmdbCompany;
use App\Support\Catalog\TmdbQuotaLimiter;
use App\Support\Tmdb\TmdbClient;
use App\Support\Tmdb\TmdbException;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Le rattrapage des noms de sociétés TMDB (spec 10 § 3.6 bis, spec 30 L30-8,
 * D43 du 01/10).
 *
 * Les films importés avant la table `tmdb_company` portent leurs sociétés en
 * étiquettes `movie_tmdb_tag` sans aucun nom. Cette commande lit les
 * identifiants distincts de ces étiquettes qui n'ont pas de ligne
 * `tmdb_company`, appelle TMDB **une fois par société** sous
 * {@see TmdbQuotaLimiter}, et n'écrit **que** `tmdb_company` — jamais `movie`,
 * donc hors de la règle 12, sans instantané. Une resynchronisation complète
 * aurait rempli la table aussi, mais en réécrivant `movie`.
 *
 * Sans clé TMDB, elle **échoue** plutôt que de la réclamer : jamais en CI.
 * `--dry-run` compte les sociétés à nommer sans appeler TMDB ni rien écrire ;
 * `--limit` borne le nombre de sociétés **nommées** par passage. Une société
 * inconnue de TMDB (404, nom vide) n'écrit aucune ligne et reste donc en
 * attente : si la borne portait sur les sociétés demandées, quelques
 * inconnues aux plus petits identifiants occuperaient la fenêtre à chaque
 * passage et la commande ne progresserait plus jamais.
 */
class CatalogCompanyNamesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:company-names
        {--dry-run : compter les sociétés à nommer, sans appeler TMDB ni rien écrire}
        {--limit= : nombre maximal de sociétés nommées par passage}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Nomme, depuis TMDB, les sociétés des étiquettes du catalogue qui n’ont pas encore de nom';

    public function __construct(
        private readonly TmdbClient $client,
        private readonly TmdbQuotaLimiter $limiter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Le domaine `admin` est français par construction : aucune requête
        // HTTP ne pose la locale d'une console.
        App::setLocale(Locale::French->value);

        $limit = $this->limit();

        if ($limit === false) {
            $this->components->error($this->message('admin.console.company_names.invalid_limit'));

            return self::FAILURE;
        }

        $pending = $this->pendingCompanyIds();

        if ($this->option('dry-run')) {
            $count = $limit === null ? count($pending) : min($limit, count($pending));

            $this->components->info($this->message('admin.console.company_names.dry_run', ['count' => $count]));

            return self::SUCCESS;
        }

        if (! $this->client->isConfigured()) {
            $this->components->error($this->message('admin.console.company_names.not_configured'));

            return self::FAILURE;
        }

        $named = 0;
        $unknown = 0;

        foreach ($pending as $tmdbId) {
            if ($limit !== null && $named >= $limit) {
                break;
            }

            try {
                $this->limiter->throttle();
                $name = $this->client->company($tmdbId);
            } catch (TmdbException $exception) {
                $this->components->error($this->message('admin.console.company_names.failed', [
                    'named' => $named,
                    'reason' => $this->message($exception->translationKey(), $exception->translationReplacements()),
                ]));

                return self::FAILURE;
            }

            if ($name === null) {
                $unknown++;

                continue;
            }

            TmdbCompany::query()->upsert([[
                'tmdb_id' => $tmdbId,
                'name' => mb_substr($name, 0, TmdbCompany::NAME_MAX_LENGTH),
            ]], ['tmdb_id'], ['name']);

            $named++;
        }

        $this->components->info($this->message('admin.console.company_names.done', [
            'named' => $named,
            'unknown' => $unknown,
        ]));

        return self::SUCCESS;
    }

    /**
     * `null` sans option, `false` pour une valeur qui n'est pas un entier
     * strictement positif.
     */
    private function limit(): int|false|null
    {
        $option = $this->option('limit');

        if ($option === null) {
            return null;
        }

        $value = trim($option);

        if ($value === '' || ! ctype_digit($value) || (int) $value < 1) {
            return false;
        }

        return (int) $value;
    }

    /**
     * Les identifiants de société des étiquettes sans ligne `tmdb_company`,
     * croissants, distincts.
     *
     * @return list<int>
     */
    private function pendingCompanyIds(): array
    {
        $query = DB::table('movie_tmdb_tag')
            ->where('tag_kind', TmdbTagKind::Company->value)
            ->whereNotExists(static fn (QueryBuilder $named): QueryBuilder => $named
                ->selectRaw('1')
                ->from('tmdb_company')
                ->whereColumn('tmdb_company.tmdb_id', 'movie_tmdb_tag.tmdb_tag_id'))
            ->distinct()
            ->orderBy('tmdb_tag_id');

        return array_values(array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $query->pluck('tmdb_tag_id')->all(),
        ));
    }

    /**
     * @param  array<string, string|int>  $replacements
     */
    private function message(string $key, array $replacements = []): string
    {
        $message = __($key, $replacements, Locale::French->value);

        return is_string($message) ? $message : $key;
    }
}
