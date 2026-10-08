<?php

namespace App\Support\Identity;

/**
 * Le « relying party » des passkeys — spec 40 § 13.8 (L40-15, D66 du 07/10).
 *
 * Lu par `config/fortify.php` seul. `PASSKEYS_RP_ID` fixe l'identifiant (un
 * nom d'hôte nu, jamais une URL ; une URL est réduite à son hôte), à défaut
 * l'hôte d'`APP_URL`. `PASSKEYS_ALLOWED_ORIGINS` est une liste d'origines
 * séparées par des virgules, à défaut `[APP_URL]`. Les deux variables sont
 * vides dans `.env.example` : aucun nom de domaine n'est écrit dans le dépôt.
 *
 * L'identifiant est figé pour toujours par la première passkey enregistrée en
 * production (décision 5) : en développement sur `dev.<DOMAINE>`, il vaut
 * `<DOMAINE>`, jamais l'hôte de développement.
 */
final class PasskeyRelyingParty
{
    /** L'identifiant déclaré, sinon l'hôte de l'URL de l'application. */
    public static function id(mixed $declared, mixed $appUrl): ?string
    {
        $declared = is_string($declared) ? trim($declared) : '';

        if ($declared !== '') {
            $host = str_contains($declared, '://') ? parse_url($declared, PHP_URL_HOST) : $declared;

            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }

        $host = is_string($appUrl) ? parse_url($appUrl, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Les origines déclarées, sinon l'URL de l'application.
     *
     * @return list<string>
     */
    public static function origins(mixed $declared, mixed $appUrl): array
    {
        $origins = is_string($declared)
            ? array_values(array_filter(
                array_map(static fn (string $origin): string => rtrim(trim($origin), '/'), explode(',', $declared)),
                static fn (string $origin): bool => $origin !== '',
            ))
            : [];

        if ($origins !== []) {
            return $origins;
        }

        return is_string($appUrl) && $appUrl !== '' ? [$appUrl] : [];
    }
}
