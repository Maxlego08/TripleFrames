<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\Locale;
use App\Enums\UserRole;
use App\Models\AdminAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Le tout premier administrateur, et lui seul — par la console.
 *
 * **Pourquoi une commande et pas un écran.** L'attribution d'un rôle est un
 * geste d'administrateur (décision 9) ; le premier n'a, par définition, aucun
 * administrateur pour le nommer. Une route d'amorçage serait une porte ouverte
 * qu'il faudrait ensuite penser à refermer. L'attribution des rôles SUIVANTS
 * appartient à l'écran de gestion des accès de la spec 20, avec sa règle du
 * dernier administrateur indéboulonnable.
 *
 * **Le cas nominal est la PROMOTION d'un compte existant, et rien d'autre.**
 * Faire naître un compte hors de Fortify, hors de `CreateNewUser`, et lui
 * décerner sa propre vérification d'adresse, ce serait trancher deux questions
 * qui appartiennent au domaine « comptes et authentification » — et les
 * trancher précisément sur le rôle le plus privilégié. La création reste donc
 * derrière `--create`, drapeau explicite et **provisoire** ; la question « le
 * premier administrateur peut-il être créé sans preuve d'adresse, ou doit-il
 * d'abord s'inscrire normalement ? » est consignée dans `docs/REPRISE.md`
 * § « À trancher par la spec 20 ».
 *
 * **Le mot de passe ne passe JAMAIS en argument.** Il serait écrit dans
 * l'historique du shell, dans la liste des processus et dans les journaux
 * d'exécution — d'où deux invites masquées, et aucune option `--password`.
 * Conséquence assumée : la création d'un compte exige une session interactive.
 * En `--no-interaction`, la commande promeut un compte existant et refuse
 * poliment d'en créer un.
 *
 * **Idempotente.** Relancée sur un compte déjà administrateur, elle ne réécrit
 * rien et sort en succès : un script de déploiement peut l'appeler à chaque
 * passage. Elle refuse en revanche de nommer un second administrateur tant
 * qu'un premier, non anonymisé, existe — `--force` lève ce refus et le dit.
 *
 * **Une ligne de journal, une seule, et son cas existe déjà.** Ce lot n'écrit
 * aucune autre ligne `admin_action` : {@see AdminActionType} est une liste
 * FERMÉE possédée par la spec 10, et `role.changed` est le seul cas qui décrit
 * ce geste. `actor_name` vaut `console` et non `system` — cette dernière est
 * réservée par la PHPDoc de l'enum aux deux seuls gestes automatiques, et la
 * garde `creating` de {@see AdminAction} refuserait l'insertion.
 *
 * **Un compte créé ici naît `player` puis est promu**, par le même chemin qu'un
 * compte existant : le journal porte alors `role_before = player`, ce qui est
 * la vérité, plutôt qu'un `role_before` nul qui laisserait croire à un rôle
 * inconnu.
 *
 * Tous les messages passent par des clés — domaine `admin`, français par
 * construction (décision 9). La locale est posée explicitement : une console ne
 * traverse aucun middleware, et la locale ambiante est `en`.
 */
class FirstAdminCommand extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:first-admin
        {email? : Adresse du compte à promouvoir, ou à créer s’il n’existe pas}
        {--name= : Nom du compte à créer, pour éviter une invite}
        {--create : Crée le compte quand l’adresse est inconnue, au lieu de refuser}
        {--force : Nomme un administrateur de plus alors qu’il en existe déjà un}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crée ou promeut le tout premier administrateur — le seul rôle qu’aucun écran ne peut attribuer';

    /** Préfixe des clés de traduction de cette commande. */
    public const string LANG_PREFIX = 'admin.console.first_admin.';

    /**
     * Valeur d'`admin_action.actor_name` pour un geste pris depuis la console.
     *
     * **Jamais `system`** : {@see AdminAction::SYSTEM_ACTOR} est réservée aux
     * deux gestes automatiques (`avatar.hidden`, `nickname.masked`) et la garde
     * `creating` du modèle lèverait. Une console n'est pas un seuil de
     * signalement : c'est une personne devant un terminal, et le journal doit
     * pouvoir les distinguer dix-huit mois plus tard.
     */
    public const string CONSOLE_ACTOR = 'console';

    public function handle(): int
    {
        $email = $this->resolveEmail();

        if ($email === null) {
            return self::FAILURE;
        }

        $target = User::query()->where('email', $email)->first();

        // Une pierre tombale du § 5.5 n'est pas un compte : la promouvoir
        // rendrait un administrateur sans adresse ni mot de passe.
        if ($target !== null && $target->anonymized_at !== null) {
            $this->components->error($this->message('anonymized', ['email' => $email]));

            return self::FAILURE;
        }

        // Idempotence, et elle passe AVANT le refus de doublon : relancer la
        // commande sur l'administrateur en place ne doit jamais échouer.
        if ($target !== null && $target->role === UserRole::Admin) {
            $this->components->info($this->message('unchanged', ['email' => $email]));

            return self::SUCCESS;
        }

        $incumbent = User::query()
            ->where('role', UserRole::Admin)
            ->whereNull('anonymized_at')
            ->first();

        if ($incumbent !== null) {
            if (! $this->forced()) {
                $this->components->error($this->message('already', ['name' => $incumbent->name]));

                return self::FAILURE;
            }

            $this->components->warn($this->message('forced', ['name' => $incumbent->name]));
        }

        if ($target === null) {
            $target = $this->createAccount($email);

            if ($target === null) {
                return self::FAILURE;
            }
        }

        $this->promote($target);

        $this->components->info($this->message('promoted', [
            'email' => $email,
            'name' => $target->name,
        ]));

        return self::SUCCESS;
    }

    /**
     * Pendant de `ForceAdminLocale` pour la console — même geste que
     * {@see CatalogImportCommand::initialize()}, pour la même raison :
     * `lang/en/admin.php` n'existe pas, et une commande tourne sous
     * `APP_LOCALE=en` sans qu'aucun middleware HTTP ne passe.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        App::setLocale(Locale::French->value);
    }

    /**
     * L'adresse : argument, ou invite. Validée avant toute requête — une chaîne
     * vide chercherait un compte sans adresse, et en trouverait un.
     */
    private function resolveEmail(): ?string
    {
        $email = $this->argument('email');
        $email = is_string($email) ? trim($email) : '';

        if ($email === '' && $this->interactive()) {
            $answer = $this->ask($this->message('ask_email'));
            $email = is_string($answer) ? trim($answer) : '';
        }

        // `ProfileValidationRules::emailRules()` n'est délibérément PAS réemployée :
        // elle porte `Rule::unique`, qui refuserait précisément le cas nominal de
        // cette commande — promouvoir un compte qui existe déjà.
        if (! $this->passesOrExplains(
            ['email' => $email],
            ['email' => ['required', 'string', 'email', 'max:255']],
            'invalid_email',
        )) {
            return null;
        }

        return $email;
    }

    /**
     * La création d'un compte : réservée à une session interactive, puisque le
     * mot de passe ne peut venir que d'une invite masquée.
     */
    private function createAccount(string $email): ?User
    {
        // Le contrat de ce lot dit « promouvoir » : un compte absent est un
        // REFUS, sauf demande explicite. Voir la note de classe.
        if (! $this->creates()) {
            $this->components->error($this->message('create_disabled', ['email' => $email]));

            return null;
        }

        if (! $this->interactive()) {
            $this->components->error($this->message('not_found', ['email' => $email]));

            return null;
        }

        if (! $this->confirm($this->message('confirm_create', ['email' => $email]), false)) {
            $this->components->warn($this->message('aborted'));

            return null;
        }

        $name = $this->resolveName();

        if ($name === null) {
            return null;
        }

        $password = $this->resolvePassword();

        if ($password === null) {
            return null;
        }

        $user = new User;
        $user->name = $name;
        $user->email = $email;
        // Le cast `hashed` de `User::casts()` hache à l'affectation : la valeur
        // en clair ne quitte jamais cette méthode.
        $user->password = $password;
        // Sans cette date, le compte fraîchement nommé atterrit sur
        // `/email/verify` — le groupe de routes d'administration porte
        // `verified` — et y attend un message que personne n'a envoyé.
        $user->email_verified_at = CarbonImmutable::now();
        $user->save();

        $this->components->info($this->message('created', ['email' => $email]));

        return $user;
    }

    private function resolveName(): ?string
    {
        $name = $this->option('name');
        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {
            $answer = $this->ask($this->message('ask_name'));
            $name = is_string($answer) ? trim($answer) : '';
        }

        if (! $this->passesOrExplains(['name' => $name], ['name' => $this->nameRules()], 'invalid_name')) {
            return null;
        }

        return $name;
    }

    /**
     * Deux invites masquées et la règle de mot de passe du dépôt — `confirmed`
     * comprise, dont le message de discordance vient de `lang/fr/validation.php`.
     */
    private function resolvePassword(): ?string
    {
        $password = $this->secret($this->message('ask_password'));
        $confirmation = $this->secret($this->message('ask_password_confirmation'));

        $password = is_string($password) ? $password : '';
        $confirmation = is_string($confirmation) ? $confirmation : '';

        if (! $this->passesOrExplains(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => $this->passwordRules()],
            'invalid_password',
        )) {
            return null;
        }

        return $password;
    }

    /**
     * Le rôle et sa trace, dans la MÊME transaction : un compte promu sans
     * ligne de journal est exactement l'élévation de privilège qu'aucun audit
     * ne retrouve.
     */
    private function promote(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $before = $user->role;

            $user->role = UserRole::Admin;

            // Même geste et même justification que `createAccount()` : le
            // groupe `/admin` porte `verified`, et sans cette date le compte
            // fraîchement nommé atterrit sur `/email/verify` pour y attendre
            // un message que personne n'a envoyé — `MAIL_MAILER=log` en
            // développement. La commande dirait « promu » quand le middleware
            // dit autre chose.
            if ($user->email_verified_at === null) {
                $user->email_verified_at = CarbonImmutable::now();
            }

            $user->save();

            $action = new AdminAction;
            $action->actor_id = null;
            $action->actor_name = self::CONSOLE_ACTOR;
            $action->action = AdminActionType::RoleChanged;
            $action->subject_type = AdminActionSubject::User;
            $action->subject_id = $user->id;
            $action->role_before = $before;
            $action->role_after = UserRole::Admin;
            // `retention_class` n'est jamais fournie : la garde `creating` du
            // modèle la dérive de l'action, et `role.changed` est permanente.
            $action->save();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     */
    private function passesOrExplains(array $data, array $rules, string $key): bool
    {
        $validator = Validator::make($data, $rules);

        if ($validator->passes()) {
            return true;
        }

        $this->components->error($this->message($key));
        $this->components->bulletList($validator->errors()->all());

        return false;
    }

    private function interactive(): bool
    {
        return ! (bool) $this->option('no-interaction');
    }

    private function forced(): bool
    {
        return (bool) $this->option('force');
    }

    private function creates(): bool
    {
        return (bool) $this->option('create');
    }

    /**
     * Une clé du domaine `admin`, rendue en français quelle que soit la locale
     * ambiante. Une clé absente est renvoyée TELLE QUELLE — même parti que
     * `App\Settings\RoomSettings` : un message tronqué se prend pour une
     * phrase, une clé brute se diagnostique.
     *
     * @param  array<string, int|string>  $replace
     */
    private function message(string $key, array $replace = []): string
    {
        $full = self::LANG_PREFIX.$key;

        $line = trans($full, $replace, Locale::French->value);

        return is_string($line) && $line !== $full ? $line : $full;
    }
}
