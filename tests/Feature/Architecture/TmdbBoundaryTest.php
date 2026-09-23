<?php

/*
|--------------------------------------------------------------------------
| Règle 6, rendue mécanique
|--------------------------------------------------------------------------
|
| « Aucun appel TMDB pendant une partie. » La règle est tenue aujourd'hui —
| le client n'est injecté que par les commandes d'import — mais rien ne la
| MAINTENAIT : `TmdbClient` est résoluble par le conteneur depuis n'importe
| où, et le jour où l'écran de curation l'injectera dans un contrôleur — ce
| qui est légitime —, plus rien ne distinguerait un contrôleur
| d'administration d'un contrôleur de jeu. Un helper de « fiche film » ajouté
| plus tard dans un contrôleur de salon partirait sur TMDB en pleine manche,
| et la seule chose qui le révélerait serait la latence en production.
|
| `Http::preventStrayRequests()` ne protège que les tests ; ceci protège le
| code.
|
*/

arch('le client TMDB ne quitte jamais la console et le back-office')
    ->expect('App\Support\Tmdb\TmdbClient')
    ->toOnlyBeUsedIn([
        'App\Console\Commands',
        'App\Http\Controllers\Admin',
    ]);

arch('les DTO TMDB ne descendent jamais dans une surface de jeu')
    ->expect('App\Support\Tmdb')
    ->toOnlyBeUsedIn([
        'App\Console\Commands',
        'App\Http\Controllers\Admin',
        'App\Support\Catalog',
        'App\Support\Tmdb',
        'App\ValueObjects\Catalog',
    ]);
