<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FrameLevel;
use App\Http\Controllers\Controller;
use App\Settings\PlatformLimits;
use App\Support\Curation\ExclusionGrid;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La page « premiers pas du curateur » — `admin.guide` (spec 20 § 13.6,
 * ligne 9 de la matrice des capacités). **Aucune écriture, aucun modèle lu.**
 *
 * Livrée avec l'outil et condition du lot pilote : les deux passes, l'échelle
 * 1-5, la grille d'exclusion item par item, le plancher de recadrage, la
 * revue et la source déclarée, la publication et l'avertissement
 * d'ambiguïté, écarter, les raccourcis, et ce qu'il ne faut jamais faire.
 *
 * La page ne recopie aucun texte normatif : ce qu'elle illustre, elle le rend
 * **depuis les clés** que les autres écrans affichent déjà, et que ce
 * contrôleur lui envoie — l'échelle (`admin.level.{n}.*`, § 6.5), la grille
 * **courante** (`ExclusionGrid`, seule à connaître sa version et ses items,
 * § 7.1) et les raccourcis de débit (`admin.shortcuts.*`, § 6.4). Un item
 * ajouté à une nouvelle version de la grille y paraît donc sans toucher la
 * page, et un libellé ne vit qu'à un seul endroit.
 *
 * Le plancher de recadrage est lu dans `PlatformLimits`, jamais écrit en
 * dur : la page dit la valeur réellement appliquée par le recadreur, le
 * serveur et le job (§ 5.2).
 *
 * Sans garde `can:` : la porte (`auth`, `verified`, `role:curator`,
 * `admin.2fa`) suffit à la garder (§ 2.3).
 */
class GuideController extends Controller
{
    /**
     * Les raccourcis de débit que la page décrit, dans l'ordre du rappel de
     * l'éditeur de la banque (§ 6.1) : classer, visuel voisin, publier en
     * revue.
     *
     * @var list<string>
     */
    public const array SHORTCUT_KEYS = [
        'admin.shortcuts.classify',
        'admin.shortcuts.neighbour',
        'admin.shortcuts.pass',
    ];

    public function show(): Response
    {
        return Inertia::render('admin/guide', [
            'scale' => $this->scale(),
            'grid' => $this->grid(),
            'floor' => $this->floor(),
            'shortcuts' => self::SHORTCUT_KEYS,
        ]);
    }

    /**
     * L'échelle fermée `frame_level`, de la plus cryptique à la plus évidente,
     * et les deux clés de chaque niveau — libellé et guide normatif (§ 6.5).
     *
     * @return list<array{level: int, label_key: string, guide_key: string}>
     */
    private function scale(): array
    {
        return array_map(
            static fn (FrameLevel $level): array => [
                'level' => $level->value,
                'label_key' => "admin.level.{$level->value}.label",
                'guide_key' => "admin.level.{$level->value}.guide",
            ],
            FrameLevel::cases(),
        );
    }

    /**
     * La grille d'exclusion **courante**, item par item, dans l'ordre de la
     * passe de revue : son slug, les niveaux auxquels il s'applique, et ses
     * deux clés gelées avec la version (§ 7.1, § 7.2).
     *
     * @return array{version: int, items: list<array{slug: string, levels: list<int>, label_key: string, help_key: string}>}
     */
    private function grid(): array
    {
        $items = [];

        foreach (ExclusionGrid::slugs() as $slug) {
            $levels = [];

            foreach (FrameLevel::cases() as $level) {
                if (ExclusionGrid::appliesTo($slug, $level)) {
                    $levels[] = $level->value;
                }
            }

            $items[] = [
                'slug' => $slug,
                'levels' => $levels,
                'label_key' => ExclusionGrid::labelKey($slug),
                'help_key' => ExclusionGrid::helpKey($slug),
            ];
        }

        return [
            'version' => ExclusionGrid::CURRENT_VERSION,
            'items' => $items,
        ];
    }

    /**
     * Le plancher de recadrage réellement appliqué (§ 5.2) : la part maximale
     * de la largeur et de la hauteur du visuel que le cadre peut reprendre, la
     * part de surface qui en découle quel que soit le ratio du visuel
     * (`pct² ÷ 100`), et la largeur minimale du cadre. Des nombres, jamais un
     * texte : la page les met en forme dans la langue du curateur.
     *
     * @return array{max_width_percent: int, max_surface_percent: float, min_width_px: int}
     */
    private function floor(): array
    {
        $percent = PlatformLimits::frameCropMaxWidthPercent();

        return [
            'max_width_percent' => $percent,
            'max_surface_percent' => ($percent * $percent) / (float) PlatformLimits::FULL_PERCENT,
            'min_width_px' => PlatformLimits::frameCropMinWidthPx(),
        ];
    }
}
