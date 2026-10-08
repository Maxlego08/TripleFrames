<?php

namespace Database\Seeders;

use App\Actions\Account\RecordConsents;
use App\Enums\ConsentKind;
use App\Enums\Locale;
use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * **Exigence 5 du § 13.3** — trois comptes de démonstration, un par rôle, créés
 * AVANT le catalogue.
 *
 * L'ordre n'est pas cosmétique : le curateur porte `movie.content_verified_by_id`
 * et les trois colonnes d'estampille de `frame_review` (`reviewer_id`,
 * `reviewer_name`, `reviewer_role`), l'administrateur porte les lignes
 * `admin_action` permanentes. Un catalogue seedé avant eux n'aurait aucune preuve
 * nominative à citer, et la coche « contenu vérifié » qui rend un film publiable
 * n'aurait pas d'auteur.
 *
 * Sans les trois rôles, trois propriétés du produit ne sont testables par aucun
 * test : la propriété **absolue** d'une `saved_config` face à un administrateur,
 * l'exemption de purge des comptes privilégiés, et la file de back-office « rôle
 * privilégié sans 2FA ».
 *
 * **Jamais hors `local` et `testing`** : la garde est portée par
 * {@see DatabaseSeeder} et redoublée ici par
 * {@see self::assertSeedableEnvironment()}, parce qu'un `db:seed --class=`
 * contourne le point d'entrée. Plus stricte que celle du catalogue, qui admet
 * aussi `staging` (préproduction, spec 100 § 18) : un mot de passe publié dans
 * le dépôt n'entre jamais sur une machine servie. C'est une **liste blanche** :
 * `APP_ENV` vaut `local` dans le `.env.example` que `composer setup` recopie, donc
 * une garde adossée au seul nom `production` livrerait ces trois comptes, mot de
 * passe connu compris, sur toute machine servie installée par ce chemin.
 *
 * Les trois comptes portent **les deux lignes `user_consent`** en plus des trois
 * projections de `users` : les projections servent la garde d'affichage à la
 * connexion, les lignes portent l'historique et survivent à l'anonymisation. Un
 * compte qui ne porterait que les projections prouverait la mauvaise version dès
 * le premier changement de CGU.
 */
class DemoAccountsSeeder extends Seeder
{
    public const string PLAYER_EMAIL = 'player@tripleframes.test';

    public const string CURATOR_EMAIL = 'curator@tripleframes.test';

    public const string ADMIN_EMAIL = 'admin@tripleframes.test';

    /**
     * Mot de passe commun aux trois comptes. C'est une fixture locale, jamais un
     * secret : le seeder qui la pose ne tourne pas en production.
     */
    public const string PASSWORD = 'password';

    /**
     * Noms réels FICTIFS des deux comptes privilégiés (D12 du 23/09) : sans eux,
     * la garde `User::saving` refuse leur création, et les preuves du catalogue
     * de démonstration figeraient un pseudo. Distincts de `users.name`, pour que
     * l'écran qui confondrait les deux se voie.
     */
    public const string CURATOR_REAL_NAME = 'Camille Démo-Curation';

    public const string ADMIN_REAL_NAME = 'Alex Démo-Administration';

    public function run(): void
    {
        self::assertSeedableEnvironment();

        DB::transaction(function (): void {
            $this->account(self::PLAYER_EMAIL, 'Joueur Démo', null, UserRole::Player, Locale::French);
            $this->account(self::CURATOR_EMAIL, 'Curateur Démo', self::CURATOR_REAL_NAME, UserRole::Curator, Locale::French);
            $this->account(self::ADMIN_EMAIL, 'Admin Démo', self::ADMIN_REAL_NAME, UserRole::Admin, Locale::English);
        });
    }

    public static function assertSeedableEnvironment(): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        throw new RuntimeException(
            'Les comptes de démonstration ne tournent qu’en `local` ou en `testing` ; APP_ENV vaut ['
            .app()->environment().']. Leur mot de passe est publié dans le dépôt.',
        );
    }

    /**
     * Le compte demandé, réconcilié par son adresse.
     *
     * `role` et `real_name` sont posés en **assignation directe** : les deux
     * colonnes sont volontairement hors du `#[Fillable]` de {@see User}, une
     * élévation de privilège par requête étant exactement ce que la liste
     * d'assignation en masse empêche. Le nom réel est posé AVANT le rôle, dans
     * la même écriture : la garde `saving` lit les deux ensemble.
     */
    private function account(string $email, string $name, ?string $realName, UserRole $role, Locale $locale): User
    {
        $now = CarbonImmutable::now();

        $user = User::query()->where('email', $email)->first() ?? new User;

        $user->name = $name;
        $user->real_name = $realName;
        $user->email = $email;
        $user->email_verified_at = $now;
        $user->password = Hash::make(self::PASSWORD);
        $user->role = $role;
        $user->locale = $locale;
        $user->last_login_at = $now;
        $user->save();

        // Les CGU à la version COURANTE, par l'écrivain unique des
        // consentements (spec 40 § 13.1) : une version figée ici renverrait
        // les trois comptes vers l'interstitiel de ré-acceptation.
        app(RecordConsents::class)->handle($user, [ConsentKind::Terms, ConsentKind::Age]);

        return $user;
    }

    /**
     * Le compte du rôle demandé, ou une exception nommée.
     *
     * C'est le point d'entrée du catalogue de démonstration : il transforme
     * « les seeders ont tourné dans le désordre » en un message qui dit quoi
     * relancer, au lieu d'une violation de contrainte trois cents lignes plus loin.
     */
    public static function demoAccount(UserRole $role): User
    {
        $email = match ($role) {
            UserRole::Player => self::PLAYER_EMAIL,
            UserRole::Curator => self::CURATOR_EMAIL,
            UserRole::Admin => self::ADMIN_EMAIL,
        };

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            throw new RuntimeException(
                "Le compte de démonstration [{$email}] n'existe pas : "
                .self::class.' doit tourner AVANT le catalogue (§ 13.3, exigence 5).',
            );
        }

        return $user;
    }
}
