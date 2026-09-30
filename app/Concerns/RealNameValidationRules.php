<?php

namespace App\Concerns;

use App\Enums\Locale;
use App\Models\AdminAction;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Les règles du NOM RÉEL d'un compte privilégié (D12 du 23/09, § 2.6 de `20`).
 *
 * Partagées par la commande du premier administrateur (jalon 1) et par la
 * correction du nom réel à l'écran de gestion des accès (jalon 2) : même
 * modèle que {@see ProfileValidationRules}, partagé entre un FormRequest et
 * une action Fortify.
 *
 * Le nom réel est ce que figent `frame_review.reviewer_name` et
 * `admin_action.actor_name` : c'est lui qui signe une preuve opposable. Il est
 * donc requis, borné par la colonne (`string(255)`), et jamais égal à l'un des
 * deux acteurs réservés du journal — `system` et `console` —, sans tenir
 * compte de la casse : une preuve signée « System » serait indiscernable d'un
 * geste automatique.
 *
 * La valeur est validée ROGNÉE : le middleware `TrimStrings` s'en charge sur
 * une requête HTTP, l'appelant console le fait lui-même avant de valider.
 */
trait RealNameValidationRules
{
    /**
     * @return array<int, ValidationRule|array<mixed>|string|Closure>
     */
    protected function realNameRules(): array
    {
        return [
            'required',
            'string',
            'min:2',
            'max:255',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! AdminAction::isReservedActorName($value)) {
                    return;
                }

                // Domaine `admin`, français par construction : résolu avec une
                // locale explicite, comme partout hors d'une requête du
                // back-office — une console tourne sous la locale ambiante `en`.
                $message = __('admin.validation.real_name', [], Locale::French->value);

                $fail(is_string($message) ? $message : 'admin.validation.real_name');
            },
        ];
    }
}
