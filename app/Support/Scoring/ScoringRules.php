<?php

namespace App\Support\Scoring;

use App\Enums\GuessSource;
use App\Enums\InputDifficulty;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use LogicException;

/**
 * La règle de score, versionnée (spec 80 § 3, § 4.3 et § 6, contrat C13).
 *
 * {@see self::VERSION} est écrite au lancement dans `game.scoring_version`
 * (contrat C6) ; une partie se note, se départage et se rejoue TOUJOURS sous sa
 * propre version, jamais sous la constante courante.
 *
 * **Elle s'incrémente à tout changement de** (§ 6.1, C13 § 4.7) : la table
 * `B_max(N)` ; la forme ou l'arrondi du bonus ; la règle de sélection du palier,
 * application de la grâce et plancher du QCM compris ; la chaîne de départage ;
 * la définition d'un agrégat figé ou de `rounds_completed` ; le DÉFAUT de
 * `tierGraceMs` ou de `preloadLeadMs`. Elle ne s'incrémente pas pour les faits
 * marquants du podium, un texte, un affichage ou une valeur d'`EngineConstants`.
 *
 * **Branches conservées** (§ 6.2) : toute méthode qui reçoit `$version` branche
 * sur elle, et la branche d'une version publiée n'est jamais modifiée — une
 * nouvelle règle est une nouvelle branche, le test d'empreinte de chaque version
 * reste vert, et une version inconnue lève {@see UnsupportedScoringVersion}.
 *
 * Aucune méthode ne reçoit un compte, un plan ni un siège : ce sont des
 * constantes d'instance (§ 3.5). Aucun aléa : une égalité de score ne se tranche
 * jamais par la graine de la partie (§ 8.3).
 */
final class ScoringRules
{
    /** Version courante, écrite dans `game.scoring_version` au lancement. */
    public const int VERSION = 1;

    /**
     * Ordre normatif de la chaîne de départage (§ 8.1), épinglé par le test
     * d'empreinte ; `tier_finds_desc` compare les trouvailles aux paliers 1, 2,
     * …, N − 1, dans cet ordre.
     *
     * @var list<string>
     */
    public const array TIE_BREAK_CHAIN = [
        'score_desc',
        'correct_answers_desc',
        'total_answer_time_ms_asc',
        'tier_finds_desc',
    ];

    /** Étiquette de la branche de la version 1 : jamais modifiée une fois publiée. */
    private const int V1 = 1;

    /**
     * Versions dont ce code porte la branche. Une version publiée n'en sort
     * jamais : `ScoreReplayer` doit redonner les points d'origine de toute
     * partie encore conservée.
     *
     * @var list<int>
     */
    private const array SUPPORTED_VERSIONS = [self::V1];

    /** Plancher neutre : le premier palier, donc aucun plancher. */
    private const int FIRST_TIER_INDEX = 1;

    /**
     * @throws UnsupportedScoringVersion Version dont ce code ne porte pas la branche.
     */
    public static function assertSupported(int $version): void
    {
        if (! in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw self::unsupported($version);
        }
    }

    /**
     * `B_max` en pourcentage ENTIER de la valeur du palier, pour `N` images par
     * manche (§ 3.1, D22 du 23/09). Seul point d'appel du calcul.
     *
     * Version 1 : {@see PlatformLimits::speedBonusMaxPercent()}, soit
     * `min(SPEED_BONUS_MAX_PERCENT_CAP, intdiv(FULL_PERCENT, N − 1))` — 50, 50, 33
     * et 25 pour `N` = 2 à 5 ; `N` hors bornes lève, jamais écrêté.
     *
     * @throws UnsupportedScoringVersion
     */
    public static function speedBonusMaxPercent(int $framesPerRound, int $version = self::VERSION): int
    {
        return match ($version) {
            self::V1 => PlatformLimits::speedBonusMaxPercent($framesPerRound),
            default => throw self::unsupported($version),
        };
    }

    /**
     * Plancher de palier du QCM — SEULE implémentation (§ 4.3, R-18).
     *
     * Version 1 : texte libre → 1, donc sans effet ; clic QCM →
     * `InputDifficulty::choicesOpenTierIndex(N)`, soit 1 en Facile et `N` en
     * Normal ; clic en Expert → `LogicException`, la saisie refusant toute route
     * QCM en Expert avant d'appeler le calcul. Sans ce plancher, un clic reçu dans
     * la grâce qui suit `T_N` achèterait le palier `N − 1`, mieux payé, pour avoir
     * vu les propositions.
     *
     * @throws UnsupportedScoringVersion
     * @throws LogicException Clic QCM dans une partie Expert.
     */
    public static function floorTierIndex(
        GuessSource $source,
        InputDifficulty $difficulty,
        int $framesPerRound,
        int $version = self::VERSION,
    ): int {
        return match ($version) {
            self::V1 => self::floorTierIndexV1($source, $difficulty, $framesPerRound),
            default => throw self::unsupported($version),
        };
    }

    /**
     * Mode « sans score » : tous les paliers valent 0 (§ 2.5). Les valeurs étant
     * positives ou nulles, leur somme nulle suffit.
     */
    public static function isScoreless(RoomSettings $settings): bool
    {
        return array_sum($settings->tierPoints) === 0;
    }

    /**
     * « Attendre paie » (§ 3.4, jalon 2 ; son avertissement est adopté par
     * l'onglet Avancé, spec 50, lot L50-10) : vrai si, pour un `i < N`,
     * `P_i + 0 < P_{i+1} + intdiv(P_{i+1} × B_max(N), 100)` — la fin du
     * palier `i` rapporte moins que l'ouverture du palier suivant, bonus
     * compris.
     *
     * Le terme `P_i + 0` est exact : le bonus est nul à la dernière
     * milliseconde de tout palier légal (§ 3.2). Fausse pour tout barème par
     * défaut (§ 3.3), elle n'est pas couverte par `non_decreasing_points` : à
     * `N = 3`, 300 / 250 / 100 est strictement décroissant et pourtant
     * 250 + 125 = 375 > 300. Elle suppose le bonus actif : l'appelant qui en
     * fait un avertissement ne la lit que si `speedBonus` est vrai.
     *
     * @param  list<int>  $tierPoints
     */
    public static function waitingPays(array $tierPoints, int $framesPerRound): bool
    {
        $percent = self::speedBonusMaxPercent($framesPerRound);
        $count = count($tierPoints);

        for ($index = 0; $index < $count - 1; $index++) {
            $next = $tierPoints[$index + 1];

            if ($tierPoints[$index] < $next + intdiv($next * $percent, PlatformLimits::FULL_PERCENT)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Branche de la version 1 de {@see self::floorTierIndex()}.
     */
    private static function floorTierIndexV1(GuessSource $source, InputDifficulty $difficulty, int $framesPerRound): int
    {
        return match ($source) {
            GuessSource::Text => self::FIRST_TIER_INDEX,
            GuessSource::Choice => $difficulty->choicesOpenTierIndex($framesPerRound)
                ?? throw new LogicException(sprintf(
                    'ScoringRules : aucun clic QCM n’est recevable en difficulté %s.',
                    $difficulty->value,
                )),
        };
    }

    private static function unsupported(int $version): UnsupportedScoringVersion
    {
        return new UnsupportedScoringVersion(sprintf(
            'ScoringRules : la version de règle de score %d n’est pas prise en charge (versions connues : %s).',
            $version,
            implode(', ', self::SUPPORTED_VERSIONS),
        ));
    }
}
