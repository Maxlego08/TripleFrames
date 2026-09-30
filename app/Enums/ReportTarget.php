<?php

namespace App\Enums;

/** Nature de la cible d'un signalement, liste fermée à deux natures qui rend structurellement impossible de signaler un avatar prédéfini ou une image de jeu : cast de `report.target_type`. */
enum ReportTarget: string
{
    case Nickname = 'nickname';

    case ProviderAvatar = 'provider_avatar';
}
