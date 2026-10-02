<?php

namespace App\Support\Room;

use App\Avatars\AvatarPresetCatalog;
use App\Models\Player;
use App\Models\Room;

/**
 * Les avatars prédéfinis TENUS dans un salon — spec 40 § 11.4, spec 50 § 7.3
 * et § 8.1 ; D55 du 02/10.
 *
 * Une clé est prise dès qu'un siège tenu (`holdingSeat()` : ni parti ni
 * expulsé) la porte en `avatar_preset`, **prédéfini de repli d'un siège
 * `upload` ou `provider` compris** : un masquage, une suppression ou un
 * déliement fait redescendre l'affichage de ce siège vers son repli sans
 * rien écrire sur `player`, donc ce repli doit rester unique.
 *
 * Seule source de la liste, partagée par la prise de siège (attribution
 * automatique), le changement d'avatar au lobby (refus d'une clé prise) et
 * la prop `avatars` du lobby. L'unicité ne tient que sous le verrou du
 * salon : aucune contrainte en base ne la porte (un siège parti garde sa
 * clé), et tout écrivain d'avatar prend ce verrou d'abord.
 *
 * Doublons résiduels acceptés (D55 du 02/10) : un siège revenu de `left` par
 * le battement de présence alors que sa clé a été prise entre-temps ; un
 * retardataire face à l'identité gelée d'un siège parti en partie ; un
 * effectif au-delà de la taille du catalogue.
 */
final class TakenAvatars
{
    /**
     * Les clés tenues, dans l'ordre du catalogue, sans doublon.
     *
     * @param  Player|null  $except  Siège dont la clé ne compte pas (le demandeur).
     * @return list<string>
     */
    public static function of(Room $room, ?Player $except = null): array
    {
        $held = Player::query()
            ->whereBelongsTo($room)
            ->holdingSeat()
            ->whereNotNull('avatar_preset')
            ->when($except !== null, static fn ($query) => $query->whereKeyNot($except?->id))
            ->pluck('avatar_preset')
            ->all();

        $held = array_flip(array_filter($held, is_string(...)));

        return array_values(array_filter(
            AvatarPresetCatalog::keys(),
            static fn (string $key): bool => isset($held[$key]),
        ));
    }
}
