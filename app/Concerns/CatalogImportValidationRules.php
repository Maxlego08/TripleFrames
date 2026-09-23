<?php

namespace App\Concerns;

use App\Support\Catalog\TmdbIdentifierList;
use App\ValueObjects\Catalog\ImportFilter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Config;

/**
 * Les règles partagées par les deux voies d'import déclenchées depuis le
 * back-office — `admin.import.discover` et `admin.import.ids`.
 *
 * **Aucune valeur chiffrée n'est écrite ici** (règle 2) : le filtre de goût
 * vient de `config('catalog.import_filter')` par {@see ImportFilter}, et les
 * deux plafonds de l'écran — pages par envoi, identifiants par collage — de
 * `config('catalog.import')`. Un `max:5` littéral dans un FormRequest serait
 * exactement le défaut que la règle 2 nomme.
 *
 * Les plafonds ne sont pas des caprices : ils bornent le travail d'UN envoi
 * web, dont l'exécution est confiée à un worker `queue:listen --timeout=900`.
 * Un collage de 200 identifiants coûte 60 à 90 s d'appels TMDB séquentiels et
 * serait tué au milieu, laissant un balayage `running` sans cause visible ; la
 * console, elle, n'a pas cette borne et avale la liste d'amorçage d'un geste.
 *
 * Le filtre de CONTENU n'apparaît nulle part ici, et c'est structurel : il
 * n'est contournable par aucune voie, donc il n'est pas un champ de formulaire
 * (décision 12).
 */
trait CatalogImportValidationRules
{
    /**
     * Seuil de notoriété. `min:0` et non `min:1` : zéro vote est un filtre
     * légitime — il dit « je prends tout », et `is_widened` le tracera.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function minVotesRules(): array
    {
        return ['required', 'integer', 'min:0', 'max:1000000'];
    }

    /**
     * Langues originales acceptées. Liste **non vide** de codes à deux lettres,
     * et non une liste blanche : `config('catalog.import.language_choices')` ne
     * sert qu'à peupler l'écran. Un balayage qui va chercher du coréen est
     * permis — il est simplement marqué élargi.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function languagesRules(): array
    {
        return [
            'languages' => ['required', 'array', 'min:1', 'max:10'],
            'languages.*' => ['required', 'string', 'size:2', 'alpha'],
        ];
    }

    /**
     * Année de sortie minimale. La borne basse est 1888 — *Roundhay Garden
     * Scene*, le plus ancien film connu — et la borne haute l'année courante :
     * un balayage sur une année à venir ne rendrait jamais rien.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function minYearRules(): array
    {
        return ['required', 'integer', 'min:1888', 'max:'.CarbonImmutable::now()->year];
    }

    /**
     * Nombre de pages TMDB traitées par envoi, borné par la configuration.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function pagesRules(): array
    {
        return [
            'required',
            'integer',
            'min:'.self::pagesMin(),
            'max:'.self::pagesMax(),
        ];
    }

    /**
     * La zone de collage : du texte libre, dont on ne valide pas la forme mais
     * le **rendement**. {@see TmdbIdentifierList} est le seul lecteur autorisé,
     * et c'est lui qui décide ce qu'est un identifiant — un nombre nu, une URL
     * TMDB, plusieurs par ligne, un commentaire `#`.
     *
     * Deux refus distincts, et jamais confondus : « rien de lisible » n'est pas
     * « trop d'identifiants ».
     *
     * @return array<int, ValidationRule|array<mixed>|string|Closure>
     */
    protected function identifiersRules(): array
    {
        return [
            'required',
            'string',
            'max:20000',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }

                $identifiers = TmdbIdentifierList::parse(preg_split('/\R/u', $value) ?: []);

                if ($identifiers === []) {
                    $fail(__('admin.validation.ids.invalid'));

                    return;
                }

                if (count($identifiers) > self::pasteMaxIds()) {
                    $fail(__('admin.validation.ids.max', ['max' => self::pasteMaxIds()]));
                }
            },
        ];
    }

    /**
     * Les identifiants réellement lus dans le collage validé, dans l'ordre et
     * dédoublonnés.
     *
     * @return list<int>
     */
    protected function parseIdentifiers(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        return array_slice(
            TmdbIdentifierList::parse(preg_split('/\R/u', $raw) ?: []),
            0,
            self::pasteMaxIds(),
        );
    }

    /** Plafond d'identifiants par collage web. */
    public static function pasteMaxIds(): int
    {
        return max(1, Config::integer('catalog.import.paste_max_ids', 50));
    }

    /** Borne basse du nombre de pages d'un balayage web. */
    public static function pagesMin(): int
    {
        return max(1, Config::integer('catalog.import.pages_min', 1));
    }

    /** Borne haute du nombre de pages d'un balayage web. */
    public static function pagesMax(): int
    {
        return max(self::pagesMin(), Config::integer('catalog.import.pages_max', 5));
    }
}
