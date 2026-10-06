<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Account\ChooseAvatar;
use App\Actions\Account\DeleteAvatar;
use App\Actions\Account\UploadAvatar;
use App\Avatars\AvatarImage;
use App\Avatars\AvatarPresetCatalog;
use App\Avatars\UploadedAvatars;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AvatarChoiceRequest;
use App\Http\Requests\Settings\AvatarUploadRequest;
use App\Models\User;
use App\Settings\PlatformLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * L'écran « Avatar » des réglages du compte — spec 40 § 11, D49 du 01/10.
 *
 * Un compte choisit sa nature effective (un prédéfini ou son image),
 * téléverse son image recadrée au carré par le navigateur, ou la supprime.
 * L'état de modération se lit ici, et nulle part ailleurs au J1 : aucune
 * notification par e-mail.
 */
class AvatarController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = self::user($request);

        return Inertia::render('settings/avatar', [
            'avatar' => $user->avatarRef()->toArray(),
            'kind' => $user->avatar_kind?->value,
            'preset' => $user->avatar_preset,
            'options' => AvatarPresetCatalog::options(),
            'upload' => [
                'url' => $user->hasVisibleUploadedAvatar() ? UploadedAvatars::url((string) $user->avatar_upload_path) : null,
                'stored' => $user->avatar_upload_path !== null,
                'blocked' => $user->isAvatarUploadBlocked(),
                'maxKilobytes' => PlatformLimits::avatarUploadMaxKilobytes(),
                'sourceSize' => AvatarImage::SOURCE_SIZE_PX,
            ],
            // La copie de la photo du fournisseur, seulement visible (spec 40
            // § 12.6) : un choix de plus, jamais téléversé ici.
            'provider' => [
                'url' => $user->hasVisibleProviderAvatar() ? UploadedAvatars::url((string) $user->avatar_provider_path) : null,
                'source' => $user->avatar_provider_source?->value,
            ],
        ]);
    }

    public function store(AvatarUploadRequest $request, UploadAvatar $upload): RedirectResponse
    {
        $upload->handle(self::user($request), $request->source());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.avatar.uploaded')]);

        return to_route('avatar.edit');
    }

    public function update(AvatarChoiceRequest $request, ChooseAvatar $choose): RedirectResponse
    {
        $choose->handle(self::user($request), $request->kind(), $request->preset());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.avatar.saved')]);

        return to_route('avatar.edit');
    }

    public function destroy(Request $request, DeleteAvatar $delete): RedirectResponse
    {
        $delete->handle(self::user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.avatar.deleted')]);

        return to_route('avatar.edit');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('L’écran « Avatar » exige le middleware auth.');
        }

        return $user;
    }
}
