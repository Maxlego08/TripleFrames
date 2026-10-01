<?php

namespace App\Http\Requests\Admin;

use App\Enums\ThemeKind;
use App\Models\Collection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * L'écran des thèmes — spec 20 § 9.6.
 *
 * Deux usages facultatifs, **jamais un 422 ni une redirection** (critique
 * C23) — l'écran est une lecture, et un paramètre faux ne doit pas renvoyer
 * le curateur ailleurs :
 *
 * - `rule_kind` : la nature dont le formulaire demande les options de règle,
 *   au rechargement partiel de la prop `rule_options` ;
 * - `create=saga` et `collection_id` : le préremplissage « Créer la saga
 *   depuis cette collection » de la fiche film.
 *
 * Aucune règle de validation : chaque accesseur lit sa valeur et rend `null`
 * pour une valeur absente ou hors liste.
 */
class ThemeIndexRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }

    /** La nature dont les options de règle sont demandées. */
    public function ruleKind(): ?ThemeKind
    {
        $kind = $this->query('rule_kind');

        return is_string($kind) ? ThemeKind::tryFrom($kind) : null;
    }

    /** La collection à préremplir pour une saga, si elle existe. */
    public function prefillCollection(): ?Collection
    {
        $collectionId = $this->query('collection_id');

        if ($this->query('create') !== ThemeKind::Saga->value
            || ! is_string($collectionId)
            || ! ctype_digit($collectionId)) {
            return null;
        }

        return Collection::query()->find((int) $collectionId);
    }
}
