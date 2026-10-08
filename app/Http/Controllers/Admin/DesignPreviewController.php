<?php

namespace App\Http\Controllers\Admin;

use App\Avatars\AvatarPresetCatalog;
use App\Enums\ContentAvailability;
use App\Enums\ContentReportReason;
use App\Enums\ContentReportScope;
use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Room\Concerns\PresentsSeatForm;
use App\Http\Controllers\Room\RoomController;
use App\Models\ContentReport;
use App\Models\Movie;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Settings\RoomSettingsEditor;
use App\Support\Design\DesignScenarios;
use App\Support\Draw\PoolReporter;
use App\Support\Draw\PoolScope;
use App\Support\Game\SoloPresets;
use App\Support\Room\RoomSettingsPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le banc d'essai du design (spec 20 § 13.8, ligne 50 ; demande du porteur du
 * 08/10) : chaque page du site joueur, dans chacun de ses états, avec des
 * données de test, pour travailler le design sans monter une partie.
 *
 * **Administrateur seul, hors production** : les deux routes ne sont
 * enregistrées que hors `APP_ENV=production` (`routes/admin.php`), et
 * `UserPolicy::previewDesign` le redit. **Aucune écriture** : rien n'est
 * créé en base, ni salon, ni siège, ni partie.
 *
 * - {@see self::index()} — `admin.design.index` : l'écran, liste groupée des
 *   scénarios ({@see DesignScenarios}) et une `iframe` qui les affiche.
 * - {@see self::frame()} — `design.frame`, HORS du groupe `admin.*` : le
 *   groupe force le français et le seul domaine `admin`, alors qu'une page
 *   joueur a besoin de ses domaines et de la langue choisie. Rend le composant
 *   hôte de la coquille du scénario, qui monte la vraie page avec les
 *   données fictives de `resources/js/lib/design/` et les vraies props
 *   indépendantes d'un salon calculées ici par le vrai code serveur.
 */
final class DesignPreviewController extends Controller
{
    use PresentsSeatForm;

    /** Nombre maximal d'images réelles prêtées aux scénarios. */
    public const int MAX_IMAGES = 5;

    /** Paramètre de requête de la langue du scénario. */
    public const string LOCALE_PARAM = 'locale';

    public function index(): Response
    {
        return Inertia::render('admin/design/index', [
            'scenarios' => DesignScenarios::forIndex(),
            'groups' => DesignScenarios::groupsForIndex(),
        ]);
    }

    /**
     * Un scénario fictif, dans la langue `?locale=` (à défaut celle de la
     * requête). Props, toutes indépendantes d'un salon et sans aucune donnée
     * de joueur :
     *
     * - `scenario` : la clé, que l'hôte lit pour choisir la page et ses
     *   données fictives ;
     * - `images` : jusqu'à cinq URL d'images réelles du catalogue publié, par
     *   l'aperçu admin `admin.catalog.frames.game` (liste vide sans
     *   catalogue : le cadre dit alors « indisponible ») ;
     * - `bounds`, `limits`, `launch`, `editor` : exactement ce que reçoit le
     *   lobby (`RoomController::show`) ;
     * - `settings` : l'état des réglages PAR DÉFAUT, vivier compté sur le
     *   catalogue (aucun salon, donc aucune non-répétition ni mémoire) ;
     * - `presets` : les quatre presets sur le vivier catalogue, comme le solo ;
     * - `nickname` : les bornes du pseudo des formulaires de siège ;
     * - `avatars` : le catalogue des avatars prédéfinis ;
     * - `passwordRules` : la règle de mot de passe des écrans Fortify ;
     * - `report` : motifs, portées et longueur de commentaire de la page
     *   « Signaler ».
     */
    public function frame(Request $request, string $scenario, PoolReporter $reporter, SoloPresets $presets): Response
    {
        $host = DesignScenarios::host($scenario);

        abort_if($host === null, 404);

        $requested = $request->query(self::LOCALE_PARAM);
        $locale = is_string($requested) ? Locale::tryFrom($requested) : null;

        if ($locale !== null) {
            App::setLocale($locale->value);
        }

        $props = [
            'scenario' => $scenario,
            'images' => $this->images(),
            'bounds' => RoomSettingsBounds::toClient(),
            'limits' => PlatformLimits::current()->toArray(),
            'settings' => $this->defaultSettings($reporter),
            'presets' => $presets->options(),
            'launch' => ['minConnected' => RoomSettingsBounds::MIN_CONNECTED_PLAYERS_TO_LAUNCH],
            'editor' => RoomController::editorProps(),
            'nickname' => $this->nicknameProps(),
            'avatars' => AvatarPresetCatalog::options(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'report' => [
                'scopes' => array_map(static fn (ContentReportScope $scope): string => $scope->value, ContentReportScope::cases()),
                'reasons' => array_map(
                    static fn (ContentReportReason $reason): array => [
                        'value' => $reason->value,
                        'frameOnly' => $reason->targetsFrameOnly(),
                    ],
                    ContentReportReason::cases(),
                ),
                'commentMaxLength' => ContentReport::COMMENT_MAX_LENGTH,
            ],
        ];

        // Un appel littéral par hôte : la coquille se choisit sur le nom de
        // page (`app.tsx`), et la preuve de rendu des pages `game/*` lit ces
        // noms dans le source (`ShellTest`).
        return match ($host) {
            'auth/design-preview' => Inertia::render('auth/design-preview', $props),
            'legal/design-preview' => Inertia::render('legal/design-preview', $props),
            default => Inertia::render('game/design-preview', $props),
        };
    }

    /**
     * Jusqu'à {@see self::MAX_IMAGES} images publiées et rendues d'UN film
     * publié — le premier qui en a —, du niveau le plus cryptique au plus
     * évident, adressées par l'aperçu admin, chemin relatif.
     *
     * @return list<string>
     */
    private function images(): array
    {
        $movie = Movie::query()
            ->where('availability', ContentAvailability::Published)
            ->whereHas('frames', static function ($query): void {
                $query->where('availability', ContentAvailability::Published)->whereNotNull('game_path');
            })
            ->orderBy('id')
            ->first();

        if ($movie === null) {
            return [];
        }

        $frames = $movie->frames()
            ->where('availability', ContentAvailability::Published)
            ->whereNotNull('game_path')
            ->orderBy('frame_level')
            ->orderBy('id')
            ->limit(self::MAX_IMAGES)
            ->get();

        $urls = [];

        foreach ($frames as $frame) {
            $urls[] = route('admin.catalog.frames.game', ['movie' => $movie->id, 'frame' => $frame->id], false);
        }

        return $urls;
    }

    /**
     * `RoomSettingsState` des réglages par défaut, de même forme que la prop
     * `settings` du lobby (`RoomSettingsPresenter::state()`), le vivier compté
     * sur le catalogue : il n'y a aucun salon, donc ni non-répétition ni
     * mémoire de salon.
     *
     * @return array{settings: array<string, list<string>|list<int>|int|string|bool>, warnings: list<string>, advancedActive: list<string>, pool: array<string, mixed>}
     */
    private function defaultSettings(PoolReporter $reporter): array
    {
        $settings = RoomSettings::defaults();

        return [
            'settings' => RoomSettingsPresenter::view($settings),
            'warnings' => $settings->warnings(),
            'advancedActive' => RoomSettingsEditor::customizedAdvancedFields($settings),
            'pool' => $reporter
                ->report(PoolScope::catalogue($settings->themeIds, $settings->framesPerRound), $settings->roundsCount)
                ->toArray(),
        ];
    }
}
