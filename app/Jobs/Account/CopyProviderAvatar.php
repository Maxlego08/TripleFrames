<?php

namespace App\Jobs\Account;

use App\Avatars\AccountImage;
use App\Avatars\AvatarImage;
use App\Avatars\AvatarImageException;
use App\Avatars\UploadedAvatars;
use App\Enums\AvatarKind;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La copie locale de la photo du fournisseur — spec 40 § 12.6, D51 du 01/10.
 *
 * File PAR DÉFAUT, jamais `game`. Aucune URL distante n'est jamais servie au
 * client : une fuite de l'adresse du joueur vers un tiers en pleine partie.
 * Le téléchargement est borné — liste blanche d'hôtes, HTTPS seul, AUCUNE
 * redirection suivie, délai court, poids plafonné —, puis l'image passe par
 * la même normalisation qu'un avatar téléversé ({@see AvatarImage}).
 *
 * La copie ne devient l'avatar effectif que si le compte n'en avait AUCUN
 * (`avatar_kind` nul) : jamais par-dessus un choix. Elle est abandonnée si le
 * compte a entre-temps une copie ou un masquage, ou si la liaison a disparu.
 * L'URL distante est effacée dans tous les cas. Un échec est consigné, jamais
 * rejoué : la photo est un confort.
 */
final class CopyProviderAvatar implements ShouldQueue
{
    use Queueable;

    /** Un essai : un hôte qui ne répond pas ne mérite pas une file encombrée. */
    public int $tries = 1;

    /** Hôtes admis, à l'octet près. */
    public const array ALLOWED_HOSTS = [
        'cdn.discordapp.com',
        'lh3.googleusercontent.com',
        'lh4.googleusercontent.com',
        'lh5.googleusercontent.com',
        'lh6.googleusercontent.com',
    ];

    public const int TIMEOUT_SECONDS = 5;

    public const int MAX_BYTES = 2 * 1_048_576;

    public function __construct(public int $linkedAccountId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $linked = LinkedAccount::query()->find($this->linkedAccountId);
        $url = $linked?->provider_avatar_url;

        if ($linked === null || $url === null) {
            return;
        }

        $bytes = self::download($url);
        $linked->forceFill(['provider_avatar_url' => null])->save();

        if ($bytes === null) {
            return;
        }

        try {
            $webp = AvatarImage::normalize($bytes);
        } catch (AvatarImageException $exception) {
            Log::info('Photo du fournisseur refusée à la normalisation.', ['failure' => $exception->failure->value]);

            return;
        }

        $path = UploadedAvatars::newPath(AccountImage::Provider);
        UploadedAvatars::disk()->put($path, $webp);

        $kept = DB::transaction(function () use ($linked, $path): bool {
            $user = User::query()->lockForUpdate()->find($linked->user_id);

            $stillLinked = LinkedAccount::query()->whereKey($linked->id)->exists();

            if ($user === null || ! $stillLinked || $user->avatar_provider_path !== null || $user->avatar_provider_hidden_at !== null) {
                return false;
            }

            $user->forceFill([
                'avatar_provider_path' => $path,
                'avatar_provider_source' => $linked->provider,
                'avatar_provider_reports_from' => Date::now(),
                'avatar_kind' => $user->avatar_kind ?? AvatarKind::Provider,
            ])->save();

            return true;
        });

        if (! $kept) {
            UploadedAvatars::deleteQuietly($path);
        }
    }

    /**
     * Les octets de la photo, ou `null` : hôte hors liste, schéma autre que
     * HTTPS, redirection, réponse en échec ou trop lourde.
     */
    public static function download(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! in_array($parts['host'] ?? null, self::ALLOWED_HOSTS, true)
            || isset($parts['user'])
            || isset($parts['port'])) {
            Log::info('Photo du fournisseur refusée : adresse hors liste blanche.');

            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (Throwable $exception) {
            Log::info('Photo du fournisseur injoignable.', ['exception' => $exception::class]);

            return null;
        }

        $body = $response->body();

        if (! $response->successful() || $body === '' || strlen($body) > self::MAX_BYTES) {
            Log::info('Photo du fournisseur refusée.', ['status' => $response->status(), 'bytes' => strlen($body)]);

            return null;
        }

        return $body;
    }
}
