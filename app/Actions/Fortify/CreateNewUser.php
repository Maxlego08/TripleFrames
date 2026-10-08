<?php

namespace App\Actions\Fortify;

use App\Actions\Account\RecordConsents;
use App\Concerns\ConsentValidationRules;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\ConsentKind;
use App\Enums\Locale;
use App\Models\User;
use App\Support\I18n\Translations;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * L'inscription par mot de passe — spec 40 § 13.1 (L40-10, D66 du 07/10).
 *
 * Le nom suit la règle de pseudo de jeu, sans l'unicité par salon ; les deux
 * cases `terms` et `age` sont exigées. Une transaction : le compte, dans la
 * langue de la requête, puis ses deux consentements et leurs projections par
 * l'écrivain unique {@see RecordConsents}. AUCUN rôle : `role` garde son
 * défaut `player`. `last_login_at` est posé par la connexion qui suit
 * (`RecordLastLogin` sur `Login`).
 */
class CreateNewUser implements CreatesNewUsers
{
    use ConsentValidationRules, PasswordValidationRules, ProfileValidationRules;

    public function __construct(private RecordConsents $consents) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        $input['name'] = $this->prepareName($input['name'] ?? null);

        $validated = Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            ...$this->consentRules(),
        ], $this->consentMessages())->validate();

        return DB::transaction(function () use ($validated): User {
            $user = new User;
            $user->forceFill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'locale' => Locale::tryFrom(App::getLocale()) ?? Translations::fallback(),
            ])->save();

            $this->consents->handle($user, [ConsentKind::Terms, ConsentKind::Age]);

            return $user;
        });
    }
}
