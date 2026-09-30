<?php

namespace App\Support\Admin;

use App\Enums\AdminActionType;
use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Models\User;
use App\ValueObjects\Admin\AdminActionDetails;
use App\ValueObjects\Catalog\ImportFilter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Throwable;

/**
 * L'ouverture d'un balayage depuis le web, et rien d'autre.
 *
 * Le contrôleur crée la ligne `import_run` **lui-même**, puis dispatche le job
 * qui appelle la commande. C'est ce partage qui évite de réécrire la moindre
 * ligne de logique d'import : la commande retrouve le balayage par
 * `--run=<id>`, l'estampille et le reprend.
 *
 * `started_at` reste **nul** à l'ouverture, et c'est le seul signal qui
 * distingue « en file » de « en cours » — `import_run.status` n'a que trois
 * valeurs et le schéma est clos, « en file » n'est donc pas une valeur d'enum
 * mais le couple `status = running` ET `started_at = null`.
 *
 * `is_widened` et les trois colonnes de filtre sont **figées ici**, au
 * démarrage : le défaut du site peut changer après coup, la preuve qu'un
 * balayage a été élargi, non (§ 9.1).
 *
 * **Chaque lancement depuis le back-office écrit sa ligne au journal**
 * (D41 du 30/09) — `import.discover_started`, `import.paste_started`,
 * `import.seed_list_started`, `import.resumed` —, sujet `import_run`, DANS la
 * transaction qui ouvre la ligne `import_run` ou relit celle qu'on reprend :
 * jamais de ligne pour un lancement refusé. `details` garde ce que rien
 * d'autre ne conserve : les identifiants d'un collage, le budget de pages.
 * Les commandes `catalog:import*` n'entrent pas ici : elles ouvrent leur
 * balayage elles-mêmes, et n'écrivent rien au journal.
 */
final class ImportLauncher
{
    /**
     * Ouvre une ligne `import_run` « en file ».
     *
     * `forceFill` et non `fill` : seules les trois colonnes de filtre sont
     * mass-assignables sur {@see ImportRun}, parce que `run_kind`, `status`,
     * `is_widened`, les quatre compteurs et le curseur sont écrits par le job
     * et par lui seul — aucune n'entre dans `#[Fillable]`.
     */
    public static function open(ImportRunKind $kind, ImportFilter $filter, ?int $actorId): ImportRun
    {
        $run = new ImportRun;

        $run->forceFill([
            ...$filter->toColumns(),
            'run_kind' => $kind,
            'status' => ImportRunStatus::Running,
            'actor_id' => $actorId,
            // Un collage n'élargit rien : il CONTOURNE. Marquer `is_widened`
            // sur une voie qui n'applique aucun filtre rendrait la colonne
            // illisible là où elle sert de preuve.
            'is_widened' => $kind === ImportRunKind::Discover && $filter->isWiderThanDefault(),
            'started_at' => null,
        ]);

        $run->save();

        return $run;
    }

    /**
     * Ouvre un balayage **sous verrou**, ou rend `null` si un balayage de la
     * même nature est déjà en cours.
     *
     * Le couple « vérifier puis insérer » ne peut pas être laissé nu : la
     * migration `import_run` ne pose aucune contrainte d'unicité — le schéma
     * appartient à la spec 10 et n'en veut pas — et `throttle:admin-import`
     * autorise douze envois par minute et par curateur, donc n'empêche aucun
     * envoi simultané. Deux POST parallèles passeraient tous deux la
     * vérification avant que l'un n'ait inséré, et `ShouldBeUnique` ne
     * rattraperait rien : les deux jobs portent des identifiants de balayage
     * distincts, donc deux clés d'unicité distinctes. Le verrou ferme la
     * fenêtre — le cache est sur le driver `database`, comme la file.
     *
     * Un verrou indisponible se lit comme un refus, pas comme une panne : un
     * second balayage est précisément ce qu'on refuse.
     *
     * La ligne `import_run` et la ligne du journal `$action` (un des trois
     * cas de lancement, D41 du 30/09) s'écrivent dans UNE transaction, sous le
     * verrou : un refus n'écrit ni l'une ni l'autre.
     *
     * @throws LogicException `$action` n'est pas un cas de lancement
     */
    public static function openExclusively(
        ImportRunKind $kind,
        ImportFilter $filter,
        User $actor,
        AdminActionType $action,
        AdminActionDetails $details,
    ): ?ImportRun {
        $opening = [
            AdminActionType::ImportDiscoverStarted,
            AdminActionType::ImportPasteStarted,
            AdminActionType::ImportSeedListStarted,
        ];

        if (! in_array($action, $opening, true)) {
            throw new LogicException('L\'action ['.$action->value.'] n\'ouvre pas un balayage.');
        }

        try {
            return Cache::lock('catalog-import-open-'.$kind->value, 10)->block(
                3,
                static fn (): ?ImportRun => self::hasOpenRun($kind)
                    ? null
                    : DB::transaction(static function () use ($kind, $filter, $actor, $action, $details): ImportRun {
                        $run = self::open($kind, $filter, $actor->id);

                        app(AdminJournal::class)->record($actor, $action, $run->id, details: $details);

                        return $run;
                    }),
            );
        } catch (LockTimeoutException) {
            return null;
        }
    }

    /**
     * Reprend un balayage `discover` suspendu : relu SOUS VERROU, sa garde et
     * sa reprenabilité rejouées, puis la ligne `import.resumed` écrite dans la
     * même transaction. Rend `false`, sans aucune écriture, si le balayage
     * n'est plus reprenable — terminé ou repris entre l'affichage et le clic.
     *
     * Le job est distribué par l'appelant, après le commit.
     *
     * @throws Throwable
     */
    public static function resume(ImportRun $run, User $actor, int $pages): bool
    {
        return DB::transaction(static function () use ($run, $actor, $pages): bool {
            $locked = ImportRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('update', $locked);

            if (! AdminCatalogPresenter::isResumable($locked)) {
                return false;
            }

            app(AdminJournal::class)->record(
                $actor,
                AdminActionType::ImportResumed,
                $locked->id,
                details: AdminActionDetails::importPages($pages),
            );

            return true;
        });
    }

    /**
     * Délai au-delà duquel un balayage `running` n'est plus une concurrence.
     *
     * Un balayage `discover` ne se clôt de lui-même que lorsque TOUTES les
     * langues du filtre sont épuisées : à cinq cents pages TMDB pour le filtre
     * par défaut, il reste `running` entre deux invocations, indéfiniment.
     * Traiter cet état comme « occupé » condamnerait le formulaire de balayage
     * après un seul usage, sans aucune route pour en sortir.
     */
    public const int BUSY_GRACE_MINUTES = 5;

    /**
     * Un balayage de cette nature est-il réellement en cours ?
     *
     * Servi par l'index `(status)`. Le refus est volontairement posé au
     * contrôleur et non au job : l'unicité de `ShouldBeUnique` porte sur
     * l'identifiant d'un balayage, et n'empêcherait donc pas d'en OUVRIR un
     * second — ce qui ferait deux curseurs concurrents sur le même quota TMDB
     * et deux jeux de compteurs dont aucun ne serait la preuve de rien.
     *
     * « Ouvert » et « en cours » ne sont PAS la même chose, et les confondre
     * est ce qui rendait l'écran inutilisable : est concurrent un balayage
     * qu'aucun worker n'a encore pris (`started_at = null`, l'état « en
     * file ») ou qu'un worker tient encore, mesuré à la fraîcheur de
     * `started_at` et de `last_request_at`. Un balayage **suspendu** depuis
     * plus de {@see self::BUSY_GRACE_MINUTES} minutes n'est plus une
     * concurrence : c'est un travail en attente, qu'on reprend par le bouton
     * « reprendre » sans pour autant s'interdire d'en lancer un autre.
     */
    public static function hasOpenRun(ImportRunKind $kind): bool
    {
        return self::openRuns($kind)->exists();
    }

    /**
     * Le balayage de cette nature qui tient le verrou, ou `null` — le PLUS
     * RÉCENT, selon exactement le prédicat de {@see self::hasOpenRun()}.
     *
     * C'est ce que l'écran d'import lie sous le bouton inactif de la liste
     * d'amorçage (spec 20 § 3.5) : le curateur suit le collage qui l'occupe.
     * Lire le verrou n'est pas le contourner : aucune écriture ne passe ici.
     */
    public static function openRun(ImportRunKind $kind): ?ImportRun
    {
        return self::openRuns($kind)->latest('id')->first();
    }

    /**
     * Le prédicat « ouvert », écrit une seule fois pour le refus et pour
     * l'affichage.
     *
     * @return Builder<ImportRun>
     */
    private static function openRuns(ImportRunKind $kind): Builder
    {
        $threshold = CarbonImmutable::now()->subMinutes(self::BUSY_GRACE_MINUTES);

        return ImportRun::query()
            ->where('status', ImportRunStatus::Running->value)
            ->where('run_kind', $kind->value)
            ->where(function (Builder $scoped) use ($threshold): void {
                $scoped->whereNull('started_at')
                    ->orWhere('started_at', '>=', $threshold)
                    ->orWhere('last_request_at', '>=', $threshold);
            });
    }
}
