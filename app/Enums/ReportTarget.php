<?php

namespace App\Enums;

/** Nature de la cible d'un signalement, liste fermée — pseudo, copie provider, image téléversée (D49 du 01/10) — qui rend structurellement impossible de signaler un avatar prédéfini ou une image de jeu : cast de `report.target_type`. */
enum ReportTarget: string
{
    case Nickname = 'nickname';

    case ProviderAvatar = 'provider_avatar';

    /** Image téléversée par le compte (D49 du 01/10) : `target_user_id` seul, comme la copie provider. */
    case UploadedAvatar = 'uploaded_avatar';

    /** Vrai pour les deux cibles qui visent un COMPTE (`target_user_id`), faux pour le pseudo d'un siège. */
    public function targetsUser(): bool
    {
        return $this !== self::Nickname;
    }
}
