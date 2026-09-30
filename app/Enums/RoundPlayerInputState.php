<?php

namespace App\Enums;

/**
 * État de saisie d'un joueur dans une manche, jamais diffusé pour un autre
 * joueur : cast de `round_player.input_state` (spec 70 § 3, contrat C10).
 *
 * Trois prédicats (spec 70 § 3.3) : `acceptsText()` (le texte libre est-il
 * recevable ?), `acceptsChoice()` (le clic QCM l'est-il ? — seul prédicat par
 * lequel 60 choisit les destinataires de `seat.choices`) et `isClosed()` (la
 * saisie est-elle close pour la fin anticipée ?). 70 ne juge une soumission
 * que par les deux premiers. `TextExhausted` (D20 du 23/09) est le seul état
 * où texte et QCM divergent : texte fermé, QCM attendu, saisie NON close.
 *
 * `AttemptsExhausted`, `Locked`, `QcmWrong`, `Revealed` et `Skipped` sont
 * terminaux pour la manche.
 */
enum RoundPlayerInputState: string
{
    /** Saisie ouverte — défaut SQL, ligne créée à `T₁` par 60. */
    case Open = 'open';

    /**
     * « Texte épuisé, QCM attendu » (D20 du 23/09) : en Normal SEULEMENT, un
     * siège qui épuise ses tentatives de texte libre alors que le QCM reste à
     * venir ou à cliquer ne peut plus taper, mais reçoit les quatre
     * propositions à `T_N` et peut cliquer une fois. Ce n'est JAMAIS une
     * saisie close : la manche ne se clôt pas d'anticipation tant qu'il peut
     * cliquer, et son `input_closed_at` reste NULL. Le cas terminal du QCM le
     * fait passer `AttemptsExhausted` (spec 70 § 10.7). 14 caractères sous
     * `string(20)` : aucune migration (E10-06).
     */
    case TextExhausted = 'text_exhausted';

    case Locked = 'locked';

    case QcmWrong = 'qcm_wrong';

    case AttemptsExhausted = 'attempts_exhausted';

    case Revealed = 'revealed';

    case Skipped = 'skipped';

    /**
     * Les états dont la saisie n'est PAS close : seule liste, lue par
     * {@see self::isClosed()}, {@see self::notClosedValues()} et donc par le
     * scope `RoundPlayer::open()` et le prédicat de fin anticipée.
     *
     * @var list<self>
     */
    private const array NOT_CLOSED = [self::Open, self::TextExhausted];

    /** Le texte libre est recevable : `Open` seulement. */
    public function acceptsText(): bool
    {
        return $this === self::Open;
    }

    /**
     * Le clic QCM est recevable — `Open` et `TextExhausted` —, et c'est le seul
     * prédicat qui choisit les destinataires de `seat.choices` (R-28).
     */
    public function acceptsChoice(): bool
    {
        return $this === self::Open || $this === self::TextExhausted;
    }

    /**
     * Numérateur du prédicat de fin anticipée : tous les participants dont la
     * saisie est close arrêtent la manche. Faux pour `Open` ET pour
     * `TextExhausted`, qui attend encore le QCM (E10-53).
     */
    public function isClosed(): bool
    {
        return ! in_array($this, self::NOT_CLOSED, true);
    }

    /**
     * Les valeurs SQL des états non clos, `['open', 'text_exhausted']`, pour un
     * `whereIn` : le complément exact de {@see self::isClosed()}.
     *
     * @return list<string>
     */
    public static function notClosedValues(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::NOT_CLOSED);
    }

    /** Inatteignable hors `game.mode = 'solo'`, et ne produit jamais de ligne `guess`. */
    public function isSoloOnly(): bool
    {
        return $this === self::Revealed || $this === self::Skipped;
    }
}
