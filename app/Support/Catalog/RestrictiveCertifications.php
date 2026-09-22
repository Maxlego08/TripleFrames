<?php

namespace App\Support\Catalog;

use App\Enums\CertificationCountry;

/**
 * Le filtre de **contenu**, et il n'est contournable par aucune voie
 * (décision 12, § 9.2).
 *
 * **Pourquoi des constantes et non de la configuration.** Le filtre de goût
 * vit dans `config/catalog.php` parce qu'il est fait pour être élargi, tracé et
 * rejoué. Celui-ci est fait pour ne jamais bouger : un fichier de configuration
 * est surchargeable en `.env`, en `config:cache` et en test, et la décision 12
 * ne laisse aucune voie de contournement. Ce n'est pas une « valeur de jeu » au
 * sens de la règle 2 — aucune durée, aucun barème, aucun réglage de salon n'est
 * ici : c'est une règle de conformité.
 *
 * **Les -16 restent.** Alien, Matrix et Le Silence des agneaux sont exactement
 * le corpus d'un blindtest ; seules FR -18, US NC-17 et US X sortent.
 *
 * La chaîne comparée est la chaîne **brute** de TMDB, seulement pliée en
 * majuscules et débarrassée de ses espaces : `movie_certification.certification`
 * la conserve telle quelle, parce que la réinterpréter à l'import perdrait la
 * preuve de ce que TMDB a réellement répondu (§ 3.8).
 */
final class RestrictiveCertifications
{
    /**
     * France — le classement « interdit aux moins de 18 ans », sous les deux
     * graphies que TMDB emploie, plus le classement X.
     *
     * @var list<string>
     */
    private const array FRANCE = ['-18', '18', 'X'];

    /**
     * États-Unis — NC-17 et son prédécesseur X, jamais R ni PG-13.
     *
     * @var list<string>
     */
    private const array UNITED_STATES = ['NC-17', 'NC17', 'X'];

    /**
     * La certification brute d'un pays interdit-elle l'entrée au catalogue ?
     *
     * Une chaîne vide n'est jamais restrictive : l'absence de classement est
     * une absence d'information, traitée par `content_flag = unrated_pending`
     * et par la coche de curateur, jamais par un refus (§ 3.8).
     */
    public static function isRestrictive(CertificationCountry $country, string $certification): bool
    {
        $folded = self::fold($certification);

        if ($folded === '') {
            return false;
        }

        return in_array($folded, match ($country) {
            CertificationCountry::France => self::FRANCE,
            CertificationCountry::UnitedStates => self::UNITED_STATES,
        }, true);
    }

    /**
     * Majuscules, espaces retirés — y compris les espaces insécables que TMDB
     * laisse passer dans « NC ‑ 17 ». Aucune autre réécriture : la chaîne
     * stockée reste la chaîne brute.
     */
    private static function fold(string $certification): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', trim($certification)) ?? '');
    }
}
