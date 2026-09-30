<?php

/*
|--------------------------------------------------------------------------
| Pages publiques — spec 90 § 4.3
|--------------------------------------------------------------------------
|
| `contact_email` : l'adresse de contact publiée par le bloc de contact de
| chaque page. VIDE dans `.env.example` (CI à zéro secret, `composer setup`
| copie le fichier) et tant que le domaine n'est pas acheté : la page affiche
| alors la mention traduite `legal.contact.unavailable` au lieu d'une adresse,
| car une adresse publiée qui ne répond pas est pire qu'aucune. Une variable
| écrite vide rend une CHAÎNE VIDE, pas `null` : le contrôleur la traite comme
| absente. Aucun nom de domaine n'entre jamais dans ce fichier.
|
| `pages` : une entrée par cas de `App\Enums\LegalPage`.
|
| - `provisional` affiche le bandeau `legal.provisional` (« texte provisoire »).
|   Il passe à `false` page par page quand le texte du porteur ou de son
|   conseil remplace le squelette (jalon 2) : c'est un commit, jamais une
|   valeur de base ni d'environnement.
| - `updated_at` : date de dernière mise à jour du texte, `AAAA-MM-JJ`, ou
|   `null` tant que le texte est un squelette. Formatée par le client, en UTC.
|
*/

return [

    'contact_email' => env('LEGAL_CONTACT_EMAIL'),

    'pages' => [
        'notice' => ['provisional' => true, 'updated_at' => null],
        'terms' => ['provisional' => true, 'updated_at' => null],
        'privacy' => ['provisional' => true, 'updated_at' => null],
        'report' => ['provisional' => true, 'updated_at' => null],
    ],

];
