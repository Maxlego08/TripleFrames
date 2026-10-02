<?php

namespace App\Http\Controllers\Room;

use App\Actions\Game\ClaimSeatTab;
use App\Actions\Room\CreateRoom;
use App\Actions\Room\TransferHost;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Room\Concerns\PresentsSeatForm;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Requests\Room\StoreRoomRequest;
use App\Models\Room;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettingsBounds;
use App\Settings\RoomSettingsEditor;
use App\Support\Game\CurrentGame;
use App\Support\Game\GameStateBuilder;
use App\Support\Identity\PlayerTokenManager;
use App\Support\Room\LobbyAvatars;
use App\Support\Room\RoomSettingsPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le salon : création et page (spec 50 § 6 et § 7.2 ; 21).
 *
 * - `room.create`, `GET /r/new` : le formulaire de pseudo de l'hôte
 *   (`room/create`, `PublicLayout`), sans avatar : la
 *   prise de siège l'attribue (D55 du 02/10). **Un GET ne frappe jamais de
 *   jeton** (C4 I4.1).
 * - `room.store`, `POST /r` : crée le salon aux réglages par défaut, prend le
 *   siège du créateur, le nomme hôte ({@see CreateRoom}), puis 303 vers la
 *   page du salon — les réglages se font dans le lobby (§ 6.1).
 * - `room.show`, `GET /r/{room}` : la page UNIQUE du salon, du lobby au
 *   podium — voir {@see self::show()}.
 */
class RoomController extends Controller
{
    use PresentsSeatForm;

    public function create(): InertiaResponse
    {
        return Inertia::render('room/create', [
            'nickname' => $this->nicknameProps(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(StoreRoomRequest $request, CreateRoom $create): RedirectResponse
    {
        $room = $create->handle(
            $request,
            $request->nickname(),
            $this->effectiveLocale(),
            $request->user(),
        );

        return to_route('room.show', $room, Response::HTTP_SEE_OTHER);
    }

    /**
     * La page du salon rend, dans cet ordre (§ 7.2) :
     *
     * 1. un salon archivé → `game/room-expired`, statut **410**, sans aucune
     *    prop : aucune autre information sur le salon (§ 16.3). Le lien d'un
     *    salon archivé n'est jamais une 404 ; après recyclage de son code, il
     *    mène au salon actif qui le porte (§ 6.3) ;
     * 2. aucun siège non expulsé pour ce jeton (`seatIn()`) → 303 vers la
     *    page d'entrée publique `room.entry` ;
     * 3. sinon : la réparation d'hôte (§ 11.1 : une lecture qui ne trouve pas
     *    de cible valide déclenche un transfert, jamais une erreur), puis
     *    `ClaimSeatTab` (le second onglet prend la main), puis **la page
     *    `game/lobby`, dans tout statut non archivé** — `lobby` comme
     *    `playing`, podium compris (§ 8.1).
     *
     * Le salon multijoueur est UNE page, du lobby au podium (90 § 2.1) :
     * manche, révélation, podium et retour au lobby après « Rejouer » sont des
     * états de cette page, tirés de `state` et du magasin de `60`. Une visite
     * entre deux états démonterait la souscription, l'horloge et l'annonceur,
     * et ferait frapper un nouveau jeton d'onglet.
     *
     * Props (`LobbyPageProps`, § 8.1) :
     * - `state` : le paquet de 60 construit sur le jeton d'onglet rendu, sur
     *   la partie que décrit `room.state` ({@see CurrentGame::forState()} :
     *   la partie en cours du siège, à défaut la dernière partie du salon tant
     *   qu'il est `playing`, NULL au lobby) ;
     * - `seatToken` : le jeton d'onglet (60 § 12.7), jamais dans `state` ;
     * - `settings` et `presets` : l'état des réglages et le grisage des
     *   presets, **recalculés à chaque rendu**, rechargement partiel compris —
     *   le vivier n'est jamais stocké (§ 8.1, § 9.1) ;
     * - `bounds`, `limits`, `launch`, `editor` : bornes par `N`, limites de
     *   plateforme, seuil de lancement et disponibilité des éditeurs ;
     * - `themes` et `configs` : `null` au J1 (sélecteur de thèmes et
     *   configurations sauvegardées, J2) ;
     * - `avatars` : le sélecteur d'avatar du siège ({@see LobbyAvatars},
     *   D55 du 02/10), fermeture rechargeable (`only: ['avatars']`).
     *
     * `state`, `settings` et `presets` sont des fermetures : un rechargement
     * partiel (`only: ['settings', 'presets']`, § 8.2) ne reconstruit ni le
     * paquet ni ce qu'il ne demande pas. `ClaimSeatTab`, lui, s'exécute à
     * chaque requête : sous l'en-tête `X-Seat-Token` de l'onglet actif, il ne
     * frappe rien.
     *
     * Toute URL de salon reste `noindex`, et le code n'entre jamais dans un
     * titre (§ 6.5) : il ne voyage qu'en prop `room.code`, pour le partage.
     * Aucune prop ne porte un identifiant interne de salon, de siège ou de
     * thème : les sièges voyagent par `public_id`, les thèmes par clé.
     */
    public function show(Request $request, Room $room, PlayerTokenManager $tokens, ClaimSeatTab $claim, TransferHost $transfer): InertiaResponse|Response
    {
        if ($room->status === RoomStatus::Archived) {
            return Inertia::render('game/room-expired')
                ->toResponse($request)
                ->setStatusCode(Response::HTTP_GONE);
        }

        $seat = $tokens->seatIn($request, $room);

        if ($seat === null) {
            return to_route('room.entry', $room, Response::HTTP_SEE_OTHER);
        }

        $this->repairHost($room, $transfer);

        $seatToken = $claim->handle($seat, EnsureActiveSeat::presentedToken($request));

        // L'instant du paquet et du vivier, pris avant leurs lectures : un
        // événement émis après lui est postérieur au paquet, et le magasin
        // l'applique (règle d'idempotence de 60 § 11.8).
        $now = Date::now()->toImmutable();

        return Inertia::render('game/lobby', [
            'room' => ['code' => $room->room_code],
            'state' => static fn (): array => GameStateBuilder::build(CurrentGame::forState($seat), $seat, $now, $seatToken),
            'seatToken' => $seatToken,
            'settings' => static fn (): array => RoomSettingsPresenter::state($room, $now),
            'bounds' => RoomSettingsBounds::toClient(),
            'limits' => PlatformLimits::current()->toArray(),
            'presets' => static fn (): array => RoomSettingsPresenter::presets($room, $now),
            'launch' => ['minConnected' => RoomSettingsBounds::MIN_CONNECTED_PLAYERS_TO_LAUNCH],
            'editor' => $this->editorProps(),
            'themes' => null,
            'configs' => null,
            'avatars' => static fn (): array => LobbyAvatars::of($room, $seat, $request->user() instanceof User ? $request->user() : null),
        ]);
    }

    /**
     * Réparation d'hôte au rendu (§ 11.1, § 11.2) : si `host_player_id` ne
     * désigne aucun siège de ce salon ni parti ni expulsé, le rôle passe au
     * siège que désigne {@see TransferHost::automatic()}, sous le verrou du
     * salon, critère relu sous ce verrou — un autre geste a pu réparer entre
     * la lecture et le verrou. Un salon archivé entre-temps n'est pas touché
     * (l'archivage vide l'hôte, § 16.2). Le chemin courant, hôte valide, ne
     * prend aucun verrou.
     */
    private function repairHost(Room $room, TransferHost $transfer): void
    {
        if (TransferHost::hasValidHost($room)) {
            return;
        }

        DB::transaction(static function () use ($room, $transfer): void {
            $locked = Room::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === RoomStatus::Archived || TransferHost::hasValidHost($locked)) {
                return;
            }

            $transfer->automatic($locked, Date::now()->toImmutable());
        });
    }

    /**
     * Disponibilité des éditeurs du lobby (§ 8.1). Au J1 : onglet Avancé et
     * sélecteur de thèmes non livrés, interrupteur des retardataires livré
     * (D35 du 23/09, § 15.4). Le sélecteur de thèmes suivra
     * `PoolReporter::themeSelectorVisible()` au J2 (§ 9.5, L50-11) : c'est un
     * état de jalon, jamais une règle de jeu.
     *
     * @return array{advancedAvailable: bool, themeSelectorVisible: bool, lateJoinAvailable: bool}
     */
    private function editorProps(): array
    {
        return [
            'advancedAvailable' => RoomSettingsEditor::ADVANCED_TAB_AVAILABLE,
            'themeSelectorVisible' => false,
            'lateJoinAvailable' => true,
        ];
    }
}
