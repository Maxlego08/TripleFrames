<?php

/*
|--------------------------------------------------------------------------
| Mesure d'audience — spec 100 § 10.12, D48 du 01/10
|--------------------------------------------------------------------------
|
| Lu par `App\Support\Audience\AudienceRecorder` et l'écran « Audience »
| seulement. Active partout par défaut, coupable par `AUDIENCE_ENABLED=false`
| (`off` dans `phpunit.xml` : les tests de l'audience l'activent eux-mêmes).
| Les durées vivent dans `AudienceRecorder` et `RetentionWindows`.
|
*/

return [

    // Vide ou absente = active. (`filter_var('')` vaudrait faux : le vide est
    // traité à part.)
    'enabled' => in_array(env('AUDIENCE_ENABLED'), [null, ''], true)
        || filter_var(env('AUDIENCE_ENABLED'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false,

];
