<?php

namespace App\Support\Frames;

use App\Enums\FrameProcessingFailure;
use RuntimeException;
use Throwable;

/**
 * Échec typé du traitement d'une image (contrat C9, spec 20 § 5.5 et § 5.6),
 * levé par {@see FrameImageProcessor::process()}.
 *
 * Il porte la cause, une {@see FrameProcessingFailure} dont la valeur est la
 * clé de traduction que le job écrit dans `frame.processing_error`. Le message
 * de l'exception est le DÉTAIL TECHNIQUE, destiné au journal applicatif et
 * jamais à l'écran : il ne nomme ni pseudo, ni adresse, ni chemin de fichier.
 *
 * Une panne qui n'est PAS une instance de cette classe est transitoire : le job
 * la laisse remonter pour que la file la rejoue, puis la consigne en
 * `unexpected` quand les essais sont épuisés.
 */
final class FrameProcessingException extends RuntimeException
{
    public function __construct(
        public readonly FrameProcessingFailure $failure,
        string $detail = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail !== '' ? $detail : $failure->name, 0, $previous);
    }

    public static function because(FrameProcessingFailure $failure, string $detail = '', ?Throwable $previous = null): self
    {
        return new self($failure, $detail, $previous);
    }
}
