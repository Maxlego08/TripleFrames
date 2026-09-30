<?php

namespace App\Casts;

use App\ValueObjects\Admin\AdminActionDetails;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/**
 * Cast de `admin_action.details` (D41 du 30/09) : un JSON nullable, relu en
 * {@see AdminActionDetails}. Un cast `'array'` nu rendrait `mixed` au niveau 7
 * et laisserait n'importe quel appelant écrire un tableau libre.
 *
 * La ligne est en ajout seul : ni comparaison de valeur, ni normalisation à la
 * relecture — elle n'est jamais réécrite.
 *
 * @implements CastsAttributes<AdminActionDetails, AdminActionDetails>
 */
final class AdminActionDetailsCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return AdminActionDetails|null
     *
     * @throws UnexpectedValueException Charge illisible : l'échec est bruyant.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes)
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof AdminActionDetails) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($decoded)) {
            throw new UnexpectedValueException(
                "La colonne [{$key}] de [".$model::class.'] ne contient pas un objet JSON valide.',
            );
        }

        $values = [];

        foreach ($decoded as $field => $item) {
            if (is_string($field)) {
                $values[$field] = $item;
            }
        }

        return AdminActionDetails::fromStorage($values);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return string|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes)
    {
        if ($value === null) {
            return null;
        }

        return $value->toJson();
    }
}
