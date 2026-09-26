<?php

namespace App\Models;

use App\Enums\FrameLevel;
use App\Enums\RoundIncidentReason;
use App\ValueObjects\Scoring\TierWindow;
use Carbon\CarbonImmutable;
use Database\Factories\RoundTierFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le palier matérialisé : une image, un instant, une durée, une valeur (§ 7.4).
 *
 * Rien de ce qui décide d'un score ne se recalcule : `starts_at_offset_ms`,
 * `duration_ms` et `points` sont figés au lancement et **aucune colonne de temps
 * ni de points n'est jamais touchée ensuite**. Seules `served_frame_id`,
 * `served_at`, `substitution_reason` et `serve_token` sont écrites — exactement
 * une fois, à l'ouverture du palier, par la transition serveur, JAMAIS par la
 * route de service d'image, qui est en lecture seule sans exception : une requête
 * cliente qui les écrirait daterait le journal sur le préchargement, laisserait
 * un palier affiché sans image enregistrée, et fabriquerait une ligne
 * `seen_frame` sur une image jamais montrée — influençant le tirage des parties
 * suivantes du salon.
 *
 * Il n'existe AUCUNE colonne `opened_at` absolue : l'instant d'ouverture se
 * calcule (`round.started_at + starts_at_offset_ms`), de sorte qu'un job de
 * frontière en retard, rejoué ou perdu décale un affichage et jamais un score.
 *
 * `frame_level` est `#[Hidden]` : les niveaux réellement tirés trahissent le repli
 * de niveau, donc la maigreur de la banque du film.
 *
 * **Les deux relations sont cachées avec leurs colonnes** : `$hidden` filtre aussi
 * les relations chargées (`getArrayableRelations()`), et l'appel réflexe du moteur
 * avant de signer une URL est précisément `$tier->load('servedFrame')`, la garde du
 * § 4.1 exigeant {@see Frame::isServable()} sur la frame réellement servie.
 *
 * @property int $id
 * @property int $round_id
 * @property int $tier_index Rang d'affichage `1..N`, propriété volatile née du tirage.
 * @property int|null $frame_id Variante tirée et figée au lancement. `#[Hidden]`.
 * @property FrameLevel $frame_level Dénormalisé ; garde le journal lisible si la frame disparaît. `#[Hidden]`.
 * @property string|null $serve_token `bin2hex(random_bytes(16))`, écrit à l'ouverture du palier ; seul identifiant d'image qui quitte le serveur, et lié à une MANCHE, pas à une frame. `#[Hidden]`.
 * @property int|null $served_frame_id La variante réellement affichée. `#[Hidden]`.
 * @property CarbonImmutable|null $served_at Instant d'affichage effectif.
 * @property RoundIncidentReason|null $substitution_reason Non nulle seulement si `served_frame_id <> frame_id`.
 * @property int $starts_at_offset_ms Décalage depuis `round.started_at`.
 * @property int $duration_ms Toujours multiple de 1000.
 * @property int $points Valeur 0-1000 figée : le barème n'est plus déductible de la position.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Round $round
 * @property-read Frame|null $frame `#[Hidden]`.
 * @property-read Frame|null $servedFrame `#[Hidden]`.
 */
#[Table('round_tier')]
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([])]
#[Hidden([
    'frame_id',
    'served_frame_id',
    'frame_level',
    'serve_token',
    'frame',
    'servedFrame',
])]
class RoundTier extends Model
{
    /** @use HasFactory<RoundTierFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'round_id' => 'integer',
            'tier_index' => 'integer',
            'frame_id' => 'integer',
            'frame_level' => FrameLevel::class,
            'served_frame_id' => 'integer',
            'served_at' => 'datetime',
            'substitution_reason' => RoundIncidentReason::class,
            'starts_at_offset_ms' => 'integer',
            'duration_ms' => 'integer',
            'points' => 'integer',
        ];
    }

    /**
     * Instant absolu d'ouverture du palier, la seule valeur qui reste calculée.
     *
     * NULL tant que la manche n'a pas démarré.
     */
    public function absoluteStartsAt(): ?CarbonImmutable
    {
        return $this->round->started_at?->addMilliseconds($this->starts_at_offset_ms);
    }

    /**
     * Instant à partir duquel l'image de ce palier peut être signée, préchargement
     * compris : `round.started_at + starts_at_offset_ms − preload_lead_ms`.
     *
     * `preload_lead_ms` vient de `game.preload_lead_ms`, constante serveur figée au
     * lancement — jamais un réglage d'hôte, jamais la durée d'un palier.
     */
    public function servingOpensAt(int $preloadLeadMs): ?CarbonImmutable
    {
        return $this->round->started_at?->addMilliseconds(
            $this->starts_at_offset_ms - $preloadLeadMs,
        );
    }

    /**
     * La garde temporelle de la signature d'URL, **palier 1 compris** : au palier 1
     * la fenêtre s'ouvre avant `round.started_at`, ce qui est voulu.
     *
     * Ce prédicat n'est qu'UNE des trois parties de l'autorisation de service
     * (§ 4.1) : il faut en outre que la frame soit servable au catalogue
     * ({@see Frame::isServable()}) et que le demandeur appartienne à la manche.
     */
    public function isOpenForServing(CarbonImmutable $now, int $preloadLeadMs): bool
    {
        $opensAt = $this->servingOpensAt($preloadLeadMs);

        return $opensAt !== null && $now->greaterThanOrEqualTo($opensAt);
    }

    /**
     * Ce palier est-il celui dont la fenêtre `[offset, offset + duration)` contient
     * le décalage donné ?
     *
     * L'appelant passe la valeur DÉJÀ corrigée du § 7.5,
     * `max(0, guess.answered_at_ms − game.tier_grace_ms)` : l'arithmétique entière
     * est identique en MySQL et en SQLite et immune à tout fuseau, et aucune valeur
     * mesurée ou déclarée par le client n'entre ici (invariant **L3**).
     *
     * Délègue à {@see TierWindow::contains()}, seule formule de fenêtre du dépôt
     * (spec 80 § 4.1) : le palier retenu par le calcul de score et celui que lit
     * ce modèle ne peuvent pas diverger.
     */
    public function containsOffsetMs(int $correctedOffsetMs): bool
    {
        return TierWindow::fromRoundTier($this)->contains($correctedOffsetMs);
    }

    /**
     * @return BelongsTo<Round, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    /**
     * La variante tirée au lancement. `nullOnDelete` : seul endroit du domaine où
     * une perte catalogue est tolérée, et elle ne coûte jamais un point.
     *
     * @return BelongsTo<Frame, $this>
     */
    public function frame(): BelongsTo
    {
        return $this->belongsTo(Frame::class);
    }

    /**
     * La variante réellement affichée — c'est elle, et jamais `frame`, que
     * `seen_frame` enregistre.
     *
     * @return BelongsTo<Frame, $this>
     */
    public function servedFrame(): BelongsTo
    {
        return $this->belongsTo(Frame::class, 'served_frame_id');
    }
}
