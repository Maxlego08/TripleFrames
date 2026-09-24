{{--
    Mentions légales — corps français, squelette du jalon 1 (spec 90 § 4.4).

    Rendu par `LegalPageController` avec `view()->file()`, transmis en prop
    `body` et injecté par `legal/show` dans un `<div lang="fr">`. Aucune classe
    ici : la mise en forme vient des sélecteurs descendants du conteneur, pour
    qu'un re-skin ne touche jamais ces textes. Le titre, le bandeau provisoire
    et le bloc de contact sont l'habillage traduit (domaine `legal`).

    Règle d'écriture du jalon 1 : uniquement des faits déjà vrais, et un
    marqueur visible `[À FOURNIR : …]` partout ailleurs. Interdits sans
    exception : toute qualification du service de « non commercial », tout nom
    de sous-traitant, tout délai d'engagement.
--}}
<h2>Éditeur du site</h2>
<p>Le site est édité par une personne physique : [À FOURNIR : nom].</p>
<p>Contact : voir le bloc « Contact » en bas de cette page. L’adresse postale de l’éditeur est communiquée sur demande.</p>

<h2>Directeur de la publication</h2>
<p>[À FOURNIR]</p>

<h2>Hébergeur</h2>
<p>[À FOURNIR : relevé du VPS]</p>

<h2>Propriété intellectuelle</h2>
<p>Les images présentées dans le jeu sont des photogrammes de films et de dessins animés. Ce sont des œuvres protégées : elles restent la propriété de leurs ayants droit. TMDB (The Movie Database) n’en est pas titulaire.</p>
<p>Un ayant droit qui souhaite le retrait d’une image ou d’un film peut consulter la page <a href="{{ route('takedown.create', absolute: false) }}">Signaler un contenu</a>.</p>

<h2>Données sur les films</h2>
<p>Ce produit utilise l’API de TMDB mais n’est ni approuvé ni certifié par TMDB.</p>
