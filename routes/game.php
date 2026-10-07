<?php

use App\Http\Controllers\Game\AnswerController;
use App\Http\Controllers\Game\ChoiceController;
use App\Http\Controllers\Game\ClockController;
use App\Http\Controllers\Game\ContentReportController;
use App\Http\Controllers\Game\FrameServeController;
use App\Http\Controllers\Game\NextRoundController;
use App\Http\Controllers\Game\RoomHeartbeatController;
use App\Http\Controllers\Game\RoomStateController;
use App\Http\Controllers\Game\SoloGameController;
use App\Http\Controllers\Game\SoloHeartbeatController;
use App\Http\Controllers\Game\SoloRoundController;
use App\Http\Controllers\Game\SoloStateController;
use App\Http\Controllers\Room\AvatarReportController;
use App\Http\Controllers\Room\HostTransferController;
use App\Http\Controllers\Room\KickController;
use App\Http\Controllers\Room\LaunchController;
use App\Http\Controllers\Room\LeaveRoomController;
use App\Http\Controllers\Room\ReplayController;
use App\Http\Controllers\Room\RoomController;
use App\Http\Controllers\Room\RoomEntryController;
use App\Http\Controllers\Room\RoomPresetController;
use App\Http\Controllers\Room\RoomSettingsController;
use App\Http\Controllers\Room\SeatAvatarController;
use App\Http\Middleware\VaryOnLanguage;
use App\Support\Room\RoomCode;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Moteur de partie et salon — spec 60 § 10.1, contrat C7 § 2.4 ; spec 50
| § 21, contrats C0 et C6
|--------------------------------------------------------------------------
|
| Routes du moteur et du salon, `require`-é par `routes/web.php` : création,
| page et entrée du salon, resynchronisation, battements, gestes d'hôte,
| réglages, solo, horloge et service d'image. Seuls les NOMS font contrat ;
| les URL partent au client par Wayfinder, sauf celles des images, produites
| par le serveur (§ 7.5).
|
| Limiteurs (`FortifyServiceProvider::configureRateLimiting()`, § 10.3 ;
| spec 50 § 17.3) : `game-read` sur les lectures, `game-write` sur les
| écritures, `frame-serve` sur les octets d'image, clés sur le hash du
| `player_token`, repli sur l'IP ; `room-create` (par adresse) et `room-join`
| (par jeton, repli sur l'IP) sur les deux gestes qui prennent un siège ;
| `answer` (spec 70 § 8, par siège résolu par `seat.active`) sur la saisie.
|
| Pile SANS SESSION de `clock.show` et `frame.serve` (contrat C8 § 2) : ni
| session, ni `Set-Cookie`. `PreventRequestForgery` lirait la session pour
| poser `XSRF-TOKEN` sur un GET ; `AddQueuedCookiesToResponse` émettrait le
| cookie `locale` que `SetLocale` met en file à la négociation. `EncryptCookies`
| reste, pour lire le `player_token` du limiteur. Ces deux routes n'ont aucun
| domaine de traduction : elles ne rendent aucune page (exclues nommément par
| `TranslationDomainDeclarationTest`).
|
*/

// Motif TOLÉRANT de `{room}` (spec 50 § 6.3), posé AVANT toute route qui
// porte `{room}` : `Route::pattern()` ne s'applique qu'aux routes déclarées
// après lui. Casse, espaces et tirets d'un lien saisi à la main passent le
// routeur ; `Room::resolveRouteBinding()` normalise, contrôle la forme et
// répond 404 sans requête à un code mal formé. Un motif strict tiré de
// l'alphabet renverrait 404 avant la liaison, contre `RoomCode::normalize()`.
Route::pattern('room', RoomCode::ROUTE_PATTERN);

// Création, page du salon et entrée (spec 50 § 6.2, § 7.2 et § 21). Un GET ne
// frappe jamais de `player_token` (contrat C4 I4.1) : seuls `room.store` et
// `room.join` en frappent un, une fois tous leurs refus écartés.
//
// `room.create` est déclarée AVANT `room.show` : `new` ne peut pas être un
// code, que le motif de `{room}` refuse d'ailleurs (trois signes).
// `room.show` rend la page `game/lobby` et redirige vers `room.entry` le
// visiteur sans siège plutôt que de lui rendre un formulaire d'entrée.
Route::get('r/new', [RoomController::class, 'create'])
    ->name('room.create')
    ->middleware(['translations:room,legal', 'throttle:game-read']);

Route::post('r', [RoomController::class, 'store'])
    ->name('room.store')
    ->middleware('throttle:room-create');

Route::get('r/{room}', [RoomController::class, 'show'])
    ->name('room.show')
    ->middleware(['translations:game,room,legal', 'throttle:game-read']);

Route::get('r/{room}/join', [RoomEntryController::class, 'show'])
    ->name('room.entry')
    ->middleware(['translations:room,legal', 'throttle:game-read']);

Route::post('r/{room}/join', [RoomEntryController::class, 'store'])
    ->name('room.join')
    ->middleware('throttle:room-join');

// Mode solo (spec 60 § 10.1 et § 16 ; écart (l) du § 22 bis). La page
// d'entrée `room/solo` est distincte, comme `room.entry` à côté de
// `room.show` ; `solo.show` rend `game/solo` et redirige vers `solo.create` le visiteur sans siège solo
// (§ 16.4). Un GET ne frappe jamais de jeton : seul `solo.store` en frappe
// un, une fois les refus de drainage et de vivier écartés (C4 I4.1).
Route::get('solo/new', [SoloGameController::class, 'create'])
    ->name('solo.create')
    ->middleware(['translations:room,legal', 'throttle:game-read']);

Route::post('solo', [SoloGameController::class, 'store'])
    ->name('solo.store')
    ->middleware('throttle:game-write');

Route::get('solo', [SoloGameController::class, 'show'])
    ->name('solo.show')
    ->middleware(['translations:game,room,legal', 'throttle:game-read']);

// Sondage de la partie solo (§ 12 et § 16.4) : le paquet `GameStatePacket`,
// JSON à destinataire unique, rattrapage compris — le solo ne reçoit aucun
// événement. Une LECTURE, comme `room.state` : ni `seat.active` (l'onglet
// supplanté y lit `seatActive: false`), ni frappe de jeton. 403 sans siège
// solo tenu par le jeton.
Route::get('solo/state', [SoloStateController::class, 'show'])
    ->name('solo.state')
    ->middleware(['translations:game,room,legal', 'throttle:game-read']);

// Battement de présence du siège solo (§ 13.1, § 13.3) : même transaction
// que celui du salon, sans salon ; un siège solo ne passe jamais `left`.
// 204, ou 403 sans siège solo. Ni `seat.active`, ni domaine de traduction.
Route::post('solo/heartbeat', [SoloHeartbeatController::class, 'store'])
    ->name('solo.heartbeat')
    ->middleware('throttle:game-write');

// Gestes du joueur solo (§ 16.5, D18 du 23/09) : « Voir la réponse »,
// « Passer la manche » et « Manche suivante ». `seat.active` résout le siège
// SOLO du jeton (aucun `{room}` dans le chemin) et sa partie solo en cours :
// 403 sans siège solo, 409 `seat_superseded` pour un onglet supplanté. Chaque
// geste vaut battement et répond par le paquet à jour ; hors précondition,
// 409 `round_not_running` (révéler, passer) ou `not_revealing` (manche
// suivante), codes que le client traduit. Aucun domaine de traduction.
Route::middleware(['seat.active', 'throttle:game-write'])->group(function (): void {
    Route::post('solo/round/reveal', [SoloRoundController::class, 'reveal'])
        ->name('solo.reveal');

    Route::post('solo/round/skip', [SoloRoundController::class, 'skip'])
        ->name('solo.skip');

    Route::post('solo/round/next', [SoloRoundController::class, 'next'])
        ->name('solo.next');
});

Route::get('clock', [ClockController::class, 'show'])
    ->name('clock.show')
    ->middleware('throttle:game-read')
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        AddQueuedCookiesToResponse::class,
    ]);

// Service d'image d'un palier (§ 7.3, contrat C8 § 2), adressé par le
// `serve_token` de la manche, jamais par un chemin. URL signée RELATIVE
// produite par le serveur (`ServeUrl`), jamais reconstruite par Wayfinder :
// 403 sur une signature invalide ou expirée, 429 au-delà du débit, 404
// uniforme sinon — jeton inconnu, prédicat de service (`ServeGuard` :
// catalogue, temps, appartenance du demandeur) faux ou fichier absent. En
// lecture seule : aucune transition, aucun rattrapage, aucun `seen_frame`.
Route::get('f/{serveToken}', [FrameServeController::class, 'show'])
    ->name('frame.serve')
    ->where('serveToken', '[0-9a-f]{32}')
    ->middleware(['signed:relative', 'throttle:frame-serve'])
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        AddQueuedCookiesToResponse::class,
    ]);

// Resynchronisation d'un siège de salon (§ 12) : le paquet `GameStatePacket`,
// JSON à destinataire unique. Une LECTURE : ni `seat.active` — l'onglet
// supplanté doit pouvoir y lire `seatActive: false` (§ 12.7) —, ni frappe de
// jeton d'onglet. `translations:game,room,legal` comme toute route GET joueur
// du moteur (contrat C15 § 2.3).
Route::get('r/{room}/state', [RoomStateController::class, 'show'])
    ->name('room.state')
    ->middleware(['translations:game,room,legal', 'throttle:game-read']);

// Battement de présence d'un siège de salon (§ 13.1) : écrit `last_seen_at`,
// ramène un siège déconnecté ou parti (non expulsé) à `connected`, nourrit
// `room.last_activity_at`, reprend une partie en pause et arme le balayage
// de présence. Ni `seat.active` — la présence est celle du SIÈGE ; c'est le
// client qui cesse de battre dans un onglet supplanté (§ 12.7) —, ni domaine
// de traduction : 204, ou 403 sans siège tenu par le jeton, siège expulsé ou
// salon archivé.
Route::post('r/{room}/heartbeat', [RoomHeartbeatController::class, 'store'])
    ->name('room.heartbeat')
    ->middleware('throttle:game-write');

// Gestes de l'hôte (spec 50 § 3, § 5.3, § 11, § 12, § 13, § 17.1, § 21 ;
// contrats C0 et C6) : l'onglet Simple, l'application d'un preset, le
// lancement, « Rejouer », l'expulsion et le transfert du rôle ; et le
// départ, geste de tout joueur ; en partie, « manche suivante » (spec 60
// § 5.4). Toute écriture du salon passe par `seat.active` (contrat C7 : 403
// sans siège tenu par le jeton, 409 `seat_superseded` pour un onglet
// supplanté), puis par `throttle:game-write`, dans cet ordre et avant la
// liaison de `{room}` (liste de priorité de `bootstrap/app.php`). Aucun
// domaine de traduction : ces routes ne rendent aucune page — elles
// redirigent vers le lobby (le départ, vers l'accueil), refus et échec
// technique rendus dans la langue de la requête ; « manche suivante »
// répond en JSON, par un code que le client traduit.
Route::middleware(['seat.active', 'throttle:game-write'])->group(function (): void {
    Route::patch('r/{room}/settings', [RoomSettingsController::class, 'update'])
        ->name('room.settings.update');

    Route::post('r/{room}/settings/preset', [RoomPresetController::class, 'store'])
        ->name('room.settings.preset');

    Route::post('r/{room}/launch', [LaunchController::class, 'store'])
        ->name('room.launch');

    // « Rejouer » (§ 13) : sur le podium, l'hôte ramène le salon au lobby ;
    // le lancement suivant repasse par la garde de vivier.
    Route::post('r/{room}/replay', [ReplayController::class, 'store'])
        ->name('room.replay');

    // Pouvoirs de l'hôte (§ 11.3, § 11.4) et départ de tout joueur.
    // `{target}` est le `public_id` du siège VISÉ, jamais `{player}` :
    // `seat.active` lit `{player}` comme le siège du demandeur, que le jeton
    // courant doit tenir — celui de l'hôte ne tient jamais la cible.
    Route::post('r/{room}/players/{target}/kick', [KickController::class, 'store'])
        ->name('room.players.kick');

    // Signalement de l'avatar téléversé d'un autre siège (spec 40 § 11.6,
    // D49 du 01/10) : tout siège actif, aucune autorité d'hôte.
    Route::post('r/{room}/players/{target}/report-avatar', [AvatarReportController::class, 'store'])
        ->name('room.players.report_avatar');

    Route::post('r/{room}/host', [HostTransferController::class, 'store'])
        ->name('room.host.transfer');

    Route::post('r/{room}/leave', [LeaveRoomController::class, 'store'])
        ->name('room.leave');

    // Avatar du siège demandeur (D55 du 02/10), au lobby seulement : avant
    // le lancement et après « Rejouer », jamais en partie ni au podium.
    Route::post('r/{room}/avatar', [SeatAvatarController::class, 'update'])
        ->name('room.avatar.update');

    // « Manche suivante » (spec 60 § 5.4), le seul pouvoir de l'hôte en
    // partie : raccourcit la révélation, jamais une manche. JSON à
    // destinataire unique — 204 ; 409 `not_revealing` hors révélation ; 403
    // pour un siège qui n'est pas l'hôte, relu sous le verrou du salon.
    Route::post('r/{room}/round/next', [NextRoundController::class, 'store'])
        ->name('room.round.next');
});

// Soumission d'une réponse en texte libre (spec 70 § 7.1, contrat C10 § 2) :
// le siège est adressé par son `public_id`, même route en salon et en solo,
// et le `public_id` ne donne aucun droit — `seat.active` exige que le jeton
// courant tienne ce siège et que `X-Seat-Token` soit l'onglet actif, puis met
// en mémoire le siège et sa partie courante, AVANT la liaison implicite. JSON
// à destinataire unique, aucune page rendue : aucun domaine de traduction.
//
// `throttle:answer` (70 § 8) est clé sur le siège que `seat.active` a résolu,
// jamais sur l'IP ni sur le `public_id` : l'ordre écrit ici n'est pas l'ordre
// d'exécution, que fixe la liste de priorité de `bootstrap/app.php` —
// `SetLocale`, `seat.active`, `throttle:answer`, puis la liaison. Un tiers
// reçoit 403 sans consommer le budget du siège ; un 429 sort dans la langue
// de la requête et n'est ni évalué ni compté.
Route::post('seat/{player:public_id}/answer', [AnswerController::class, 'store'])
    ->name('round.answer.store')
    ->middleware(['seat.active', 'throttle:answer']);

// Clic d'une proposition du QCM (spec 70 § 7.6, contrat C10 § 2) : même
// adressage, même pile et même limiteur que la saisie en texte libre, sur le
// budget `choice` du siège, distinct du budget `text`. `choice` est l'une des
// quatre chaînes reçues, renvoyée telle quelle — jamais un index —, jugée
// par égalité stricte avec la cible sans jamais lire `answer_key`. JSON à
// destinataire unique, aucun domaine de traduction.
Route::post('seat/{player:public_id}/choice', [ChoiceController::class, 'store'])
    ->name('round.choice.store')
    ->middleware(['seat.active', 'throttle:answer']);

// Signaler un film ou une image vue en jeu (D63 du 07/10, spec 90 § 4.5 bis),
// depuis la révélation ou le podium : `/report?movie=<tmdb_id>&frame=<public_id>`,
// aucun identifiant interne. Page publique, coquille `PublicLayout`, domaine
// `game` (`legal` comme toute route joueur), `noindex` permanent : la route ne
// porte JAMAIS `RobotsDirectives::ROUTE_FLAG`. L'envoi exige un compte ou un
// siège (403 sinon) et passe par le limiteur `content-report`. Distincte de
// « signaler un contenu » (`takedown.create`, `/report-content`), la voie des
// ayants droit, vers laquelle la page renvoie.
Route::middleware('translations:game,legal')->group(function (): void {
    Route::get('report', [ContentReportController::class, 'create'])
        ->name('content-report.create')
        ->defaults(VaryOnLanguage::ROUTE_FLAG, true);

    Route::post('report', [ContentReportController::class, 'store'])
        ->name('content-report.store')
        ->middleware('throttle:content-report');
});
