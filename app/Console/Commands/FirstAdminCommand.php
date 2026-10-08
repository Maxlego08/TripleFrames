<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Concerns\RealNameValidationRules;
use App\Enums\AdminActionType;
use App\Enums\Locale;
use App\Enums\UserRole;
use App\Models\AdminAction;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Identity\NicknameNormalizer;
use App\Support\Preprod\PreprodAuthors;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Le tout premier administrateur, et lui seul — par la console (`20` § 2.5).
 *
 * **Pourquoi une commande et pas un écran.** L'attribution d'un rôle est un
 * geste d'administrateur (décision 9) ; le premier n'a, par définition, aucun
 * administrateur pour le nommer. Une route d'amorçage serait une porte ouverte
 * qu'il faudrait ensuite penser à refermer. C'est la SEULE commande que le
 * back-office exige jamais, exécutée une fois, par le porteur, en SSH, après
 * l'achat du domaine ; l'attribution des rôles suivants appartient à l'écran de
 * gestion des accès (spec 20 § 2.8).
 *
 * **Le nom réel est exigé** (D12 du 23/09) : c'est lui, et non le pseudo de
 * compte, que figent `frame_review.reviewer_name` et `admin_action.actor_name`.
 * Option `--real-name`, sinon une invite en session interactive, sinon un
 * refus — rien n'est écrit. Il passe par
 * {@see RealNameValidationRules::realNameRules()}.
 *
 * **L'accès au shell vaut preuve d'adresse**, sur les deux chemins : la
 * commande pose `email_verified_at` en promotion comme en création, et
 * `--create` est DÉFINITIF pour le premier administrateur. L'opérateur de la
 * console a plus de pouvoir qu'un administrateur ; exiger en plus un courriel
 * ajouterait une dépendance à un SMTP sans rien prouver.
 *
 * **Le mot de passe ne passe JAMAIS en argument.** Il serait écrit dans
 * l'historique du shell, dans la liste des processus et dans les journaux
 * d'exécution — d'où deux invites masquées, et aucune option `--password`.
 * Conséquence assumée : la création d'un compte exige une session interactive.
 *
 * **Idempotente.** Relancée sur un compte déjà administrateur, elle ne réécrit
 * rien et sort en succès. Avec `--real-name`, elle y CORRIGE le nom réel s'il
 * diffère — voie de secours depuis que l'écran de gestion des accès corrige un
 * nom réel (spec 20 § 2.8) —, sans ligne `role.changed`, puisque le rôle ne
 * change pas, et sans autre ligne : `user.real_name_changed` n'admet pas
 * l'acteur console (EN20-3), et l'opérateur de la console a de toute façon plus
 * de pouvoir qu'un administrateur. Les instantanés déjà figés ne sont jamais
 * réécrits : la correction ne vaut que pour les gestes suivants. Elle refuse de
 * nommer un second administrateur tant qu'un premier, non anonymisé, existe —
 * `--force` est une procédure de secours, jamais une voie d'attribution.
 *
 * **Une ligne de journal, une seule**, écrite par l'écrivain unique
 * {@see AdminJournal::recordFromConsole()} dans la transaction du rôle :
 * `role.changed`, acteur réservé {@see AdminAction::CONSOLE_ACTOR}, `actor_id`
 * NULL. Un compte créé ici naît `player` puis est promu, dans la même
 * transaction : le journal porte `role_before = player`, ce qui est la vérité.
 *
 * Tous les messages passent par des clés — domaine `admin`, français par
 * construction (décision 9). La locale est posée explicitement : une console ne
 * traverse aucun middleware, et la locale ambiante est `en`.
 */
class FirstAdminCommand extends Command
{
    use PasswordValidationRules, ProfileValidationRules, RealNameValidationRules;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:first-admin
        {email? : Adresse du compte à promouvoir, ou à créer s’il n’existe pas}
        {--name= : Nom du compte à créer, pour éviter une invite}
        {--real-name= : Nom réel, figé dans les preuves de revue et le journal}
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

    public function handle(AdminJournal $journal): int
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
            return $this->reconcileIncumbent($target, $email);
        }

        // L'administrateur inouvrable qui signe le catalogue de préproduction
        // n'est pas un administrateur en place (PreprodAuthors).
        $incumbent = User::query()
            ->where('role', UserRole::Admin)
            ->whereNull('anonymized_at')
            ->whereNotIn('email', PreprodAuthors::inertAdminEmails())
            ->first();

        if ($incumbent !== null) {
            if (! $this->forced()) {
                $this->components->error($this->message('already', ['name' => $incumbent->name]));

                return self::FAILURE;
            }

            $this->components->warn($this->message('forced', ['name' => $incumbent->name]));
        }

        // Rien n'est écrit avant que TOUT soit réuni : un compte créé puis
        // abandonné faute de nom réel serait un compte sans rôle que
        // personne n'a demandé.
        $user = $target ?? $this->draftAccount($email);

        if ($user === null) {
            return self::FAILURE;
        }

        $realName = $this->resolveRealName($user);

        if ($realName === null) {
            return self::FAILURE;
        }

        $created = ! $user->exists;

        $this->promote($user, $realName, $journal);

        if ($created) {
            $this->components->info($this->message('created', ['email' => $email]));
        }

        $this->components->info($this->message('promoted', [
            'email' => $email,
            'name' => $user->name,
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
     * L'administrateur en place : rien à faire, sauf une correction du nom réel
     * demandée par `--real-name` (D4 du 23/09). Aucune ligne de journal — le
     * rôle ne change pas, et `user.real_name_changed` n'admet pas l'acteur
     * console (EN20-3).
     */
    private function reconcileIncumbent(User $admin, string $email): int
    {
        $requested = $this->realNameOption();

        if ($requested === null || $requested === $admin->real_name) {
            $this->components->info($this->message('unchanged', ['email' => $email]));

            return self::SUCCESS;
        }

        if (! $this->validRealName($requested)) {
            return self::FAILURE;
        }

        $admin->real_name = $requested;
        $admin->save();

        $this->components->info($this->message('real_name_updated', ['email' => $email]));

        return self::SUCCESS;
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
     * Le compte à créer, composé mais PAS enregistré : il ne l'est que dans la
     * transaction de sa promotion. Réservé à une session interactive, puisque
     * le mot de passe ne peut venir que d'une invite masquée.
     */
    private function draftAccount(string $email): ?User
    {
        // Promouvoir est le cas nominal : un compte absent est un REFUS, sauf
        // demande explicite. Voir la note de classe.
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

        // La forme canonique du pseudo de jeu : le nom du compte en suit la
        // règle (spec 40 § 13.1).
        $name = NicknameNormalizer::canonical($name);

        if (! $this->passesOrExplains(['name' => $name], ['name' => $this->nameRules()], 'invalid_name')) {
            return null;
        }

        return $name;
    }

    /**
     * Le nom réel du futur administrateur : l'option, sinon une invite en
     * session interactive — proposant le nom réel déjà porté, s'il y en a
     * un —, sinon un refus. Jamais déduit de `users.name` : un pseudo n'est pas
     * un nom réel.
     */
    private function resolveRealName(User $user): ?string
    {
        $realName = $this->realNameOption();

        if ($realName === null) {
            if (! $this->interactive()) {
                $this->components->error($this->message('real_name_required'));

                return null;
            }

            $answer = $this->ask($this->message('real_name_prompt'), $user->real_name);
            $realName = is_string($answer) ? trim($answer) : '';
        }

        return $this->validRealName($realName) ? $realName : null;
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
     * Le compte, son nom réel, son rôle et sa trace, dans la MÊME transaction :
     * un compte promu sans ligne de journal est exactement l'élévation de
     * privilège qu'aucun audit ne retrouve.
     */
    private function promote(User $user, string $realName, AdminJournal $journal): void
    {
        DB::transaction(function () use ($user, $realName, $journal): void {
            // Un compte créé ici naît `player` : c'est ce que dira `role_before`.
            if (! $user->exists) {
                $user->save();
            }

            $before = $user->role;

            $user->real_name = $realName;
            $user->role = UserRole::Admin;

            // L'accès au shell vaut preuve d'adresse (`20` § 2.5, n° 15) : le
            // groupe `/admin` porte `verified`, et sans cette date le compte
            // fraîchement nommé atterrirait sur `/email/verify` pour y attendre
            // un message que personne n'a envoyé.
            if ($user->email_verified_at === null) {
                $user->email_verified_at = CarbonImmutable::now();
            }

            $user->save();

            // `subject_type` et `retention_class` ne sont jamais fournis : la
            // garde `creating` du modèle les dérive de l'action, et
            // `role.changed` est permanente.
            $journal->recordFromConsole(AdminActionType::RoleChanged, $user->id, null, $before, UserRole::Admin);
        });
    }

    /** La valeur de `--real-name`, rognée ; NULL si l'option est absente ou vide. */
    private function realNameOption(): ?string
    {
        $realName = $this->option('real-name');
        $realName = is_string($realName) ? trim($realName) : '';

        return $realName === '' ? null : $realName;
    }

    private function validRealName(string $realName): bool
    {
        return $this->passesOrExplains(
            ['real_name' => $realName],
            ['real_name' => $this->realNameRules()],
            'invalid_real_name',
        );
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
