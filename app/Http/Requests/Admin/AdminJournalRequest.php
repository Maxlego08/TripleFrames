<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Models\AdminAction;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Le contrat de la query string de l'écran « Journal » — acteur, type
 * d'action, période, sujet et pagination (spec 20 § 2.2, ligne 41 ; D41 du
 * 30/09).
 *
 * **Toute valeur est une colonne typée** : `actor_id` (ou l'un des deux
 * acteurs réservés, lus sur `actor_name`), `action`, `subject_type`,
 * `subject_id` et `created_at`. Aucun filtre ne lit `details` : la colonne
 * JSON est affichée, jamais interrogée (tests en SQLite, dev en MySQL).
 *
 * `movie` est le raccourci de l'historique d'un film (spec 20 § 2.10) : les
 * lignes du film ET celles de ses images, par `frame.movie_id`. Il exclut
 * `subject_type` et `subject_id`, qu'il remplace : les deux ensemble
 * désigneraient une intersection que personne n'a demandée.
 *
 * Un filtre refusé ne retombe jamais en silence sur « tout le journal » : il
 * renvoie à l'écran nu, erreurs en session, parce qu'un administrateur qui
 * croit lire l'historique d'un film et lit celui de tout le site en tirerait
 * une conclusion fausse.
 *
 * **Aucun message n'est écrit ici** : `attributes()` nomme chaque champ par une
 * clé `admin.validation.*` (règle 4).
 */
class AdminJournalRequest extends FormRequest
{
    /** Une page du journal. */
    public const int PER_PAGE = 50;

    /** Le format des deux bornes de période, celui d'un `<input type="date">`. */
    public const string DATE_FORMAT = 'Y-m-d';

    /**
     * Les deux acteurs réservés, filtrables comme une personne : ils n'ont pas
     * de compte, donc pas d'identifiant.
     *
     * @var list<string>
     */
    public const array RESERVED_ACTORS = [AdminAction::SYSTEM_ACTOR, AdminAction::CONSOLE_ACTOR];

    /**
     * Un filtre refusé renvoie à l'écran nu, jamais à la page précédente : le
     * lien partagé qui portait un filtre illisible ne boucle pas sur lui-même.
     *
     * @var string
     */
    protected $redirectRoute = 'admin.journal.index';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|Closure|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'actor' => ['nullable', 'string', 'regex:/^([1-9][0-9]{0,18}|'.implode('|', self::RESERVED_ACTORS).')$/'],
            'action' => ['nullable', Rule::enum(AdminActionType::class)],
            'from' => ['nullable', 'date_format:'.self::DATE_FORMAT],
            'to' => ['nullable', 'date_format:'.self::DATE_FORMAT, 'after_or_equal:from'],
            'subject_type' => ['nullable', Rule::enum(AdminActionSubject::class)],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'movie' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Un identifiant de sujet n'a de sens qu'avec un type de sujet qui en porte
     * un : seul, il mêlerait le film n° 12 et le compte n° 12 ; avec le site ou
     * l'ensemble des comptes, il ne désignerait rien.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['subject_type', 'subject_id', 'movie'])) {
                    return;
                }

                if ($this->filled('movie') && ($this->filled('subject_type') || $this->filled('subject_id'))) {
                    $validator->errors()->add('movie', __('admin.validation.journal_movie'));

                    return;
                }

                if ($this->filled('subject_id') && $this->subjectType()?->hasIdentifier() !== true) {
                    $validator->errors()->add('subject_id', __('admin.validation.journal_subject_id'));
                }
            },
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'actor' => __('admin.validation.journal_actor'),
            'action' => __('admin.validation.journal_action'),
            'from' => __('admin.validation.journal_from'),
            'to' => __('admin.validation.journal_to'),
            'subject_type' => __('admin.validation.journal_subject_type'),
            'subject_id' => __('admin.validation.journal_subject_id_field'),
            'movie' => __('admin.validation.journal_movie_field'),
        ];
    }

    /**
     * Les filtres tels que l'écran doit les réafficher — miroir exact de la
     * query string.
     *
     * @return array{actor: string|null, action: string|null, from: string|null, to: string|null, subject_type: string|null, subject_id: int|null, movie: int|null}
     */
    public function filters(): array
    {
        return [
            'actor' => $this->actor(),
            'action' => $this->actionType()?->value,
            'from' => $this->from()?->format(self::DATE_FORMAT),
            'to' => $this->to()?->format(self::DATE_FORMAT),
            'subject_type' => $this->subjectType()?->value,
            'subject_id' => $this->subjectId(),
            'movie' => $this->movieId(),
        ];
    }

    /** L'acteur filtré : un identifiant de compte en chiffres, `system`, `console`, ou `null`. */
    public function actor(): ?string
    {
        $value = trim((string) $this->string('actor'));

        return $value === '' ? null : $value;
    }

    /** L'identifiant de compte de l'acteur filtré, `null` pour un acteur réservé ou aucun filtre. */
    public function actorId(): ?int
    {
        $actor = $this->actor();

        return $actor !== null && ctype_digit($actor) ? (int) $actor : null;
    }

    /** L'acteur réservé filtré, s'il en est un. */
    public function reservedActor(): ?string
    {
        $actor = $this->actor();

        return in_array($actor, self::RESERVED_ACTORS, true) ? $actor : null;
    }

    public function actionType(): ?AdminActionType
    {
        return AdminActionType::tryFrom(trim((string) $this->string('action')));
    }

    public function subjectType(): ?AdminActionSubject
    {
        return AdminActionSubject::tryFrom(trim((string) $this->string('subject_type')));
    }

    public function subjectId(): ?int
    {
        return $this->filled('subject_id') ? $this->integer('subject_id') : null;
    }

    /** Le film de l'historique complet (lignes du film et de ses images), s'il en est un. */
    public function movieId(): ?int
    {
        return $this->filled('movie') ? $this->integer('movie') : null;
    }

    /** Début de période, à minuit inclus. */
    public function from(): ?CarbonImmutable
    {
        return $this->day('from')?->startOfDay();
    }

    /** Fin de période : le jour entier est inclus. */
    public function to(): ?CarbonImmutable
    {
        return $this->day('to')?->startOfDay();
    }

    private function day(string $key): ?CarbonImmutable
    {
        $value = trim((string) $this->string($key));

        if ($value === '') {
            return null;
        }

        $day = CarbonImmutable::createFromFormat(self::DATE_FORMAT, $value);

        return $day instanceof CarbonImmutable ? $day : null;
    }
}
