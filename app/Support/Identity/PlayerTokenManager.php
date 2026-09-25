<?php

namespace App\Support\Identity;

use App\Enums\Locale;
use App\Models\Player;
use App\Models\Room;
use App\Support\I18n\Translations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use LogicException;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Lecture, frappe paresseuse, re-signature et résolution du siège — contrat C4
 * (spec 40 § 3).
 *
 * **Singleton sans état.** Les workers de file sont des processus longs : le
 * jeton courant est mémorisé dans `$request->attributes[REQUEST_ATTRIBUTE]`,
 * jamais dans une propriété de l'objet, pour qu'aucune identité ne fuie d'une
 * requête à l'autre (§ 3.1).
 *
 * **Frappe paresseuse (I4.1).** Seul {@see self::ensure()} frappe, et seuls
 * l'appellent les gestes qui prennent ou reprennent un siège (`room.store`,
 * `room.join`, `solo.store`), **une fois tous les refus écartés** (§ 2.1,
 * étape 4) : un GET, `locale.update`, la connexion ou un geste refusé ne posent
 * aucun identifiant. {@see self::current()} ne frappe jamais et ne repose jamais
 * le cookie — c'est ce qui permet à `/f/{serveToken}` de lire le jeton sans
 * émettre de `Set-Cookie`.
 *
 * **Idempotence par requête (I4.2).** Plusieurs `ensure()` et `current()` dans
 * une même requête rendent le même jeton, et un seul `Set-Cookie` part : la
 * file de cookies remplace une entrée de même nom et de même chemin. « Même
 * requête » s'entend de la requête HTTP, pas de l'objet : une `FormRequest`,
 * copie de la requête de base, partage son mémo (voir `memoBags()`).
 *
 * **Siège identifié par le seul hash du jeton courant, expulsé toujours exclu**
 * (§ 3.9, I4.9) : {@see self::seatIn()} pour un salon donné,
 * `Player::heldByToken()` combiné à `whereNull('kicked_at')` ailleurs. Ni IP,
 * ni session, ni `public_id` fourni par le client.
 *
 * **Connexion neutre (I4.6).** Rien ici ne dépend de la session ni du compte :
 * l'identité d'un siège ne passe pas par la session PHP.
 */
final class PlayerTokenManager
{
    public const string REQUEST_ATTRIBUTE = 'tripleframes.player_token';

    /**
     * Le jeton de la requête, ou `null` s'il est absent ou invalide — sans
     * exception ni journal (I4.7). Ne frappe jamais, ne repose jamais le cookie.
     */
    public function current(Request $request): ?PlayerToken
    {
        $bags = $this->memoBags($request);

        if (! $bags[0]->has(self::REQUEST_ATTRIBUTE)) {
            $bags[0]->set(self::REQUEST_ATTRIBUTE, PlayerTokenCookie::read($request));
        }

        $token = $bags[0]->get(self::REQUEST_ATTRIBUTE);

        foreach ($bags as $bag) {
            $bag->set(self::REQUEST_ATTRIBUTE, $token);
        }

        return $token instanceof PlayerToken ? $token : null;
    }

    /**
     * `current() ?? mint`, puis repose le cookie avec 30 jours pleins
     * (glissement) et **réaligne** la revendication `locale` sur la locale
     * effective de la requête, résolue par `SetLocale` (I4.5).
     *
     * À n'appeler qu'une fois tous les refus d'un geste de siège écartés, juste
     * avant l'écriture du siège (§ 2.1, étape 4) — ou à la reprise d'un siège.
     */
    public function ensure(Request $request): PlayerToken
    {
        $locale = $this->effectiveLocale();
        $current = $this->current($request);

        $token = $current === null
            ? PlayerToken::mint($locale)
            : ($current->locale === $locale ? $current : $current->withLocale($locale));

        return $this->remember($request, $token);
    }

    /**
     * Re-signe le jeton courant sous une autre revendication — langue (§ 4.2) ou
     * avatar choisi (I4.5) — et fait glisser l'échéance : le cookie repart de
     * 30 jours pleins (§ 3.6).
     *
     * @throws LogicException si la requête ne porte aucun jeton, ou si le `tid`
     *                        de `$token` diffère de celui de {@see self::current()} :
     *                        aucun chemin ne change le `tid` (I4.4), et
     *                        seul {@see self::ensure()} frappe.
     */
    public function resign(Request $request, PlayerToken $token): PlayerToken
    {
        $current = $this->current($request);

        if ($current === null) {
            throw new LogicException('Re-signature impossible : la requête ne porte aucun player_token (seul ensure() frappe).');
        }

        if (! $current->sameIdentityAs($token)) {
            throw new LogicException('Re-signature refusée : le tid diffère de celui du player_token courant.');
        }

        return $this->remember($request, $token);
    }

    /**
     * Le siège du jeton courant dans ce salon, **expulsé exclu** (I4.9) ; `null`
     * sans jeton. Un siège parti mais non expulsé est rendu : sa reprise
     * appartient au geste de siège.
     */
    public function seatIn(Request $request, Room $room): ?Player
    {
        $token = $this->current($request);

        if ($token === null) {
            return null;
        }

        return Player::query()
            ->whereBelongsTo($room)
            ->heldByToken($token)
            ->whereNull('kicked_at')
            ->first();
    }

    /**
     * Vrai si le jeton courant tient un siège **expulsé** de ce salon : la prise
     * de siège refuse alors par `room.join.kicked`, avant tout comptage (§ 3.10).
     * Le refus tombe de lui-même à l'archivage, qui efface le hash.
     */
    public function wasKickedFrom(Request $request, Room $room): bool
    {
        $token = $this->current($request);

        if ($token === null) {
            return false;
        }

        return Player::query()
            ->whereBelongsTo($room)
            ->heldByToken($token)
            ->whereNotNull('kicked_at')
            ->exists();
    }

    /** Mémorise le jeton pour la requête et met son cookie en file — un seul `Set-Cookie`. */
    private function remember(Request $request, PlayerToken $token): PlayerToken
    {
        foreach ($this->memoBags($request) as $bag) {
            $bag->set(self::REQUEST_ATTRIBUTE, $token);
        }

        PlayerTokenCookie::queue($token);

        return $token;
    }

    /**
     * Les sacs d'attributs qui portent le mémo, celui de la **requête de base**
     * en tête.
     *
     * Une `FormRequest` est bâtie par `FormRequest::createFrom(app('request'))`,
     * qui copie les attributs **par valeur** : sans ce partage, le mémo
     * divergerait entre la `FormRequest` reçue par l'action de siège et
     * `request()`, que lisent `SetLocale` et la garde `player`. Un `ensure()`
     * sur l'une frapperait alors un second `tid`, dont le `Set-Cookie`
     * orphelinerait en silence le siège écrit sous le premier (I4.2).
     *
     * @return non-empty-list<ParameterBag>
     */
    private function memoBags(Request $request): array
    {
        if (! $request instanceof FormRequest || ! App::bound('request')) {
            return [$request->attributes];
        }

        $base = App::make('request');

        if ($base === $request) {
            return [$request->attributes];
        }

        return [$base->attributes, $request->attributes];
    }

    /** La locale posée par `SetLocale` (ordre de `05`), validée par l'enum. */
    private function effectiveLocale(): Locale
    {
        return Locale::tryFrom(App::getLocale()) ?? Translations::fallback();
    }
}
