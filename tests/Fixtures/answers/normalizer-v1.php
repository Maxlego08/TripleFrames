<?php

/*
|--------------------------------------------------------------------------
| Sorties figées du normaliseur — version 1 de la règle (spec 70 § 5.6)
|--------------------------------------------------------------------------
|
| Paires entrée → sortie MESURÉES sur le dépôt, jamais écrites à la main
| puis ajustées au code. Les seize premières paires de `normalize` sont
| celles du contrat C12, sans modification.
|
| Ce fichier n'est JAMAIS réécrit : un changement de sortie — étape du
| normaliseur, liste d'articles, règle romaine, ou mise à jour de
| `voku/portable-ascii` — ouvre la version 2 (`AnswerRules::VERSION`), avec
| son propre `normalizer-v2.php` et sa propre empreinte, puis une
| reprojection (`catalog:reproject`). Seul le fichier de la version
| courante est exécuté (`Tests\Support\Answers\AnswerRuleFixtures`).
|
| `fold` n'entre pas dans `validation_version` (§ 5.9) ; ses paires vivent
| ici par commodité, pas parce qu'elles versionnent quoi que ce soit.
|
| Des listes de paires, et non des tableaux indexés par l'entrée : une entrée
| numérique (« 007 ») deviendrait une clé entière.
|
*/

return [

    'normalize' => [
        // Contrat C12, seize paires.
        ['ガラスの果樹園', 'garasunoguo shu yuan'],
        ['千と千尋の神隠し', 'qian toqian xun noshen yin shi'],
        ['기생충', 'gisaengcung'],
        ['Ｔｏｋｙｏ', 'tokyo'],
        ['Rocky Ⅳ', 'rocky 4'],
        ['Rocky V', 'rocky 5'],
        ['Saw X', 'saw 10'],
        ['X-Men', 'x men'],
        ['I, Robot', 'i robot'],
        ['Final Fantasy VII', 'final fantasy 7'],
        ['Alien³', 'alien3'],
        ['Se7en', 'se7en'],
        ['Le Fabuleux Destin d\'Amélie Poulain', 'fabuleux destin d amelie poulain'],
        ['WALL·E', 'wall e'],
        ['Straße', 'strasse'],
        ['L\'Été', 'ete'],

        // Spec 70 § 5.6, mesurées.
        ['Malcolm X', 'malcolm 10'],
        ['xXx', '30'],
        ['The The Thing', 'the thing'],
        ['Star Wars : Épisode V - L\'Empire contre-attaque', 'star wars episode v l empire contre attaque'],

        // Latin déjà couvert par `Str::ascii()`, sorties inchangées (§ 5.2).
        ['Œdipe roi', 'oedipe roi'],
        ['Æon Flux', 'aeon flux'],
        ['Łódź', 'lodz'],
        ['Ōkami', 'okami'],

        // Cyrillique : la translittération peut allonger (`щ` → `shch`).
        ['Брат', 'brat'],
        ['Щелкунчик', 'shchelkunchik'],

        // Romains : bornes et formes non strictes.
        ['XXXIX', '39'],
        ['IIII', 'iiii'],
        ['XL', 'xl'],
        ['MCMLXXXIV', 'mcmlxxxiv'],
        ['Ⅻ', '12'],
        ['V pour Vendetta', 'v pour vendetta'],
        ['Part II The Return', 'part 2 the return'],

        // Chiffres arabes conservés tels quels.
        ['Agent 007', 'agent 007'],
        ['Toy Story 3', 'toy story 3'],

        // Étape 7 : troncature symétrique à 200 (§ 5.5), espace finale
        // retirée quand la coupe tombe juste après un mot (écart E37-1).
        // Expressions pour la lisibilité ; elles s'évaluent en chaînes.
        // Coupe après un mot : 199 caractères, sans espace finale.
        [str_repeat('abcd ', 50), str_repeat('abcd ', 39).'abcd'],
        // La translittération allonge (`ß` → `ss`) ; coupe au milieu d'un mot.
        ['Straße '.str_repeat('ß', 200), 'strasse '.str_repeat('s', 192)],
    ],

    'fold' => [
        ['José', 'jose'],
        ['Le Chat', 'le chat'],
        ['Jean-Luc_77', 'jean-luc_77'],
        ['ß-Boy', 'ss-boy'],
        ['  A  b  ', 'a b'],
        ['Rocky IV', 'rocky iv'],
        ["Tab\tNew\nLine", 'tab new line'],
        ['Ｔｏｋｙｏ', 'tokyo'],
    ],

];
