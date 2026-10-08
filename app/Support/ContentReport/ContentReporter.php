<?php

namespace App\Support\ContentReport;

use App\Models\ContentReport;
use App\Models\Player;
use App\Models\User;
use App\Support\Identity\PlayerTokenManager;
use Illuminate\Http\Request;

/**
 * Le signaleur d'un contenu (D63 du 07/10) : le compte connecté, sinon le
 * siège le plus récent tenu par le `player_token` courant — lu par
 * {@see PlayerTokenManager::current()}, qui ne frappe jamais de jeton. Ni
 * compte ni siège : personne, et le signalement est refusé (règle 11 : on
 * signale après avoir joué, invité compris). Aucune IP, aucun visiteur.
 */
final readonly class ContentReporter
{
    private function __construct(
        public ?User $user,
        public ?Player $seat,
    ) {}

    /** Le signaleur de la requête, ou `null` : ni compte ni siège. */
    public static function fromRequest(Request $request): ?self
    {
        $user = $request->user();

        if ($user instanceof User) {
            return new self($user, null);
        }

        $token = app(PlayerTokenManager::class)->current($request);

        if ($token === null) {
            return null;
        }

        $seat = Player::query()
            ->heldByToken($token)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->first();

        return $seat instanceof Player ? new self(null, $seat) : null;
    }

    /** Vrai si ce signaleur a déjà signalé la cible de clé `$targetKey`. */
    public function hasReported(string $targetKey): bool
    {
        return ContentReport::query()
            ->where('target_key', $targetKey)
            ->when(
                $this->user !== null,
                fn ($query) => $query->where('reporter_user_id', $this->user?->id),
                fn ($query) => $query->where('reporter_player_id', $this->seat?->id),
            )
            ->exists();
    }
}
