<?php

namespace App\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\LocaleUpdated;

/**
 * Recâble `CarbonImmutable` sur la locale de l'application.
 *
 * Un listener et non le middleware : la même bascule doit s'appliquer dans un
 * **job de mail en file**, où aucun middleware HTTP ne tourne mais où Laravel
 * appelle bien `App::setLocale()` avec la locale figée à la mise en file.
 * Sans lui, une date dans un e-mail français s'écrit en anglais.
 *
 * `Date::use(CarbonImmutable::class)` étant actif dans `AppServiceProvider`,
 * c'est bien `CarbonImmutable` que l'on règle.
 */
class SyncCarbonLocale
{
    public function handle(LocaleUpdated $event): void
    {
        CarbonImmutable::setLocale($event->locale);
    }
}
