{{--
    Politique de confidentialité — corps français, squelette du jalon 1
    (spec 90 § 4.4). Même régime que les mentions légales : rendu par
    `LegalPageController`, injecté dans un `<div lang="fr">`, aucune classe.

    Uniquement des faits déjà vrais, et `[À FOURNIR : …]` partout ailleurs.
    Les destinataires sont écrits en TROIS catégories, jamais mélangées
    (`00` § Ouverture) ; un tiers n'est nommé que lorsqu'il est réellement
    contractualisé et branché. L'inventaire des cookies est NORMATIF et
    factuel dès le jalon 1 : c'est le tableau de la spec 90 § 4.6, repris tel
    quel ; tout nouveau cookie l'amende AVANT sa mise en service. Le nom et la
    durée du cookie de session sont lus dans la configuration, pour que la page
    dise ce que le serveur pose réellement. Le tableau vit dans une région
    étiquetée et focalisable, qui défile seule sur un petit écran (styles du
    conteneur de `legal/show`). Interdits sans exception : toute
    qualification du service de « non commercial », tout nom de
    sous-traitant, tout délai d'engagement.
--}}
<h2>Responsable du traitement</h2>
<p>Le responsable du traitement est une personne physique : [À FOURNIR : nom]. Contact : voir le bloc « Contact » en bas de cette page.</p>

<h2>Données traitées</h2>
<p>On joue sans compte. Pour un joueur invité, le site traite :</p>
<ul>
    <li>le pseudo choisi ;</li>
    <li>le jeton de joueur (<code>player_token</code>), qui retient le siège occupé dans un salon ;</li>
    <li>la langue d’interface ;</li>
    <li>l’avatar choisi parmi ceux proposés ;</li>
    <li>l’adresse IP, dans les journaux techniques du serveur et dans la session ;</li>
    <li>les scores et les horodatages des parties ;</li>
    <li>seulement si vous l’acceptez (bannière « Être reconnu d’une partie à l’autre ? ») : un identifiant de visiteur, qui relie vos sièges successifs, et le type de votre appareil, réduit à trois familles (classe d’appareil, navigateur, système), sans version ni modèle.</li>
</ul>

<h2>Base légale</h2>
<p>Ces données sont traitées pour l’exécution du service : permettre de jouer. L’identifiant de visiteur et le type d’appareil reposent sur votre consentement, retirable à tout moment (section « Vos préférences de cookies » en bas de cette page) ; le retrait supprime l’identifiant.</p>

<h2>Destinataires</h2>
<ul>
    <li>Sous-traitants techniques : [À FOURNIR : noms, cités une fois contractualisés].</li>
    <li>Services tiers que le joueur active lui-même : aucun à ce jour.</li>
    <li>Tiers qui ne reçoivent aucune donnée des joueurs : TMDB, source des informations sur les films.</li>
</ul>

<h2>Durées de conservation</h2>
<ul>
    <li>Pseudo d’un siège : 12 mois après la dernière activité du siège, puis anonymisé ; le jeton de joueur est effacé dès l’archivage du salon (24 heures sans activité).</li>
    <li>Identifiant de visiteur et type d’appareil : 13 mois après la dernière visite pour l’identifiant, 12 mois pour son lien aux sièges ; supprimés dès le retrait du consentement.</li>
    <li>[À FOURNIR : le reste du tableau des durées de conservation]</li>
</ul>

<h2 id="legal-cookies-heading">Cookies et stockage local</h2>
<p>Les cookies posés par le site sont strictement nécessaires à son fonctionnement ou mémorisent un choix que vous avez exprimé, à une exception : le cookie <code>visitor</code>, qui n’est déposé qu’avec votre accord, donné ou refusé sur la bannière de consentement. Refuser est aussi simple qu’accepter, et ne change rien au jeu.</p>
<div role="region" aria-labelledby="legal-cookies-heading" tabindex="0">
    <table>
        <thead>
            <tr>
                <th scope="col">Nom</th>
                <th scope="col">Finalité</th>
                <th scope="col">Durée</th>
                <th scope="col">Nature</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>{{ config('session.cookie') }}</code> (session)</td>
                <td>Session : authentification d’un compte, jeton anti-falsification, messages ponctuels</td>
                <td>{{ config('session.lifetime') }} minutes, prolongées à chaque visite</td>
                <td>HttpOnly, chiffré ; strictement nécessaire</td>
            </tr>
            <tr>
                <td><code>XSRF-TOKEN</code></td>
                <td>Protection contre la falsification de requête</td>
                <td>Celle de la session</td>
                <td>Lisible par le navigateur ; strictement nécessaire</td>
            </tr>
            <tr>
                <td><code>remember_web_*</code></td>
                <td>« Se souvenir de moi », seulement si la case a été cochée</td>
                <td>400 jours</td>
                <td>HttpOnly, chiffré ; demandé par l’utilisateur</td>
            </tr>
            <tr>
                <td><code>player_token</code></td>
                <td>Siège d’invité et reprise, langue et avatar prédéfini</td>
                <td>30 jours après la dernière prise de siège ou le dernier changement de langue</td>
                <td>HttpOnly, chiffré ; strictement nécessaire</td>
            </tr>
            <tr>
                <td><code>locale</code></td>
                <td>Langue choisie</td>
                <td>1 an</td>
                <td>En clair ; préférence</td>
            </tr>
            <tr>
                <td><code>consent</code></td>
                <td>Votre choix sur la bannière de consentement</td>
                <td>13 mois si vous acceptez, 6 mois si vous refusez</td>
                <td>HttpOnly, chiffré ; mémorise un choix</td>
            </tr>
            <tr>
                <td><code>visitor</code></td>
                <td>Identifiant de visiteur : relier vos parties successives pour améliorer le jeu</td>
                <td>13 mois</td>
                <td>HttpOnly, chiffré ; soumis à votre consentement</td>
            </tr>
            <tr>
                <td><code>sidebar_state</code></td>
                <td>Barre latérale ouverte ou repliée (écrans de compte et d’administration)</td>
                <td>7 jours</td>
                <td>En clair ; préférence</td>
            </tr>
        </tbody>
    </table>
</div>

<h2>Mesure d’audience</h2>
<p>Le site ne pratique aucune mesure d’audience.</p>

<h2>Vos droits</h2>
<p>[À FOURNIR]</p>
