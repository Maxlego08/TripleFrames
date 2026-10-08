<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BanNickname;
use App\Actions\Admin\UnmaskNickname;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModerationBanRequest;
use App\Http\Requests\Admin\ModerationReasonRequest;
use App\Models\Player;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use LogicException;

/**
 * Les deux gestes de l'écran « Modération » — spec 20 § 11.5, règle : spec
 * 40 § 13.3 (D66 du 07/10). Administrateur seul (`PlayerPolicy::moderateNickname`,
 * `throttle:admin-curation`). `{player}` est lié par `id`, identifiant qui ne
 * sort que vers le back-office.
 *
 * - **Lever** (`nickname.unmasked`, motif facultatif) : refusé pour un siège
 *   banni ou non masqué ;
 * - **Bannir** (`nickname.banned`, motif obligatoire) : masquage définitif.
 */
class ModerationNicknameController extends Controller
{
    public function unmask(ModerationReasonRequest $request, Player $player, UnmaskNickname $unmask): RedirectResponse
    {
        $unmask->handle(self::actor($request), $player, $request->reason());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.moderation.unmasked')]);

        return back(fallback: route('admin.moderation.index'));
    }

    public function ban(ModerationBanRequest $request, Player $player, BanNickname $ban): RedirectResponse
    {
        $ban->handle(self::actor($request), $player, $request->reason());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.moderation.banned')]);

        return back(fallback: route('admin.moderation.index'));
    }

    private static function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('L’écran « Modération » exige le middleware auth.');
        }

        return $user;
    }
}
