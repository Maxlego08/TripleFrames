<?php

namespace App\Console\Commands;

use App\Enums\DrainPhase;
use App\Enums\Locale;
use App\Support\Deploy\DeployDrain;
use App\Support\Deploy\DrainAlreadyRunning;
use App\Support\Game\GamesInProgress;
use App\ValueObjects\Deploy\DrainState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * `deploy:drain` — obtient une **fenêtre libre** de déploiement par un
 * drainage borné : spec 100 § 11.3 et § 11.4, contrat C18-bis (nom et
 * signature figés).
 *
 * Motif : un `reverb:restart` déconnecte tous les joueurs, et `composer
 * install`, les migrations, `optimize` et `queue:restart` cassent les requêtes
 * et les jobs de toute partie en cours, solo compris. Le drapeau interdit
 * tout NOUVEAU lancement ; aucune partie en cours n'est jamais coupée ni
 * retardée.
 *
 * **Invariants** (§ 11.3) :
 *
 * 1. un drapeau existe déjà → code **2**, rien n'est modifié ;
 * 2. sinon, drapeau posé en phase `draining`, échéance `now + timeout` ;
 * 3. `game:reschedule` est appelée **avant** d'attendre, pour qu'aucune
 *    partie aux jobs perdus ne bloque le drainage jusqu'à l'échéance (R-10).
 *    Une partie en échec y est rapportée et le drainage continue : elle reste
 *    comptée en cours, et l'échéance tranche ;
 * 4. relevé de {@see GamesInProgress::count()} toutes les `poll_seconds`, par
 *    `Sleep::for()` ;
 * 5. **deux relevés nuls consécutifs** → phase `window`, échéance
 *    `now + window`, annonce de la fenêtre, code **0** ;
 * 6. échéance atteinte sans fenêtre → `release()`, code **1** (abandon).
 *    Le drapeau expire à cet instant de toute façon (TTL) : aucun relevé
 *    n'est fait au-delà, et une fenêtre ne s'ouvre jamais après l'échéance.
 *    Si un autre drainage a posé son drapeau entre l'échéance et le réveil,
 *    celui-ci n'est pas levé.
 *
 * **Drapeau levé ailleurs** (`deploy:release` dans une autre session, puis
 * peut-être un autre drainage) : la commande le constate après chaque attente
 * et abandonne en code 1 **sans rien lever**, le drapeau n'étant plus le sien.
 *
 * **Mort de la commande** : aucun `finally` ne lève le drapeau ; son TTL le
 * fait expirer à l'échéance, ce qui reproduit exactement l'abandon. Le
 * porteur lance donc la commande dans une session qui survit à une coupure
 * SSH (`tmux`), ou accepte qu'une coupure vaille abandon à l'échéance.
 *
 * **Durées** : `--timeout` puis `deploy.drain_timeout_minutes`, sinon
 * {@see DeployDrain::defaultTimeoutMinutes()} ; `--window` puis
 * `deploy.window_minutes`. Une durée qui n'est pas un entier ≥ 1 est refusée
 * en code **2**, avant toute écriture.
 *
 * **Sortie** en français par clés littérales `admin.console.deploy.*` (locale
 * forcée, comme `admin:first-admin`). **Journal** : nombres et instants
 * seulement ; aucune ligne `admin_action` (le drainage n'est pas un geste sur
 * un sujet).
 */
class DeployDrainCommand extends Command
{
    /** Relevés nuls consécutifs qui ouvrent la fenêtre (§ 11.3, invariant 5). */
    private const int NULL_READINGS_FOR_WINDOW = 2;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy:drain {--timeout= : minutes} {--window= : minutes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bloque les nouveaux lancements et attend la fin des parties en cours, jusqu’à une fenêtre libre de déploiement';

    public function handle(DeployDrain $drain): int
    {
        $timeout = $this->minutes('timeout', Config::get('deploy.drain_timeout_minutes'), 'DEPLOY_DRAIN_TIMEOUT_MINUTES', DeployDrain::defaultTimeoutMinutes());
        $window = $this->minutes('window', Config::get('deploy.window_minutes'), 'DEPLOY_WINDOW_MINUTES', null);

        if ($timeout === null || $window === null) {
            return self::INVALID;
        }

        try {
            $state = $drain->start($timeout);
        } catch (DrainAlreadyRunning $running) {
            $this->components->error(self::text(trans('admin.console.deploy.already_running', [
                'phase' => self::phaseLabel($running->state->phase),
                'until' => self::instant($running->state->expiresAt),
            ], Locale::French->value)));

            return self::INVALID;
        }

        $this->components->info(self::text(trans('admin.console.deploy.started', [], Locale::French->value)));

        Log::notice('Drainage de déploiement commencé.', [
            'timeout_minutes' => $timeout,
            'started_at' => self::instant($state->startedAt),
            'expires_at' => self::instant($state->expiresAt),
        ]);

        // Invariant 3 : AVANT d'attendre, pour qu'une partie aux jobs perdus
        // retrouve sa terminaison bornée au lieu de tenir le drainage jusqu'à
        // l'échéance.
        if ($this->call('game:reschedule') !== self::SUCCESS) {
            Log::warning('game:reschedule a rapporté au moins une partie en échec pendant le drainage ; le drainage continue.');
        }

        return $this->await($drain, $state, $window);
    }

    /**
     * Relève les parties en cours jusqu'à deux relevés nuls consécutifs, ou
     * jusqu'à l'échéance du drainage.
     */
    private function await(DeployDrain $drain, DrainState $state, int $windowMinutes): int
    {
        $pollSeconds = Config::integer('deploy.poll_seconds');

        // Garde-fou d'une horloge qui n'avancerait pas (sommeil simulé sans
        // horloge synchronisée) : jamais plus de relevés que l'échéance n'en
        // admet, plus une marge. Sur une vraie horloge, l'échéance tombe avant.
        $maxReadings = (int) ceil(
            max(0, (int) $state->startedAt->diffInSeconds($state->expiresAt)) / max(1, $pollSeconds),
        ) + self::NULL_READINGS_FOR_WINDOW;

        $nullReadings = 0;
        $lastCount = null;

        for ($reading = 1; $reading <= $maxReadings; $reading++) {
            $count = GamesInProgress::count();
            $nullReadings = $count === 0 ? $nullReadings + 1 : 0;

            if ($nullReadings >= self::NULL_READINGS_FOR_WINDOW) {
                return $this->openWindow($drain, $windowMinutes);
            }

            if ($count > 0 && $count !== $lastCount) {
                $this->line(self::text(trans('admin.console.deploy.waiting', ['count' => $count], Locale::French->value)));
            }

            $lastCount = $count;

            Sleep::for($pollSeconds)->seconds();

            if ($state->isExpiredAt(self::now())) {
                return $this->abandon($drain, $count, release: self::mayRelease($drain->state(), $state));
            }

            if (! self::isOwnFlag($drain->state(), $state)) {
                return $this->abandon($drain, $count, release: false);
            }
        }

        return $this->abandon($drain, $lastCount ?? 0, release: self::mayRelease($drain->state(), $state));
    }

    private function openWindow(DeployDrain $drain, int $windowMinutes): int
    {
        $window = $drain->openWindow($windowMinutes);

        $this->components->info(self::text(trans('admin.console.deploy.window_open', [
            'until' => self::instant($window->expiresAt),
        ], Locale::French->value)));

        Log::notice('Fenêtre libre de déploiement ouverte.', [
            'window_minutes' => $windowMinutes,
            'started_at' => self::instant($window->startedAt),
            'expires_at' => self::instant($window->expiresAt),
        ]);

        return self::SUCCESS;
    }

    /**
     * Abandon (invariant 6) : le drapeau est levé s'il est encore le sien,
     * pour rouvrir les lancements sans attendre son TTL.
     */
    private function abandon(DeployDrain $drain, int $lastCount, bool $release): int
    {
        if ($release) {
            $drain->release();
        }

        $this->components->error(self::text(trans('admin.console.deploy.abandoned', [], Locale::French->value)));

        Log::warning('Drainage de déploiement abandonné.', [
            'games_in_progress' => $lastCount,
            'at' => self::instant(self::now()),
        ]);

        return self::FAILURE;
    }

    /**
     * Une durée en minutes : l'option si elle est donnée, sinon la valeur
     * configurée, sinon le défaut. `null` — refus écrit à la console — pour
     * tout ce qui n'est pas un entier ≥ 1.
     */
    private function minutes(string $option, mixed $configured, string $variable, ?int $default): ?int
    {
        $given = $this->option($option);

        if ($given !== null) {
            return $this->positiveMinutes($given, '--'.$option);
        }

        if ($configured === null && $default !== null) {
            return $default;
        }

        return $this->positiveMinutes($configured, $variable);
    }

    private function positiveMinutes(mixed $value, string $source): ?int
    {
        $minutes = is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        if (is_int($minutes)) {
            return $minutes;
        }

        $this->components->error(self::text(trans('admin.console.deploy.invalid_minutes', ['option' => $source], Locale::French->value)));

        return null;
    }

    /**
     * Le drapeau relu est-il encore celui que cette commande a posé ? Le
     * début du drainage l'identifie : il est conservé tant que le drapeau
     * vit, et un autre drainage en aurait un autre. La comparaison se fait
     * sur la forme écrite dans le cache, à la milliseconde, pour ne jamais
     * dépendre de la précision de l'état gardé en mémoire.
     */
    private static function isOwnFlag(?DrainState $current, DrainState $own): bool
    {
        return $current instanceof DrainState
            && $current->phase === DrainPhase::Draining
            && $current->toCache()['startedAt'] === $own->toCache()['startedAt'];
    }

    /**
     * L'abandon à l'échéance peut-il lever le drapeau ? Seulement s'il est
     * absent — le sien, échu, que {@see DeployDrain::state()} tient pour
     * absent — ou s'il est encore le sien. Un autre drainage posé entre
     * l'échéance et le réveil de cette commande garde son drapeau.
     */
    private static function mayRelease(?DrainState $current, DrainState $own): bool
    {
        return $current === null || self::isOwnFlag($current, $own);
    }

    /** La phase rendue en français, jamais sa valeur brute (§ 11.3). */
    private static function phaseLabel(DrainPhase $phase): string
    {
        return self::text(match ($phase) {
            DrainPhase::Draining => trans('admin.console.deploy.phase.draining', [], Locale::French->value),
            DrainPhase::Window => trans('admin.console.deploy.phase.window', [], Locale::French->value),
        });
    }

    /** Un instant pour la console : ISO-8601 UTC, à la seconde. */
    private static function instant(CarbonImmutable $instant): string
    {
        return $instant->utc()->toIso8601ZuluString();
    }

    private static function now(): CarbonImmutable
    {
        return Date::now()->toImmutable();
    }

    private static function text(mixed $translated): string
    {
        return is_string($translated) ? $translated : '';
    }
}
