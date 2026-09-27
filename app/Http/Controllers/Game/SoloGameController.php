<?php

namespace App\Http\Controllers\Game;

use App\Actions\Game\ClaimSeatTab;
use App\Actions\Game\StartSoloGame;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Room\Concerns\PresentsSeatForm;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Requests\Game\SoloStartRequest;
use App\Models\Player;
use App\Settings\PlatformLimits;
use App\Support\Game\CurrentGame;
use App\Support\Game\GameJournal;
use App\Support\Game\GameStateBuilder;
use App\Support\Game\SoloPresets;
use App\Support\Game\SoloSeat;
use App\Support\Identity\PlayerTokenManager;
use App\ValueObjects\Game\SoloStartOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Le mode solo : page d'entrée, démarrage et page de partie (spec 60 § 16 ;
 * contrat C7 § 2.4 et § 4.12 ; écart (l) du § 22 bis).
 *
 * - `solo.create`, `GET /solo/new` : la page d'entrée **`room/solo`**
 *   (`PublicLayout`, apparence du visiteur, domaines `room` et `legal`) —
 *   choix d'un des quatre presets, et pseudo et avatar du premier siège
 *   solo. Un GET ne frappe jamais de jeton (C4 I4.1) : le jeton courant est
 *   seulement lu. 303 vers `solo.show` quand le jeton tient déjà un siège
 *   solo — la relance se fait depuis `game/solo`.
 * - `solo.store`, `POST /solo` : {@see StartSoloGame}, puis 303 vers
 *   `solo.show`, l'annonce D19 en flash `settingsNotice`. Refus et échec
 *   technique reviennent sous le champ `preset`, dans la langue de la
 *   requête — jamais une 409 ni une 422 à une visite Inertia, jamais une 500
 *   brute en anglais (règle 4).
 * - `solo.show`, `GET /solo` : 303 vers `solo.create` sans siège solo tenu
 *   par le jeton (premier passage, § 16.4) ; sinon la page `game/solo` —
 *   voir {@see self::show()}.
 */
final class SoloGameController extends Controller
{
    use PresentsSeatForm;

    /** Message d'un échec technique du démarrage (§ 16.2), clé de 50 réutilisée. */
    public const string KEY_START_FAILED = 'room.errors.launch_failed';

    /** Libellé de la ligne du journal `game` d'un démarrage en échec (§ 16.2). */
    public const string LOG_START_FAILED = 'solo.start_failed';

    /**
     * Clé du flash de session qui porte `settingsNotice` de `solo.store` à
     * `solo.show` (contrat C7 § 4.12) : une annonce, lue une fois.
     */
    public const string SETTINGS_NOTICE = 'settingsNotice';

    /** Champ sous lequel reviennent un refus et un échec technique. */
    private const string ERROR_FIELD = 'preset';

    public function create(Request $request, PlayerTokenManager $tokens, SoloSeat $soloSeat, SoloPresets $presets): InertiaResponse|RedirectResponse
    {
        if ($soloSeat->of($request) !== null) {
            return to_route('solo.show', [], Response::HTTP_SEE_OTHER);
        }

        return Inertia::render('room/solo', [
            'presets' => $presets->options(),
            'avatars' => $this->avatarProps($tokens->current($request), []),
            'nickname' => $this->nicknameProps(),
        ]);
    }

    public function store(SoloStartRequest $request, StartSoloGame $start, SoloSeat $soloSeat): RedirectResponse
    {
        // À la milliseconde, comme `game.started_at` (`timestamp(3)`, `$now`
        // de StartSoloGame) : à la microseconde, une partie née dans la même
        // milliseconde que la requête paraîtrait antérieure à elle.
        $requestedAt = Date::now()->toImmutable()->startOfMillisecond();

        try {
            $outcome = $start->handle(
                $request,
                $request->preset(),
                $request->nickname(),
                $request->avatarPreset(),
                $this->effectiveLocale(),
            );
        } catch (Throwable $exception) {
            $started = self::startedSince($soloSeat->of($request), $requestedAt);
            self::journalFailure($exception, $started);

            // Levée après le COMMIT (poussée du job de la manche 1 sur une
            // file en panne) : la partie est née, rien n'a échoué pour le
            // joueur ; la manche sans job est rattrapée par `solo.state`.
            if ($started) {
                return to_route('solo.show', [], Response::HTTP_SEE_OTHER);
            }

            return $this->backWithError($request, $soloSeat, __(self::KEY_START_FAILED));
        }

        if (! $outcome->isStarted()) {
            return $this->refused($request, $soloSeat, $outcome);
        }

        $redirect = to_route('solo.show', [], Response::HTTP_SEE_OTHER);

        return $outcome->notice === null ? $redirect : $redirect->with(self::SETTINGS_NOTICE, $outcome->notice);
    }

    /**
     * La page de partie solo (§ 16.4, lots L60-15 et L60-16) :
     *
     * 1. aucun siège solo tenu par le jeton (jeton absent, ou aucun `player`
     *    solo non parti) → 303 vers `solo.create`, **sans frapper de jeton**
     *    (C4 I4.1, § 16.4) ;
     * 2. sinon `ClaimSeatTab` (l'onglet prend la main, § 12.7), puis
     *    `game/solo` avec :
     *    - `state` — la partie solo en cours, sinon la dernière partie close
     *      du siège pour son podium, sinon le paquet sans partie
     *      ({@see CurrentGame::forState()}), construit sur le jeton rendu ;
     *    - `seatToken`, jamais dans `state` ;
     *    - `settingsNotice` — flash de `solo.store` (D19), `null` sinon ;
     *    - `limits` — `PlatformLimits::toArray()`, prop de page comme au
     *      lobby : l'aide `GameHelp` y lit `speedBonusMaxPercent` (90 § 7.7) ;
     *    - `presets` — les quatre presets et leur `N` jouable le plus proche
     *      sur le vivier catalogue, même forme que sur `room/solo`, pour le
     *      choix du preset de la relance.
     *
     * Aucun rattrapage au rendu (§ 12.1 ne le demande qu'à `solo.state`) : le
     * client tire `solo.state` dès le montage (§ 16.4), qui rattrape. Les
     * fermetures ne sont évaluées que pour les props demandées : un
     * rechargement partiel ne reconstruit ni le paquet ni le vivier.
     */
    public function show(Request $request, SoloSeat $soloSeat, ClaimSeatTab $claim, SoloPresets $presets): InertiaResponse|RedirectResponse
    {
        $seat = $soloSeat->of($request);

        if ($seat === null) {
            return to_route('solo.create', [], Response::HTTP_SEE_OTHER);
        }

        $seatToken = $claim->handle($seat, EnsureActiveSeat::presentedToken($request));
        $now = Date::now()->toImmutable();
        $notice = $request->session()->get(self::SETTINGS_NOTICE);

        return Inertia::render('game/solo', [
            'state' => static fn (): array => GameStateBuilder::build(CurrentGame::forState($seat), $seat, $now, $seatToken),
            'seatToken' => $seatToken,
            'settingsNotice' => is_array($notice) ? $notice : null,
            'limits' => PlatformLimits::current()->toArray(),
            'presets' => static fn (): array => $presets->options(),
        ]);
    }

    /**
     * Un refus de règle, sous `preset`, dans la langue de la requête ; le
     * rapport de vivier d'un refus `pool_too_small` part en données, au seul
     * joueur (30 § 4.6).
     */
    private function refused(Request $request, SoloSeat $soloSeat, SoloStartOutcome $outcome): RedirectResponse
    {
        if ($outcome->pool !== null) {
            Inertia::flash('pool', $outcome->pool->toArray());
        }

        return $this->backWithError($request, $soloSeat, __($outcome->refusal()->messageKey()));
    }

    /**
     * Retour à la page d'où part le geste — `room/solo` au premier siège,
     * `game/solo` à une relance —, jamais l'accueil, même sans en-tête
     * `Referer` : la page de repli est celle que le jeton atteint sans
     * redirection supplémentaire, qui consommerait le message.
     */
    private function backWithError(Request $request, SoloSeat $soloSeat, mixed $message): RedirectResponse
    {
        $fallback = $soloSeat->of($request) === null ? route('solo.create') : route('solo.show');

        return back(Response::HTTP_SEE_OTHER, fallback: $fallback)
            ->withErrors([self::ERROR_FIELD => is_string($message) ? $message : self::KEY_START_FAILED]);
    }

    /**
     * Vrai si le siège solo du jeton porte une partie EN COURS née pendant
     * cette requête : l'échec est alors postérieur à la validation.
     * `$requestedAt` est à la milliseconde, précision de `started_at`. Une
     * relecture en échec (base perdue) vaut faux : le joueur lit l'échec
     * traduit et peut recommencer, la relance interrompant ce qui serait né.
     */
    private static function startedSince(?Player $seat, CarbonImmutable $requestedAt): bool
    {
        if ($seat === null) {
            return false;
        }

        try {
            $game = CurrentGame::of($seat);

            return $game !== null && $game->started_at->greaterThanOrEqualTo($requestedAt);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * La ligne du journal `game` d'un démarrage en échec (§ 16.2, § 4.7) :
     * classe, code et lieu de l'exception (fichier relatif à la racine du
     * dépôt), classe de sa cause, et si une partie est née. **Sans donnée
     * personnelle** : ni message ni trace, qui peuvent citer une requête et
     * ses valeurs (pseudo compris). Écrire le journal n'échoue jamais la
     * réponse.
     */
    private static function journalFailure(Throwable $exception, bool $started): void
    {
        try {
            Log::channel(GameJournal::CHANNEL)->error(self::LOG_START_FAILED, [
                'exception' => $exception::class,
                'code' => $exception->getCode(),
                'file' => str_replace('\\', '/', Str::after($exception->getFile(), base_path().DIRECTORY_SEPARATOR)),
                'line' => $exception->getLine(),
                'previous' => $exception->getPrevious() === null ? null : $exception->getPrevious()::class,
                'soloStarted' => $started,
            ]);
        } catch (Throwable) {
            // Le journal ne décide rien : la réponse traduite part quand même.
        }
    }
}
