<?php

namespace App\Support\Preprod;

/**
 * Les deux **auteurs inouvrables** du catalogue de démonstration de la
 * préproduction (`APP_ENV=staging`, spec 100 § 18, n° 5 de D66 du 07/10),
 * posés par `Database\Seeders\PreprodCurationAccountsSeeder`.
 *
 * Leurs adresses vivent ici, dans `app/`, et non dans le seeder : la commande
 * `admin:first-admin` doit les reconnaître sans dépendre d'un espace de noms
 * de `database/`. L'administrateur semé signe la démonstration et rien
 * d'autre — mot de passe aléatoire jeté, adresse `.test` non vérifiée —, donc
 * il ne compte **jamais** comme l'administrateur en place : sans cela,
 * `admin:first-admin` refuserait de créer l'administrateur de recette juste
 * après `db:seed --force` (`ops/mise-en-service.md` § 12).
 */
final class PreprodAuthors
{
    public const string CURATOR_EMAIL = 'preprod-curator@tripleframes.test';

    public const string ADMIN_EMAIL = 'preprod-admin@tripleframes.test';

    /**
     * Les adresses semées qui ne comptent jamais comme administrateur en
     * place : vide hors `staging`, où ces comptes n'existent pas.
     *
     * @return list<string>
     */
    public static function inertAdminEmails(): array
    {
        return app()->environment('staging') ? [self::ADMIN_EMAIL] : [];
    }
}
