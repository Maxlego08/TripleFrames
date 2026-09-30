<?php

namespace App\Support\I18n;

use InvalidArgumentException;

/**
 * Sélection des domaines de traduction expédiés dans la charge utile Inertia.
 *
 * La spec 05 refuse d'envoyer le dictionnaire entier à chaque réponse : une
 * page de jeu embarque `common` + `game`, l'accueil `common` + `legal`, et le
 * domaine `admin` n'est **jamais** expédié à un écran joueur ni l'inverse.
 * Cette classe est le mécanisme de sélection : un singleton de requête que
 * remplissent le middleware `SelectTranslationDomains`
 * (alias de route `translations:game,room`), le middleware
 * `ForceAdminLocale` et, au besoin, un contrôleur.
 *
 * Elle est lue **tardivement** : la prop `translations` de
 * `HandleInertiaRequests` est une closure, résolue au
 * rendu de la réponse, donc après les middlewares de route. Une lecture
 * anticipée ne verrait jamais que `common`.
 */
final class TranslationDomains
{
    /** Domaine joint à toute charge utile joueur. */
    public const string BASE = 'common';

    /** Domaine du back-office : français par construction, jamais mêlé aux domaines joueur. */
    public const string ADMIN = 'admin';

    /**
     * Les sept domaines normatifs de la spec 05, dans son ordre.
     *
     * @var list<string>
     */
    public const array KNOWN = [
        'common',
        'game',
        'room',
        'account',
        'legal',
        'mail',
        'admin',
    ];

    /** @var list<string> */
    private array $selected = [];

    /**
     * Déclare les domaines dont la page rendue a besoin.
     *
     * Un domaine inconnu lève : une faute de frappe expédierait silencieusement
     * un dictionnaire vide, et l'écran afficherait des clés brutes en
     * production sans qu'aucun test ne s'en aperçoive.
     *
     * @throws InvalidArgumentException
     */
    public function need(string ...$domains): void
    {
        foreach ($domains as $domain) {
            if (! in_array($domain, self::KNOWN, true)) {
                throw new InvalidArgumentException(
                    "Domaine de traduction inconnu : [{$domain}]. Domaines normatifs : "
                    .implode(', ', self::KNOWN).'.',
                );
            }

            if (! in_array($domain, $this->selected, true)) {
                $this->selected[] = $domain;
            }
        }
    }

    /**
     * Domaines à expédier, `common` compris — sauf sur un écran
     * d'administration, qui ne reçoit que `admin`.
     *
     * @return list<string>
     */
    public function selected(): array
    {
        if (in_array(self::ADMIN, $this->selected, true)) {
            return [self::ADMIN];
        }

        $domains = $this->selected;

        if (! in_array(self::BASE, $domains, true)) {
            $domains[] = self::BASE;
        }

        sort($domains);

        return $domains;
    }
}
