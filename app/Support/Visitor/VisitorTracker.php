<?php

namespace App\Support\Visitor;

use App\Models\Player;
use App\Models\Visitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Le visiteur consentant (D62 du 06/10, spec 40 § 2.3 bis) : seul lecteur et
 * seul écrivain du cookie `visitor` et de la table `visitor`.
 *
 * - **Rien sans consentement.** Le cookie `visitor` n'est déposé qu'à
 *   l'accord ({@see self::accept()}) ; sans accord valide (cookie `consent` à
 *   la version courante), {@see self::current()} rend `null` même si un
 *   cookie `visitor` traîne, et aucun siège n'est relié.
 * - **Retrait** ({@see self::refuse()}) : la ligne `visitor` est supprimée —
 *   les sièges perdent leur lien par `nullOnDelete` — et le cookie effacé.
 * - **Le jeton ne quitte jamais le navigateur et la base n'en garde que
 *   l'empreinte** SHA-256 : une fuite de la table ne permet pas de se faire
 *   passer pour un visiteur.
 */
final class VisitorTracker
{
    public const string COOKIE = 'visitor';

    /** Treize mois, en minutes : la durée maximale d'un traceur (CNIL). */
    public const int LIFETIME = ConsentCookie::ACCEPTED_LIFETIME;

    /** Octets aléatoires du jeton. */
    private const int TOKEN_BYTES = 32;

    /** Le visiteur consentant de la requête, ou `null`. */
    public function current(Request $request): ?Visitor
    {
        if (ConsentCookie::read($request) !== ConsentChoice::Accepted) {
            return null;
        }

        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return null;
        }

        return Visitor::query()->where('token_hash', self::hash($token))->first();
    }

    /**
     * L'accord : le visiteur existant de la requête est gardé, sinon un
     * nouveau est créé ; la preuve du consentement est (ré)écrite à la
     * version courante. Rend le cookie `visitor` à poser.
     */
    public function accept(Request $request): SymfonyCookie
    {
        $now = Date::now()->toImmutable();
        $token = $request->cookie(self::COOKIE);
        $visitor = is_string($token) && $token !== ''
            ? Visitor::query()->where('token_hash', self::hash($token))->first()
            : null;

        if (! $visitor instanceof Visitor) {
            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
            $visitor = new Visitor;
            $visitor->forceFill([
                'token_hash' => self::hash($token),
                'first_seen_at' => $now,
            ]);
        }

        $visitor->forceFill([
            'consent_version' => ConsentCookie::VERSION,
            'consented_at' => $now,
            'last_seen_at' => $now,
        ])->save();

        return Cookie::make(
            name: self::COOKIE,
            value: (string) $token,
            minutes: self::LIFETIME,
            path: '/',
            domain: null,
            secure: App::isProduction(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    /**
     * Le refus ou le retrait : le visiteur de la requête, s'il existe, est
     * supprimé, et le cookie `visitor` effacé.
     */
    public function refuse(Request $request): SymfonyCookie
    {
        $token = $request->cookie(self::COOKIE);

        if (is_string($token) && $token !== '') {
            Visitor::query()->where('token_hash', self::hash($token))->delete();
        }

        return Cookie::forget(self::COOKIE);
    }

    /**
     * Relie un siège au visiteur consentant de la requête, avec l'appareil
     * grossier ; sans visiteur, n'écrit rien. Appelé à la prise d'un siège
     * de salon ou solo, dans sa transaction.
     */
    public function stamp(Player $seat, Request $request): void
    {
        $visitor = $this->current($request);

        if (! $visitor instanceof Visitor) {
            return;
        }

        $userAgent = (string) $request->userAgent();
        $now = Date::now()->toImmutable();

        DB::transaction(function () use ($seat, $visitor, $userAgent, $now): void {
            Player::query()->whereKey($seat->id)->update([
                'visitor_id' => $visitor->id,
                'device_class' => UserAgentFamily::device($userAgent),
                'browser_family' => UserAgentFamily::browser($userAgent),
                'os_family' => UserAgentFamily::os($userAgent),
            ]);

            Visitor::query()->whereKey($visitor->id)->update(['last_seen_at' => $now->format('Y-m-d H:i:s.v')]);
        });
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
