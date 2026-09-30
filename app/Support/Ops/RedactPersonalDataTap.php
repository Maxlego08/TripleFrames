<?php

namespace App\Support\Ops;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * Branche {@see RedactPersonalData} sur un canal de journal — spec 100 § 10.9.
 *
 * Les pilotes `single` et `daily` de Laravel n'acceptent pas de clé
 * `processors` (seul le pilote `monolog` la lit) : leur configuration nomme
 * donc cette classe sous `tap`. Le processeur est empilé EN TÊTE : il passe
 * avant le remplacement des marqueurs du message, et après le processeur de
 * `Context`, que Laravel empile plus tard encore.
 */
final class RedactPersonalDataTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactPersonalData);
        }
    }
}
