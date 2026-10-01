<?php

/*
|--------------------------------------------------------------------------
| Mesure des performances — spec 100 § 10.11, D47 du 01/10
|--------------------------------------------------------------------------
|
| Lu par `App\Support\Perf\PerfRecorder` et `App\Support\Perf\GameTraceWriter`
| seulement. Active partout par défaut, coupable par `PERF_ENABLED=false`
| (que `phpunit.xml` pose : les tests de la mesure l'activent eux-mêmes).
| La durée de conservation, elle, vit dans `RetentionWindows`, jamais ici.
|
*/

return [

    // Vide ou absente = active ; `false` coupe échantillons ET chronologie.
    // (`filter_var('')` vaudrait faux : le vide est traité à part.)
    'enabled' => in_array(env('PERF_ENABLED'), [null, ''], true)
        || filter_var(env('PERF_ENABLED'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false,

    // Part des requêtes et des jobs échantillonnés dans `perf_sample`, de 0 à
    // 1. La chronologie d'une partie (`game_trace`) n'est jamais échantillonnée.
    'sample_rate' => max(0.0, min(1.0, (float) env('PERF_SAMPLE_RATE', 1.0))),

    // Seuil, en millisecondes, d'une requête SQL retenue comme lente.
    'slow_query_ms' => max(1, (int) env('PERF_SLOW_QUERY_MS', 100)),

    // Au plus autant de requêtes lentes retenues par échantillon.
    'slow_queries_per_sample' => 20,

];
