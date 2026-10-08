<?php

namespace Database\Seeders;

use App\Enums\Locale;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Preprod\PreprodAuthors;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Les deux **auteurs** du catalogue de démonstration de la préproduction
 * (`APP_ENV=staging`, spec 100 § 18, n° 5 de D66 du 07/10).
 *
 * Le catalogue de démonstration cite un curateur (`movie.content_verified_by_id`,
 * estampilles de `frame_review`) et un administrateur (lignes `admin_action`
 * permanentes) : sans eux, il n'a aucune preuve nominative à citer. Mais la
 * préproduction ne reçoit **jamais** les comptes de démonstration de
 * {@see DemoAccountsSeeder}, dont le mot de passe est connu et publié dans le
 * dépôt : une préproduction joignable sur Internet, même derrière une
 * authentification HTTP, ne porte aucun compte privilégié ouvrable par quiconque
 * lit ce fichier.
 *
 * D'où deux comptes **inouvrables** : mot de passe aléatoire jeté à la création
 * (jamais affiché ni journalisé), adresse non vérifiée sous le TLD réservé
 * `.test` (aucune réinitialisation ne peut aboutir), aucun consentement. Ils
 * signent la démonstration et rien d'autre ; l'administrateur de recette, lui,
 * est créé par `admin:first-admin` comme en production, sans `--force` :
 * l'administrateur semé ne compte jamais comme administrateur en place
 * ({@see PreprodAuthors::inertAdminEmails()}).
 *
 * **Jamais hors `staging`** : en `local` et `testing`, ce sont les comptes de
 * démonstration qui signent ; en production, rien.
 */
class PreprodCurationAccountsSeeder extends Seeder
{
    public const string CURATOR_EMAIL = PreprodAuthors::CURATOR_EMAIL;

    public const string ADMIN_EMAIL = PreprodAuthors::ADMIN_EMAIL;

    /** Noms réels FICTIFS (D12 du 23/09 : un rôle privilégié en exige un). */
    public const string CURATOR_REAL_NAME = 'Camille Préprod-Curation';

    public const string ADMIN_REAL_NAME = 'Alex Préprod-Administration';

    public function run(): void
    {
        self::assertSeedableEnvironment();

        DB::transaction(function (): void {
            $this->upsert(self::CURATOR_EMAIL, 'Curateur Préprod', self::CURATOR_REAL_NAME, UserRole::Curator);
            $this->upsert(self::ADMIN_EMAIL, 'Admin Préprod', self::ADMIN_REAL_NAME, UserRole::Admin);
        });
    }

    public static function assertSeedableEnvironment(): void
    {
        if (app()->environment('staging')) {
            return;
        }

        throw new RuntimeException(
            'Les auteurs du catalogue de préproduction ne sont posés qu’en `staging` ; APP_ENV vaut ['
            .app()->environment().'].',
        );
    }

    /**
     * Le compte du rôle demandé, ou une exception nommée.
     */
    public static function account(UserRole $role): User
    {
        $email = match ($role) {
            UserRole::Curator => self::CURATOR_EMAIL,
            UserRole::Admin => self::ADMIN_EMAIL,
            UserRole::Player => throw new RuntimeException('La préproduction ne pose aucun compte joueur.'),
        };

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            throw new RuntimeException(
                "Le compte [{$email}] n'existe pas : ".self::class.' doit tourner AVANT le catalogue.',
            );
        }

        return $user;
    }

    /**
     * Réconcilié par l'adresse ; le mot de passe n'est posé qu'à la création,
     * pour qu'un second passage ne réveille rien.
     */
    private function upsert(string $email, string $name, string $realName, UserRole $role): void
    {
        $user = User::query()->where('email', $email)->first() ?? new User;

        $user->name = $name;
        $user->real_name = $realName;
        $user->email = $email;
        $user->email_verified_at = null;
        $user->role = $role;
        $user->locale = Locale::French;

        if (! $user->exists) {
            $user->password = Hash::make(Str::random(64));
        }

        $user->save();
    }
}
