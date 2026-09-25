# Avatars prédéfinis — `public/avatars/`

Ce répertoire porte les **24 avatars prédéfinis** du jeu (spec `40` § 6, contrat C5, D27 du 23/09) : des fichiers **statiques** de `public/`, publics et cacheables, jamais servis par la route des images de jeu. Aucun n'est une création du projet : ce sont des têtes d'animaux d'un pack de Kenney, sous licence CC0 1.0.

Un avatar est désigné par une **clé stable** (`preset-01` à `preset-24`, registre `App\Avatars\AvatarPresetCatalog`), jamais par un chemin. Changer de pack, c'est changer les fichiers de ce répertoire, leurs libellés `common.avatar.preset.*` (`lang/{en,fr}/common.php`) et la table « Clé → fichier d'origine » ci-dessous, **sans migration de schéma ni réécriture de ligne** (C5 I5.7). Un avatar déjà choisi change alors d'image sous la même clé : un remplacement après la mise en service est un **changement visible**, consigné dans le journal en fin de fichier.

## Le pack

|                                          |                                                                                                                                                                                                                                                                                                                                                        |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Nom**                                  | « Animal Pack Remastered » — le pack que la spec `40` § 6.1 nomme « Animal Pack Redux » (voir « Nom du pack » ci-dessous)                                                                                                                                                                                                                              |
| **Auteur**                               | Kenney Vleugels (Kenney, https://kenney.nl)                                                                                                                                                                                                                                                                                                            |
| **Licence**                              | Creative Commons Zero, **CC0 1.0 Universal** (https://creativecommons.org/publicdomain/zero/1.0/) : dédicace au domaine public ; usage commercial et redistribution permis, attribution non exigée                                                                                                                                                     |
| **Page officielle**                      | https://kenney.nl/assets/animal-pack-remastered — publié le 02/02/2018, 240 fichiers (30 sujets × 8 variantes), licence affichée « Creative Commons CC0 »                                                                                                                                                                                              |
| **URL de récupération**                  | https://kenney.nl/media/pages/assets/animal-pack-remastered/54a307a369-1774771709/kenney_animal-pack-remastered.zip (lien « Continue without donating… » de la page officielle)                                                                                                                                                                        |
| **Date de récupération**                 | 2026-09-25                                                                                                                                                                                                                                                                                                                                             |
| **Archive téléchargée**                  | `kenney_animal-pack-remastered.zip`, 3 600 298 octets                                                                                                                                                                                                                                                                                                  |
| **Empreinte SHA-256 de l'archive**       | `25c51ae89d0af358d60b75cfcebb76a72e9cb20e38453d90f0dfddd29234069a`                                                                                                                                                                                                                                                                                     |
| **Licence revérifiée au téléchargement** | le 2026-09-25, lors du lot L40-5 : la page officielle affiche « Creative Commons CC0 » avec un lien vers https://creativecommons.org/publicdomain/zero/1.0/, et le `License.txt` de l'archive (reproduit ci-dessous) déclare « License (Creative Commons Zero, CC0) ». **La vérification du porteur reste due** (section « Vérification du porteur »). |

### Nom du pack

Le 2026-09-25, l'adresse https://kenney.nl/assets/animal-pack-redux répond **404**. Le pack aux trente têtes rondes que décrit la spec `40` § 6.1 — ours, buffle, poussin, poule, vache, crocodile, chien, canard, éléphant, grenouille, girafe, chèvre, gorille, hippopotame, cheval, singe, élan, narval, hibou, panda, perroquet, manchot, cochon, lapin, rhinocéros, paresseux, serpent, morse, baleine, zèbre — est publié à l'adresse ci-dessus sous le nom « Animal Pack Remastered », et son `License.txt` porte ce nom. Les 24 sujets retenus par la spec y sont tous présents : aucun remplacement de sujet n'a été nécessaire. L'« Animal Pack » de 2015 (https://kenney.nl/assets/animal-pack, dix sujets) est un autre pack, non utilisé.

### `License.txt` de l'archive

Reproduit sans ses deux lignes vides d'ouverture, sans la tabulation de tête commune à toutes ses lignes ni ses fins de ligne CRLF ; les deux tabulations propres à chaque séparateur y sont rendues par huit espaces :

```text
Animal Pack Remastered

by Kenney Vleugels (Kenney.nl)

        ------------------------------

License (Creative Commons Zero, CC0)
http://creativecommons.org/publicdomain/zero/1.0/

You may use these assets in personal and commercial projects.
Credit (Kenney or www.kenney.nl) would be nice but is not mandatory.

        ------------------------------

Donate:   http://support.kenney.nl
Request:  http://request.kenney.nl

Follow on Twitter for updates: @KenneyNL (www.twitter.com/kenneynl)
```

### Texte de la licence CC0 1.0

Texte juridique récupéré le 2026-09-25 à l'adresse https://creativecommons.org/publicdomain/zero/1.0/legalcode.txt, reproduit tel quel :

```text
Creative Commons Legal Code

CC0 1.0 Universal

    CREATIVE COMMONS CORPORATION IS NOT A LAW FIRM AND DOES NOT PROVIDE
    LEGAL SERVICES. DISTRIBUTION OF THIS DOCUMENT DOES NOT CREATE AN
    ATTORNEY-CLIENT RELATIONSHIP. CREATIVE COMMONS PROVIDES THIS
    INFORMATION ON AN "AS-IS" BASIS. CREATIVE COMMONS MAKES NO WARRANTIES
    REGARDING THE USE OF THIS DOCUMENT OR THE INFORMATION OR WORKS
    PROVIDED HEREUNDER, AND DISCLAIMS LIABILITY FOR DAMAGES RESULTING FROM
    THE USE OF THIS DOCUMENT OR THE INFORMATION OR WORKS PROVIDED
    HEREUNDER.

Statement of Purpose

The laws of most jurisdictions throughout the world automatically confer
exclusive Copyright and Related Rights (defined below) upon the creator
and subsequent owner(s) (each and all, an "owner") of an original work of
authorship and/or a database (each, a "Work").

Certain owners wish to permanently relinquish those rights to a Work for
the purpose of contributing to a commons of creative, cultural and
scientific works ("Commons") that the public can reliably and without fear
of later claims of infringement build upon, modify, incorporate in other
works, reuse and redistribute as freely as possible in any form whatsoever
and for any purposes, including without limitation commercial purposes.
These owners may contribute to the Commons to promote the ideal of a free
culture and the further production of creative, cultural and scientific
works, or to gain reputation or greater distribution for their Work in
part through the use and efforts of others.

For these and/or other purposes and motivations, and without any
expectation of additional consideration or compensation, the person
associating CC0 with a Work (the "Affirmer"), to the extent that he or she
is an owner of Copyright and Related Rights in the Work, voluntarily
elects to apply CC0 to the Work and publicly distribute the Work under its
terms, with knowledge of his or her Copyright and Related Rights in the
Work and the meaning and intended legal effect of CC0 on those rights.

1. Copyright and Related Rights. A Work made available under CC0 may be
protected by copyright and related or neighboring rights ("Copyright and
Related Rights"). Copyright and Related Rights include, but are not
limited to, the following:

  i. the right to reproduce, adapt, distribute, perform, display,
     communicate, and translate a Work;
 ii. moral rights retained by the original author(s) and/or performer(s);
iii. publicity and privacy rights pertaining to a person's image or
     likeness depicted in a Work;
 iv. rights protecting against unfair competition in regards to a Work,
     subject to the limitations in paragraph 4(a), below;
  v. rights protecting the extraction, dissemination, use and reuse of data
     in a Work;
 vi. database rights (such as those arising under Directive 96/9/EC of the
     European Parliament and of the Council of 11 March 1996 on the legal
     protection of databases, and under any national implementation
     thereof, including any amended or successor version of such
     directive); and
vii. other similar, equivalent or corresponding rights throughout the
     world based on applicable law or treaty, and any national
     implementations thereof.

2. Waiver. To the greatest extent permitted by, but not in contravention
of, applicable law, Affirmer hereby overtly, fully, permanently,
irrevocably and unconditionally waives, abandons, and surrenders all of
Affirmer's Copyright and Related Rights and associated claims and causes
of action, whether now known or unknown (including existing as well as
future claims and causes of action), in the Work (i) in all territories
worldwide, (ii) for the maximum duration provided by applicable law or
treaty (including future time extensions), (iii) in any current or future
medium and for any number of copies, and (iv) for any purpose whatsoever,
including without limitation commercial, advertising or promotional
purposes (the "Waiver"). Affirmer makes the Waiver for the benefit of each
member of the public at large and to the detriment of Affirmer's heirs and
successors, fully intending that such Waiver shall not be subject to
revocation, rescission, cancellation, termination, or any other legal or
equitable action to disrupt the quiet enjoyment of the Work by the public
as contemplated by Affirmer's express Statement of Purpose.

3. Public License Fallback. Should any part of the Waiver for any reason
be judged legally invalid or ineffective under applicable law, then the
Waiver shall be preserved to the maximum extent permitted taking into
account Affirmer's express Statement of Purpose. In addition, to the
extent the Waiver is so judged Affirmer hereby grants to each affected
person a royalty-free, non transferable, non sublicensable, non exclusive,
irrevocable and unconditional license to exercise Affirmer's Copyright and
Related Rights in the Work (i) in all territories worldwide, (ii) for the
maximum duration provided by applicable law or treaty (including future
time extensions), (iii) in any current or future medium and for any number
of copies, and (iv) for any purpose whatsoever, including without
limitation commercial, advertising or promotional purposes (the
"License"). The License shall be deemed effective as of the date CC0 was
applied by Affirmer to the Work. Should any part of the License for any
reason be judged legally invalid or ineffective under applicable law, such
partial invalidity or ineffectiveness shall not invalidate the remainder
of the License, and in such case Affirmer hereby affirms that he or she
will not (i) exercise any of his or her remaining Copyright and Related
Rights in the Work or (ii) assert any associated claims and causes of
action with respect to the Work, in either case contrary to Affirmer's
express Statement of Purpose.

4. Limitations and Disclaimers.

 a. No trademark or patent rights held by Affirmer are waived, abandoned,
    surrendered, licensed or otherwise affected by this document.
 b. Affirmer offers the Work as-is and makes no representations or
    warranties of any kind concerning the Work, express, implied,
    statutory or otherwise, including without limitation warranties of
    title, merchantability, fitness for a particular purpose, non
    infringement, or the absence of latent or other defects, accuracy, or
    the present or absence of errors, whether or not discoverable, all to
    the greatest extent permissible under applicable law.
 c. Affirmer disclaims responsibility for clearing rights of other persons
    that may apply to the Work or any use thereof, including without
    limitation any person's Copyright and Related Rights in the Work.
    Further, Affirmer disclaims responsibility for obtaining any necessary
    consents, permissions or other rights required for any use of the
    Work.
 d. Affirmer understands and acknowledges that Creative Commons is not a
    party to this document and has no duty or obligation with respect to
    this CC0 or use of the Work.
```

## Transformations appliquées

Chaque fichier `preset-NN.webp` est dérivé d'**un seul** fichier de l'archive, nommé dans la table « Clé → fichier d'origine », par un script Imagick joué une fois lors du lot L40-5 (ImageMagick 7.1.1-46 par l'extension PHP `imagick`) :

1. **Variante** `PNG/Round/` : tête ronde, avec détails, sans contour. Les fichiers source mesurent de 128 à 269 px de large et de 128 à 176 px de haut ; le cercle de la tête y fait 128 px.
2. **Mise à l'échelle uniforme** × 1,24, filtre Lanczos : le cercle de la tête passe à 159 px. Le même facteur pour les 24 sujets, pour que les têtes aient la même taille dans le sélecteur ; c'est le plus grand qui garde chaque sujet, élan excepté, dans le disque inscrit de la toile, parce que les avatars s'affichent en pastille ronde (`Avatar` de shadcn, `rounded-full`).
3. **Recadrage** : la boîte englobante du sujet est centrée sur une toile **256 × 256**. Seul l'élan (`preset-13`) dépasse la toile : l'extrémité de ses bois est rognée à gauche et à droite.
4. **Fond transparent conservé** (canal alpha), métadonnées retirées (`stripImage`).
5. **WebP sans perte** (`webp:lossless=true`, `webp:method=6`) : de 9 446 à 15 412 octets par fichier, sous le plafond de 20 Ko (20 480 octets) de la spec `40` § 6.2.

Ces propriétés — WebP, 256 × 256, au plus 20 Ko, fond transparent — sont vérifiées par `tests/Feature/Identity/AvatarPresetTest.php`.

## Clé → fichier d'origine

| Clé         | Fichier d'origine dans l'archive | Sujet     |
| ----------- | -------------------------------- | --------- |
| `preset-01` | `PNG/Round/bear.png`             | bear      |
| `preset-02` | `PNG/Round/chick.png`            | chick     |
| `preset-03` | `PNG/Round/cow.png`              | cow       |
| `preset-04` | `PNG/Round/crocodile.png`        | crocodile |
| `preset-05` | `PNG/Round/dog.png`              | dog       |
| `preset-06` | `PNG/Round/duck.png`             | duck      |
| `preset-07` | `PNG/Round/elephant.png`         | elephant  |
| `preset-08` | `PNG/Round/frog.png`             | frog      |
| `preset-09` | `PNG/Round/giraffe.png`          | giraffe   |
| `preset-10` | `PNG/Round/hippo.png`            | hippo     |
| `preset-11` | `PNG/Round/horse.png`            | horse     |
| `preset-12` | `PNG/Round/monkey.png`           | monkey    |
| `preset-13` | `PNG/Round/moose.png`            | moose     |
| `preset-14` | `PNG/Round/owl.png`              | owl       |
| `preset-15` | `PNG/Round/panda.png`            | panda     |
| `preset-16` | `PNG/Round/parrot.png`           | parrot    |
| `preset-17` | `PNG/Round/penguin.png`          | penguin   |
| `preset-18` | `PNG/Round/pig.png`              | pig       |
| `preset-19` | `PNG/Round/rabbit.png`           | rabbit    |
| `preset-20` | `PNG/Round/rhino.png`            | rhino     |
| `preset-21` | `PNG/Round/sloth.png`            | sloth     |
| `preset-22` | `PNG/Round/walrus.png`           | walrus    |
| `preset-23` | `PNG/Round/whale.png`            | whale     |
| `preset-24` | `PNG/Round/zebra.png`            | zebra     |

Les libellés affichés, en français et en anglais, vivent dans `lang/{fr,en}/common.php`, sous `avatar.preset.*`.

## Lisibilité à 32 px sur le thème sombre

Planche de contrôle des 24 avatars réduits à 32 px, découpés en disque, sur le fond sombre du jeu (`--background`, `oklch(0.145 0 0)`) : chaque sujet est reconnaissable, y compris les deux plus sombres (manchot, baleine), qui se lisent par leurs zones claires. Vérifié à l'œil le 2026-09-25 lors du lot L40-5 ; **à confirmer par le porteur** avec la vérification de licence ci-dessous.

## Vérification du porteur (étape 64, D27 du 23/09)

La vérification de la licence au téléchargement est un **geste humain du porteur**, sur le chemin critique humain du jalon 1 (D36 du 23/09). Le lot L40-5 n'est pas clos tant qu'elle n'est pas consignée ici.

- **Date** : `[À FOURNIR : AAAA-MM-JJ]`
- **Constat** : `[À FOURNIR : licence lue sur la page officielle et dans License.txt ; nom du pack confirmé ; lisibilité à 32 px confirmée]`
- **Vérifié par** : `[À FOURNIR]`

## Journal des remplacements

| Date       | Changement                                                  | Clés concernées           |
| ---------- | ----------------------------------------------------------- | ------------------------- |
| 2026-09-25 | Livraison initiale (lot L40-5), avant toute mise en service | `preset-01` à `preset-24` |
