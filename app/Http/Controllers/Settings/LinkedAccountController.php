<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Account\UnlinkProvider;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Controller;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Support\Identity\OAuthProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * L'écran « Comptes liés » — `linked_accounts.edit` et
 * `linked_accounts.destroy` (spec 40 § 12.5, D51 du 01/10).
 *
 * Par fournisseur ACTIF : lié (avec sa date) ou à lier. Ni identifiant ni
 * adresse du fournisseur ne quittent le serveur. La déliaison passe par
 * `password.confirm` et, sous 2FA confirmée, un code.
 */
class LinkedAccountController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = self::user($request);

        $linked = LinkedAccount::query()
            ->where('user_id', $user->id)
            ->get(['provider', 'created_at'])
            ->keyBy(fn (LinkedAccount $account): string => $account->provider->value);

        $providers = [];

        foreach (OAuthProviders::enabled() as $provider) {
            $account = $linked->get($provider->value);

            $providers[] = [
                'provider' => $provider->value,
                'linked' => $account !== null,
                'linkedAt' => $account?->created_at?->toIso8601String(),
            ];
        }

        return Inertia::render('settings/accounts', [
            'providers' => $providers,
            'hasPassword' => $user->password !== null,
            'requiresCode' => $user->two_factor_confirmed_at !== null,
        ]);
    }

    public function destroy(Request $request, string $provider, UnlinkProvider $unlink): RedirectResponse
    {
        $resolved = OAuthController::enabledProvider($provider);
        $code = $request->input('code');

        $unlink->handle(self::user($request), $resolved, is_string($code) ? $code : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.linked.unlinked')]);

        return to_route('linked_accounts.edit');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('L’écran « Comptes liés » exige le middleware auth.');
        }

        return $user;
    }
}
