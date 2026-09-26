<?php

namespace App\Support\Answers;

use App\Enums\Locale;
use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\Models\RoundPlayer;
use App\Support\Draw\DrawContext;
use App\Support\Draw\SeededPrf;
use App\ValueObjects\Answers\ChoicesPayload;

/**
 * Les quatre propositions du QCM d'un siège, telles qu'elles partent au client
 * (spec 70 § 10.8, contrat C11) — **seule** voie de sortie de
 * `round_choice_set.choice_1..4` et, à travers eux, de
 * `round.decoy_movie_id_*`.
 *
 * **Rejeu identique** (règle 3, 05 § QCM étape 7) : la ligne rendue est celle
 * de `round_player.choices_locale`, figée à la composition ; resynchronisation,
 * rechargement, second onglet et changement de langue rejouent les mêmes
 * quatre chaînes, dans le même ordre, avec le même `lang`
 * (`round_choice_set.rendered_locale`). Deux tirages indépendants pour un
 * même siège auraient la cible pour seul élément commun garanti.
 *
 * **Ordre par siège, jamais stocké** (E10-54) : la proposition affichée en
 * position `j` est la chaîne d'index `perm[j]` de
 * `[choice_1, choice_2, choice_3, choice_4]`, où
 * `perm = SeededPrf::forGame(game)->permutation(DrawContext::qcmOrder(
 * sequence_index, player.public_id), 4)` — contexte
 * `draw:qcm:{sequenceIndex}:{playerPublicId}`, jamais `round_id` ni
 * `player_id`. Identique d'un envoi à l'autre, différent d'un siège à l'autre.
 *
 * **Cas défensif** (arbitrage 16) : un siège dont `choices_locale` est NULL
 * alors que la manche est composée (`decoy_movie_id_1` non nul) et qui accepte
 * encore un clic reçoit une fois `choices_locale = player.locale` et
 * `choices_composed_at = round_choice_set.composed_at`, par une écriture
 * conditionnelle `WHERE choices_locale IS NULL` ; la ligne est relue, et la
 * locale ainsi figée ne bouge plus. Dans tous les autres cas : NULL.
 *
 * Aucun appel TMDB, aucun aléa hors de {@see SeededPrf}.
 */
final readonly class ChoicesPresenter
{
    /**
     * La charge du QCM de ce siège, ou NULL : manche non composée (avant
     * l'ouverture du QCM, cas terminal, Expert), ou siège sans langue de
     * composition qui n'accepte plus de clic.
     */
    public function forSeat(RoundPlayer $roundPlayer): ?ChoicesPayload
    {
        $round = Round::query()->findOrFail($roundPlayer->round_id);
        $player = Player::query()->findOrFail($roundPlayer->player_id);

        $locale = $roundPlayer->choices_composed_at !== null && $roundPlayer->choices_locale !== null
            ? $roundPlayer->choices_locale
            : self::freezeDefensively($roundPlayer, $round, $player);

        if ($locale === null) {
            return null;
        }

        $set = RoundChoiceSet::query()
            ->where('round_id', $round->id)
            ->where('locale', $locale->value)
            ->first();

        // Une langue de composition sans ligne n'a rien à rejouer.
        if ($set === null) {
            return null;
        }

        $stored = [$set->choice_1, $set->choice_2, $set->choice_3, $set->choice_4];

        $order = SeededPrf::forGame(Game::query()->findOrFail($round->game_id))->permutation(
            DrawContext::qcmOrder($round->sequence_index, $player->public_id),
            ChoicesPayload::COUNT,
        );

        return new ChoicesPayload(
            array_map(static fn (int $index): string => $stored[$index], $order),
            $round->choices_use_original_title,
            $set->rendered_locale,
        );
    }

    /**
     * Le cas défensif : pose une seule fois la langue de composition du siège,
     * et rend celle qui est effectivement en base après l'écriture
     * conditionnelle — une autre requête a pu la poser entre-temps.
     */
    private static function freezeDefensively(RoundPlayer $roundPlayer, Round $round, Player $player): ?Locale
    {
        if ($round->decoy_movie_id_1 === null || ! $roundPlayer->input_state->acceptsChoice()) {
            return null;
        }

        $composedAt = RoundChoiceSet::query()
            ->where('round_id', $round->id)
            ->where('locale', $player->locale->value)
            ->value('composed_at');

        if ($composedAt === null) {
            return null;
        }

        RoundPlayer::query()
            ->whereKey($roundPlayer->id)
            ->whereNull('choices_locale')
            ->update([
                'choices_locale' => $player->locale->value,
                'choices_composed_at' => (new RoundPlayer)->fromDateTime($composedAt),
            ]);

        return RoundPlayer::query()->findOrFail($roundPlayer->id)->choices_locale;
    }
}
