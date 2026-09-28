<?php

namespace App\Support\Admin;

use App\Models\AdminAction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * La forme EXACTE des props de l'annuaire des comptes et de l'écran de gestion
 * des accès (spec 20 § 2.8), écrite une seule fois — pendant de
 * {@see AdminCatalogPresenter} pour les comptes, en **snake_case**, miroir de
 * `resources/js/types/admin.ts`.
 *
 * **Règle de sécurité tenue ici, et non déléguée à un `#[Hidden]`** : aucun
 * compte n'est jamais sérialisé entier. On ne compose que ce que l'écran
 * affiche, et jamais le mot de passe, les secrets et codes de secours du
 * second facteur, le jeton « se souvenir de moi », le chemin de la copie
 * locale d'une photo de fournisseur ni l'adresse d'un compte de fournisseur.
 * Les configurations sauvegardées n'apparaissent nulle part : elles sont
 * strictement privées, y compris d'un administrateur.
 *
 * Le nom réel, lui, est rendu : `#[Hidden]` le garde de toute surface joueur
 * (D12 du 23/09), et l'écran de gestion des accès est précisément le lieu où
 * il se lit et se corrige.
 *
 * Toute date part en ISO-8601, jamais en texte pré-formaté (règle 4).
 */
final class AdminAccountPresenter
{
    /**
     * Une ligne de compte — les colonnes partagées par l'annuaire, la liste
     * des comptes privilégiés et la recherche par adresse.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string|null,
     *     email_verified: bool,
     *     real_name: string|null,
     *     role: string,
     *     two_factor_confirmed: bool,
     *     last_login_at: string|null,
     *     created_at: string|null,
     *     anonymized: bool,
     * }
     */
    public static function row(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email !== null && $user->email_verified_at !== null,
            'real_name' => $user->real_name,
            'role' => $user->role->value,
            'two_factor_confirmed' => $user->two_factor_confirmed_at !== null,
            'last_login_at' => self::moment($user->last_login_at),
            'created_at' => self::moment($user->created_at),
            'anonymized' => $user->anonymized_at !== null,
        ];
    }

    /**
     * La fiche d'un compte — la ligne, plus ce que la fiche affiche.
     *
     * @return array<string, mixed>
     */
    public static function detail(User $user): array
    {
        return [
            ...self::row($user),
            'email_verified_at' => self::moment($user->email_verified_at),
            'two_factor_confirmed_at' => self::moment($user->two_factor_confirmed_at),
            'locale' => $user->locale->value,
            'terms_version' => $user->terms_version,
            'terms_accepted_at' => self::moment($user->terms_accepted_at),
            'age_confirmed_at' => self::moment($user->age_confirmed_at),
            'anonymized_at' => self::moment($user->anonymized_at),
        ];
    }

    /**
     * Une ligne du journal portant sur un compte : changement de rôle,
     * correction du nom réel, masquage ou rétablissement d'une photo. L'auteur
     * est rendu par son **instantané** `actor_name`, jamais par son compte :
     * c'est le nom sous lequel le geste a été signé. Le sujet, quand l'écran
     * mêle plusieurs comptes, est rendu par son pseudo et son nom réel
     * courants ; `null` sur la fiche du compte lui-même.
     *
     * @return array{
     *     id: int,
     *     action: string,
     *     actor_name: string,
     *     subject: array{id: int, name: string, real_name: string|null}|null,
     *     role_before: string|null,
     *     role_after: string|null,
     *     reason: string|null,
     *     created_at: string|null,
     * }
     */
    public static function historyLine(AdminAction $line, ?User $subject = null): array
    {
        return [
            'id' => $line->id,
            'action' => $line->action->value,
            'actor_name' => $line->actor_name,
            'subject' => $subject === null ? null : [
                'id' => $subject->id,
                'name' => $subject->name,
                'real_name' => $subject->real_name,
            ],
            'role_before' => $line->role_before?->value,
            'role_after' => $line->role_after?->value,
            'reason' => $line->reason,
            'created_at' => self::moment($line->created_at),
        ];
    }

    /** Un instant, en ISO-8601 — jamais une chaîne pré-formatée côté serveur. */
    private static function moment(?CarbonImmutable $moment): ?string
    {
        return $moment?->toIso8601String();
    }
}
