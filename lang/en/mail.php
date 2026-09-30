<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `mail` : objets et corps des e-mails du projet
    |--------------------------------------------------------------------------
    |
    | Domaine normatif de la spec 05, volontairement vide : le dépôt n’envoie
    | aujourd’hui que les notifications **du framework et de Fortify**, dont
    | toutes les chaînes sont des clés littérales et vivent donc dans
    | `lang/en.json` / `lang/fr.json`.
    |
    | Ce fichier accueillera les gabarits propres au projet, dont les messages
    | sortants vers un tiers extérieur (accusé de réception d’une demande de
    | retrait, notification de décision, réponse à un signalement). La spec 05
    | est formelle : ces gabarits **ne vivent pas dans le domaine `admin`** —
    | ils existent en FR **et** EN et entrent dans la couverture de clés
    | vérifiée en CI, parce que leur destinataire n’est pas le curateur mais un
    | inconnu dont la langue est stockée avec sa demande.
    |
    */

];
