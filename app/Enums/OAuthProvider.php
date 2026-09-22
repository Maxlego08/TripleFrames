<?php

namespace App\Enums;

/** Fournisseur d'authentification sociale, valeur stable indépendamment du paquet Socialite : cast de `linked_account.provider`. */
enum OAuthProvider: string
{
    case Discord = 'discord';

    case Google = 'google';
}
