<?php

namespace App\Avatars;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Le stockage des images téléversées par les comptes — spec 40 § 11.3,
 * D49 du 01/10.
 *
 * Disque privé `avatars`, préfixe `upload/`, nom `bin2hex(random_bytes(16))` :
 * jamais un ULID (trié dans le temps) ni un identifiant de compte. Le chemin
 * reste en base, `#[Hidden]` ; le client ne reçoit que l'URL de la route
 * `avatar.show`, qui ne sert l'image que tant qu'elle est l'image courante et
 * visible de son compte.
 */
final class UploadedAvatars
{
    public const string DISK = 'avatars';

    public const string PREFIX = 'upload/';

    /** Copie locale de la photo du fournisseur (spec 40 § 12.6, D51 du 01/10). */
    public const string PROVIDER_PREFIX = 'provider/';

    public const string EXTENSION = 'webp';

    /** Motif du nom de fichier dans l'URL : 32 caractères hexadécimaux, extension comprise. */
    public const string FILE_PATTERN = '[0-9a-f]{32}\.webp';

    /** Un chemin neuf, jamais réutilisé, sous le préfixe de cette image. */
    public static function newPath(AccountImage $image = AccountImage::Upload): string
    {
        return $image->prefix().bin2hex(random_bytes(16)).'.'.self::EXTENSION;
    }

    /** Le chemin relatif d'un nom de fichier reçu dans l'URL, pour cette image. */
    public static function pathOf(string $file, AccountImage $image = AccountImage::Upload): string
    {
        return $image->prefix().$file;
    }

    /** URL RELATIVE de la route applicative : ni domaine, ni chemin de disque. */
    public static function url(string $path): string
    {
        return route('avatar.show', ['file' => basename($path)], absolute: false);
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * Le chemin de l'image VISIBLE d'un compte, lu par clé primaire — la
     * résolution vivante d'un siège de nature `upload` ou `provider` (spec 40
     * § 11.5, § 12.6). `null` sans compte, sans image, image masquée ou compte
     * anonymisé.
     */
    public static function visiblePath(?int $userId, AccountImage $image = AccountImage::Upload): ?string
    {
        if ($userId === null) {
            return null;
        }

        $user = User::query()->select(AccountImage::columns())->find($userId);

        return $user !== null && $image->isVisible($user) ? $image->path($user) : null;
    }

    /**
     * Un fichier qui ne se supprime pas ne fait jamais échouer un geste
     * réussi : il est consigné, et l'orphelin se retrouve par son préfixe.
     */
    public static function deleteQuietly(?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            self::disk()->delete($path);
        } catch (Throwable $exception) {
            Log::warning('Avatar téléversé : fichier non supprimé.', ['exception' => $exception::class]);
        }
    }
}
