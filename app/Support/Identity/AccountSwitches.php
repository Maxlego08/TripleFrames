<?php

namespace App\Support\Identity;

use Illuminate\Support\Facades\App;

/**
 * Les deux interrupteurs de compte du jalon 1 — spec 40 § 8.2.
 *
 * La production existe dès le jalon 1 (D1 du 23/09), avec un seul compte :
 * le premier administrateur. Un déploiement de la configuration du starter y
 * ouvrirait l'inscription publique et l'enregistrement de passkeys, contre
 * CLAUDE.md §1 et la décision 5 (la première passkey de production est le
 * point de non-retour du domaine). Ces deux booléens les ferment.
 *
 * **Lecture.** Seuls `true` et `false` déclarés dans l'environnement sont
 * lus. Toute autre valeur, vide comprise, vaut « non déclarée » et applique
 * une LISTE BLANCHE — la même garde que `DemoAccountsSeeder` : ouvert en
 * `local` et `testing`, fermé partout ailleurs. Une garde « fermé en
 * `production`, ouvert ailleurs » serait une liste noire : le premier nom
 * d'environnement imprévu (`staging`, `prod`, une faute de frappe)
 * ouvrirait l'inscription sur un serveur public.
 *
 * **Ce n'est pas un système de drapeaux de fonctionnalités** (00 § Hors
 * périmètre v1) : deux booléens nommés, lus par cette seule classe, sans
 * table ni interface. Les routes de Fortify restent enregistrées quel que
 * soit l'interrupteur (les helpers Wayfinder sont régénérés au build) ;
 * c'est l'intergiciel `accounts.switches` (`EnforceAccountSwitches`) qui
 * leur répond 404.
 * La future commande d'arrêt du service (`site:close`, spec 100, J2)
 * coupera l'inscription en ajoutant sa condition dans `registrationOpen()`,
 * jamais par une seconde lecture.
 */
final class AccountSwitches
{
    /**
     * Environnements où un interrupteur non déclaré est OUVERT : le poste de
     * développement et la suite de tests, qui exercent déjà l'inscription et
     * les passkeys de Fortify. Liste blanche, jamais liste noire.
     *
     * @var list<string>
     */
    public const array OPEN_WHEN_UNDECLARED_IN = ['local', 'testing'];

    /**
     * L'inscription publique est-elle ouverte ? Gouverne aussi les liens de
     * connexion et d'inscription partagés (`accountsOpen`).
     */
    public static function registrationOpen(): bool
    {
        return self::read('accounts.registration_open');
    }

    /** L'enregistrement et l'usage des passkeys sont-ils ouverts ? */
    public static function passkeysEnabled(): bool
    {
        return self::read('accounts.passkeys_enabled');
    }

    /**
     * Valeur booléenne déclarée, sinon la liste blanche d'environnements.
     * `is_bool` et non une conversion : `'1'`, `'yes'` ou `'on'` ne sont pas
     * ce que `env()` rend pour `true`, et un humain qui les écrit n'a rien
     * déclaré que cette classe sache lire.
     */
    private static function read(string $key): bool
    {
        $declared = config($key);

        if (is_bool($declared)) {
            return $declared;
        }

        return App::environment(self::OPEN_WHEN_UNDECLARED_IN) === true;
    }
}
