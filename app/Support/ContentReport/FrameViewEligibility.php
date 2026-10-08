<?php

namespace App\Support\ContentReport;

use App\Enums\ContentAvailability;
use App\Enums\RoundStatus;
use App\Models\Frame;
use App\Models\RoundTier;
use App\Models\User;
use App\Support\Frames\FrameStoragePrefix;
use App\Support\Identity\PlayerTokenManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * L'aperçu de l'image signalée sur la page `/report` (D63 du 07/10, amendé
 * le 07/10 ; spec 90 § 4.5 bis) : **seul prédicat**, lu par la page pour
 * décider de `frame.imageUrl` et par la route `content-report.frame` à
 * CHAQUE service.
 *
 * L'image n'est montrée qu'à qui l'a **déjà vue** en jeu, sa manche
 * révélée : aucune information nouvelle pour un tricheur, aucune URL stable
 * et partageable d'une image protégée pour un tiers. Vrai si et seulement si :
 *
 * 1. **catalogue** — ni la frame ni son film ne sont `withdrawn` (retrait
 *    juridique) ni `suspended` (suspension conservatoire, refus prudent) ;
 *    une frame ou un film dépublié reste visible du joueur qui l'a vu. Le
 *    dérivé existe en base (`game_path` du préfixe `game/`, fichiers non
 *    effacés) ; jamais `master/` ;
 * 2. **vue et révélée** — il existe un `round_tier` dont la variante SERVIE
 *    est cette frame (`served_frame_id`), réellement ouvert (`served_at`
 *    non nul), dans une manche arrivée à la révélation
 *    (`round.status ∈ {revealing, completed}` ; une manche annulée n'a
 *    jamais été révélée) ;
 * 3. **participation** — cette manche a une participation (`round_player`,
 *    créée à l'ouverture du palier 1 pour chaque siège admis) d'un siège
 *    `player` lié au compte connecté (`player.user_id`) **ou** tenu par le
 *    `player_token` courant (`player_token_hash`), lu par
 *    {@see PlayerTokenManager::current()}, qui ne frappe jamais de jeton ;
 *    ce siège n'est pas expulsé (`kicked_at` nul, I4.9) et était présent à
 *    l'ouverture du palier (`left_at` nul ou postérieur à
 *    `round_tier.served_at`) : un siège parti avant n'a jamais reçu l'image.
 *    Un siège revenu (`left_at` remis à nul par le battement de cœur) compte
 *    comme présent : son absence ne laisse aucune trace.
 *
 * Une seule lecture d'existence, en lecture seule. Aucune IP, aucune session
 * anonyme : ni compte ni jeton, faux.
 */
final readonly class FrameViewEligibility
{
    public function __construct(
        private PlayerTokenManager $tokens,
    ) {}

    public function allows(Frame $frame, Request $request): bool
    {
        if (! self::catalogueAllows($frame)) {
            return false;
        }

        $user = $request->user();
        $userId = $user instanceof User ? $user->id : null;
        $tokenHash = $this->tokens->current($request)?->hash();

        if ($userId === null && $tokenHash === null) {
            return false;
        }

        $tiers = RoundTier::query();
        $servedAt = $tiers->qualifyColumn('served_at');

        return $tiers
            ->where('served_frame_id', $frame->id)
            ->whereNotNull('served_at')
            ->whereHas('round', static function (Builder $round) use ($userId, $tokenHash, $servedAt): void {
                $round->whereIn('status', [RoundStatus::Revealing->value, RoundStatus::Completed->value])
                    ->whereHas('roundPlayers.player', static function (Builder $player) use ($userId, $tokenHash, $servedAt): void {
                        $player->where(static function (Builder $seat) use ($userId, $tokenHash): void {
                            if ($userId !== null) {
                                $seat->orWhere('user_id', $userId);
                            }

                            if ($tokenHash !== null) {
                                $seat->orWhere('player_token_hash', $tokenHash);
                            }
                        })
                            // I4.9 : un siège expulsé ne compte jamais.
                            ->whereNull($player->qualifyColumn('kicked_at'))
                            // Présent quand le palier s'est ouvert : un siège
                            // parti avant `served_at` n'a jamais reçu l'image.
                            ->where(static function (Builder $present) use ($player, $servedAt): void {
                                $present->whereNull($player->qualifyColumn('left_at'))
                                    ->orWhereColumn($player->qualifyColumn('left_at'), '>', $servedAt);
                            });
                    });
            })
            ->exists();
    }

    /** (1) Ni retirée ni suspendue, frame comme film, et un dérivé `game/` présent en base. */
    private static function catalogueAllows(Frame $frame): bool
    {
        return self::availabilityAllows($frame->availability)
            && self::availabilityAllows($frame->movie->availability)
            && $frame->files_deleted_at === null
            && $frame->game_path !== null
            && FrameStoragePrefix::Game->owns($frame->game_path);
    }

    private static function availabilityAllows(ContentAvailability $availability): bool
    {
        return $availability !== ContentAvailability::Withdrawn
            && $availability !== ContentAvailability::Suspended;
    }
}
