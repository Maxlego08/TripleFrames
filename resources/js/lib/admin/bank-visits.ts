/**
 * Les props qu'une ÉCRITURE de l'éditeur de la banque d'images recharge
 * (spec 20 § 6.1) : ajout, relance, re-recadrage, changement de niveau,
 * dépublication ou mise à l'écart d'une image, publication du film.
 *
 * Chaque écriture revient sur l'éditeur par une redirection. Cette visite
 * doit être PARTIELLE et nommer `backdrops` :
 *
 * - le serveur ne sert une prop différée qu'à une visite partielle qui la
 *   demande ; une visite complète l'omettrait, et le client, qui ne fusionne
 *   les props qu'en visite partielle, la viderait. La grille des visuels se
 *   démonterait alors — squelette, focus perdu — jusqu'au rechargement
 *   différé suivant ;
 * - nommée ici, elle revient dans la même réponse (liste de visuels en
 *   cache) : la grille reste montée, et ses `used_levels` suivent
 *   l'écriture.
 *
 * Les en-têtes de la visite partielle suivent la redirection : le GET qui
 * rend l'éditeur est partiel lui aussi. `abilities` suit l'état du film (une
 * suspension retire l'ajout, une publication retire « Publier le film »), et
 * `movie` porte ses conditions de publication ; `limits`, `captureEnabled` et
 * `pollSeconds` ne changent pas d'une écriture à l'autre.
 */
export const BANK_WRITE_PROPS: string[] = [
    'frames',
    'movie',
    'sequencePreview',
    'abilities',
    'backdrops',
];
