<?php

namespace Tests\Support\Realtime;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Job témoin (spec 60 § 20, lot L60-3) : tient la place du job de frontière
 * que la transition programme après son commit, pour prouver qu'une diffusion
 * en échec, rappelée avant lui, ne l'empêche pas de partir. Tourne sur la
 * file `sync` des tests, qui honore `afterCommit()`.
 */
final class RecordingJob implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    public static array $handled = [];

    public function __construct(public readonly string $label) {}

    public function handle(): void
    {
        self::$handled[] = $this->label;
    }
}
