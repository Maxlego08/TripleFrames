# Jalon 2 — décisions proposées au porteur (07/10)

> **Annexe archivée — remplacée par D66 du 07/10** (`questions-ouvertes.md` § « Décisions du 07/10/2026 »). Le porteur a répondu le 07/10 : toutes les recommandations ci-dessous sont retenues, **sauf n° 23**, où il retient le **rattachement automatique** (option « automatique au rendu ») au lieu du bandeau de confirmation ; n° 10 = A ; n° 24, n° 27 et n° 28 confirmés. Ce fichier ne fait plus foi que pour la **numérotation** « n° 1 » à « n° 51 » que citent les specs ; jamais prioritaire sur une spec ni sur `questions-ouvertes.md`. La liste des gestes humains vit désormais dans `REPRISE.md` § « Gestes humains du J2 ».

Chaque question porte la recommandation retenue par défaut. Réponds « tout accepter », ou cite les numéros à changer avec ton choix.

## Questions, par impact structurel

**A. Schéma, dépendances, environnements**
1. **Comment adresser une `saved_config` dans les URL ?**
   - Options : `name_normalized` du compte, ou colonne `public_id char(12)` (`PublicId`, patron D63).
   - **Reco : `public_id`.**
2. **Quel format pour l'archive d'export ?**
   - Options : ZIP JSON + README + avatar ; JSON seul ; CSV.
   - **Reco : ZIP JSON + README localisé + avatar.** Cela impose ext-zip dans `composer.json`, la CI et le VPS.
3. **Que contient l'export ?**
   - Options : les parties seules ; toutes les données du compte ; tout, signalements reçus et journal admin compris.
   - **Reco : toutes les données du compte** (profil sans secrets, consentements, comptes liés, configurations, avatar, parties, bonnes et fausses réponses, signalements émis), **sans** les signalements reçus ni `admin_action`.
4. **Combien de temps garder l'identité d'un demandeur de retrait (`takedown_identity`) ?**
   - Options : 5 ans après la décision ; 3 ans après la réception ; 1 an ; périmètre non branché.
   - **Reco : 5 ans après la décision**, en constante `RetentionWindows`, à confirmer par le conseil.
5. **Quel catalogue pour la préproduction ?**
   - Options : démo en `APP_ENV=staging` ; petit import réel ; copie du catalogue de production.
   - **Reco : démo en staging.**
6. **Comment faire tourner les services de préproduction ?**
   - Options : unités système toujours actives et plafonnées ; unités utilisateur ; démarrage à la demande.
   - **Reco : unités système plafonnées**, un seul geste root.
7. **Quel outil pour les parcours de bout en bout ?**
   - Options : `@playwright/test` autonome contre la préproduction ; plugin navigateur de Pest ; Vitest navigateur.
   - **Reco : Playwright autonome contre la préproduction.**
8. **Que fait le geste admin « bannir un pseudo » ?**
   - Options : masquage définitif + forme ajoutée à `NicknameBlocklist` au déploiement suivant ; retirer le geste ; table de pseudos interdits.
   - **Reco : masquage définitif + liste noire versionnée.**
9. **Comment l'état « site fermé » est-il stocké ?** Pas de question posée dans les briefs, défaut de rédacteur à ratifier.
   - **Reco : dérivé de la dernière ligne `site.closed`/`site.reopened` du journal, mis en cache.** Aucune table.

**B. Sécurité et accès**
10. **Pour `admin.2fa`, une passkey remplace-t-elle le TOTP chez un curateur ou un admin ?**
    - Options :
      - A : TOTP obligatoire, la passkey ne fait que sauter le challenge ;
      - B : TOTP ou au moins une passkey, connexion par mot de passe refusée sans TOTP ;
      - C : TOTP ou session ouverte par passkey.
    - **Reco : A.**
11. **Le lien de téléchargement de l'export exige-t-il une session en plus de la signature ?**
    - Options : signature + session du propriétaire ; signature seule.
    - **Reco : signature + session du propriétaire.**
12. **Que suspend un refus (ou une attente) des nouvelles CGU ?**
    - Options : toutes les pages de compte sauf déconnexion, export et suppression ; tout sauf la déconnexion ; les seules écritures.
    - **Reco : toutes les pages de compte sauf déconnexion, export et suppression.** Le jeu n'est jamais bloqué.
13. **Le back-office est-il bloqué par des CGU non acceptées ?**
    - **Reco : non, il est exempté.**
14. **Un admin peut-il anonymiser le compte d'un tiers ?**
    - Options : non ; geste du back-office avec motif ; commande artisan seule.
    - **Reco : geste du back-office réservé à l'admin**, motif obligatoire, journalisé `user.anonymized`, garde du dernier admin.
15. **Quelle garde anti-automate sur le formulaire public de retrait ?**
    - Options : pot de miel + délai signé ; preuve de travail auto-hébergée ; question textuelle ; throttle seul.
    - **Reco : pot de miel + délai signé.**
16. **Faut-il rendre le limiteur `answer` atomique (E105-6) ?**
    - **Reco : oui, livré avec L70-13.**
17. **Quel code HTTP pour la page de fermeture ?**
    - Options : 503 sans Retry-After ; 503 avec Retry-After ; 410 ; 200.
    - **Reco : 503 sans Retry-After + noindex.**
18. **Le back-office reste-t-il ouvert pendant la fermeture ?**
    - **Reco : oui, pour les admins.**
19. **Une page légale encore provisoire peut-elle être indexée quand `SITE_INDEXABLE=true` ?**
    - **Reco : non, garde de code.**

**C. Règles produit**
20. **Quelle règle pour `users.name` (pseudo persistant) ?**
    - Options : règle de pseudo de jeu à l'inscription, à la finalisation OAuth et au profil ; règle de profil du starter.
    - **Reco : règle de pseudo de jeu partout.** Les noms non conformes déjà en base ne sont pas migrés, ils ne sont simplement pas préremplis.
21. **Un pseudo tapé au siège réécrit-il `users.name` ?**
    - **Reco : jamais.** `users.name` ne change que dans Réglages › Profil.
22. **Le préremplissage du pseudo s'applique-t-il aux curateurs et admins ?**
    - **Reco : oui, depuis `users.name`**, jamais `real_name` (amender `40` § 8.4.2).
23. **Le rattachement d'un siège invité est-il automatique ou confirmé ?**
    - Options : automatique au rendu ; bandeau de confirmation en un clic ; automatique si même session navigateur.
    - **Reco : bandeau de confirmation en un clic.**
24. **Quelle portée pour un rattachement ?**
    - Options : le seul siège de la page ; tous les sièges du jeton ; tous sauf solo.
    - **Reco : le seul siège de la page, solo compris.**
25. **L'avatar bascule-t-il sur l'image du compte après rattachement ?**
    - Options : au retour au lobby ; jamais, seulement proposé ; immédiatement en lobby, sinon au retour au lobby.
    - **Reco : immédiatement si le salon est en lobby, sinon au retour au lobby** (règle D55). Le pseudo ne change jamais.
26. **Le joueur masqué est-il prévenu ?**
    - Options : avis privé discret ; rien, il voit son pseudo ; libellé neutre sans explication.
    - **Reco : avis privé discret, sans e-mail.**
27. **Que devient la suppression d'un compte qui a une partie en cours ?**
    - Options : refus ; anonymisation immédiate ; anonymisation différée du siège.
    - **Reco : refus tant que la partie est en cours.**
28. **Dormance : 24 mois + 30 jours ?**
    - Options : confirmer ; 12 mois + 30 jours ; 36 mois + 30 jours.
    - **Reco : confirmer 24 mois + 30 jours.**
29. **Une partie sans score compte-t-elle dans le meilleur score ?**
    - Options : exclue du seul meilleur score, « — » si le maximum vaut 0 ; incluse ; exclue de tout.
    - **Reco : exclue du meilleur score seulement**, comptée dans les trois autres compteurs.
30. **Où afficher le détail des films d'une partie (surface Letterboxd) ?**
    - Options : page `history.show` ; ligne dépliable ; titres et lien seuls.
    - **Reco : page `history.show`**, avec trouvé ou non, points et Letterboxd.
31. **Une partie à 0 manche jouée apparaît-elle dans « Mes parties » ?**
    - **Reco : non, masquée.**
32. **Comment l'export est-il notifié ?**
    - **Reco : e-mail si l'adresse est vérifiée, plus l'état à l'écran dans tous les cas.**
33. **Comment un invité exerce-t-il son droit d'accès ?**
    - Options : e-mail de contact + commande admin ; bouton « exporter ce siège » ; explication art. 11 seule.
    - **Reco : e-mail de contact + commande admin**, avec l'explication de l'art. 11 sur la page de confidentialité.
34. **Que deviennent les signalements de contenu ouverts quand on suspend depuis la file ?**
    - Options : clos avec deux issues nouvelles ; laissés ouverts avec badge ; clos en `already_handled`.
    - **Reco : clos en `resolved`**, issues `movie_suspended` et `frame_suspended`.
35. **Faut-il encore livrer le crochet `RoundAnswersClosed` ?**
    - **Reco : le retirer** (amender `60` § 9.4 et `70` L70-12).
36. **Faut-il bloquer le ré-ajout des octets d'une image retirée ?**
    - Options : tout le catalogue ; même film ; rien.
    - **Reco : bloquer sur tout le catalogue** (`source_hash` ou `tmdb_file_path`).
37. **Une décision `rejected` ou `out_of_scope` lève-t-elle la suspension conservatoire liée ?**
    - **Reco : non, aucune levée automatique**, rappel et lien sur la fiche.
38. **Comment traduire la « seconde adresse d'administration » ?**
    - Options : alerte interne à chaque demande ; Bcc des e-mails au demandeur ; rien.
    - **Reco : alerte interne en français** à `LEGAL_CONTACT_EMAIL` et à une seconde adresse (référence + lien, sans le corps).
39. **Faut-il prévenir les comptes par e-mail d'un changement de CGU ?**
    - **Reco : non, interstitiel seul en v1.**
40. **Quelles bornes pour la cadence de réponse ?**
    - Options : garder 1-5/s et 5-50 ; 2/s et 30 ; garder avec avertissement ; ne pas exposer.
    - **Reco : 2/s et 30 par manche.**
41. **Quel seuil K pour la sonde de force brute ?**
    - **Reco : K = 5.**
42. **Quelle borne basse pour `maxAnswerLength` ?**
    - Options : minimum fixe relevé ; avertissement ; plancher dynamique.
    - **Reco : minimum fixe d'environ 60.**
43. **Quel seuil pour le sélecteur de thèmes (`theme_selector_min_pool`) ?**
    - Options : garder 150 ; environ 60 ; 0.
    - **Reco : environ 60 par configuration de production**, le défaut du code restant 150.
44. **L'Avancé avertit-il quand un barème fait « payer l'attente » (`waitingPays`) ?**
    - **Reco : oui, avertissement non bloquant.**
45. **Que deviennent les parties en cours à `site:close` ?**
    - Options : coupure immédiate ; entrées et lancements fermés, parties jusqu'au podium ; drainage exigé.
    - **Reco : entrées et lancements fermés, les parties en cours vont jusqu'au podium.**
46. **Faut-il un indice « titres originaux » sur le QCM ?**
    - **Reco : une légende unique au-dessus des quatre propositions.**
47. **Où mettre les balises Open Graph ?**
    - **Reco : balises génériques sur toutes les pages**, sans `room_code` ni titre.
48. **Où mettre la balise canonique ?**
    - **Reco : sur les quatre routes indexables seulement** (`05` corrigé).
49. **Quelle durée publier pour l'effacement d'un siège solo (E122-4) ?**
    - **Reco : « au plus 48 h ».**
50. **Que faire des icônes héritées du starter (E7-11) ?**
    - **Reco : les remplacer par une icône propre au projet.**
51. **Quel seuil de fraîcheur pour la sonde `stale_lobby` ?**
    - **Reco : 1 h.**

## Ce qui ne peut pas être codé (gestes humains)

**Production (`.env`, VPS, Plesk)**
- **Variables à poser au bon moment** :
  - `ACCOUNTS_REGISTRATION_OPEN=true` ;
  - `ACCOUNTS_PASSKEYS_ENABLED=true`, avec `PASSKEYS_RP_ID` et `PASSKEYS_ALLOWED_ORIGINS` si l'`APP_URL` n'est pas l'apex `<DOMAINE>` ;
  - `SITE_INDEXABLE=true` ;
  - `theme_selector_min_pool` ;
  - `MAIL_*` et `LEGAL_CONTACT_EMAIL`.
- **Point de non-retour** : la première passkey enregistrée en production.
- **Mail** : choisir et contractualiser le SMTP transactionnel UE. Sans lui, ni accusés, ni décisions, ni export, ni rappel de dormance ne partent.
- **Système sur le VPS** :
  - vérifier ext-zip sur PHP 8.3 FPM et CLI ;
  - créer `EXPORTS_DISK_ROOT` hors du chemin de déploiement ;
  - décider s'il est exclu des sauvegardes (recommandé : oui).
- **Préproduction** :
  - abonnement Plesk `preprod.<DOMAINE>` (DNS, Let's Encrypt, base séparée) ;
  - un geste root pour Redis et les unités systemd ;
  - authentification HTTP ;
  - Plesk Git branché sur `deploy-preprod`.
- **Promotions** : navigateurs Playwright installés sur le poste, trois parcours joués avant chaque promotion, puis `promote` et le pull manuel de production.
- **Sondes** : configurer chez le prestataire de supervision `takedown-ack` et la purge étendue.
- **Commandes manuelles** :
  - `catalog:themes`, une fois après L30-10 (il commence par un instantané) ;
  - `takedown:reconcile`, après toute restauration ;
  - `site:close`, geste console.
- **Restauration** : restauration complète chronométrée sur le VPS (clé APP_KEY et clé privée age hors ligne), temps à consigner dans `100` § 13.6.
- **Base de test locale** : base MySQL de test sur Homestead pour la preuve `locks-timing`.

**Juridique et conseil**
- Textes opposables en français : mentions légales, CGU, confidentialité, procédure de signalement.
- Sous-traitants UE nommés et registre des traitements.
- Durée de `takedown_identity`.
- Modèle de notification de violation.
- Relecture de la bannière de consentement et des engagements (72 h, 7 jours ouvrés, fermeture « sans délai »).
- Dépôt du logo TMDB officiel (`public/brand/tmdb.svg`) avec ses conditions.
- Passage de `terms_version` à la version définitive dans le commit qui publie le texte.

**Organisation**
- Nommer au moins deux admins.
- Ouvrir la seconde adresse d'administration.
- Désigner la personne de confiance.
- Sceller l'inventaire des accès hors dépôt.

**Données et relectures**
- **L70-13** : des parties réelles et le test de charge sont nécessaires, puis la lecture des rapports `answers:collisions` et `ReportBruteForce` avec l'IA, avant d'arrêter les bornes.
- **Contenu** :
  - publier les thèmes un à un ;
  - publier une grille marquée rétroactive, sans laquelle le geste de L20-25 répond « indisponible » ;
  - promouvoir un second curateur pour exercer le limiteur partagé et la réservation souple.
- **Relectures FR/EN** :
  - libellés des compteurs et explication du taux ;
  - e-mails (export, dormance, retrait) ;
  - encart de compte au podium ;
  - page de fermeture ;
  - Open Graph.
- **Tests manuels** : à 360 px sur un vrai téléphone, écrans de compte réécrits, bouton de signalement et libellé masqué.
- **Premier admin** :
  - accepter les CGU à sa prochaine connexion ;
  - confirmer que son TOTP est enrôlé ;
  - n'enregistrer sa passkey qu'après l'ouverture des passkeys en production.
- **`dev.<DOMAINE>` (facultatif)** : enregistrement DNS vers 127.0.0.1, certificat DNS-01, redirect URIs Discord et Google.