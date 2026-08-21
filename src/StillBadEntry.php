<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * A {@see DiffEntry} that was already bad at `base` and still is at `head`,
 * with how many runs it has been continuously bad — the "age" a two-point
 * diff cannot otherwise express. `id`/`kind` are plain `string` for the same
 * reason as {@see DiffEntry}.
 *
 * @api
 */
final readonly class StillBadEntry
{
    /**
     * @param array<array-key, mixed> $meta Carried through from the observation verbatim; a digit-only key arrives as an `int` after a round trip through storage.
     * @param int<0, max> $ageRuns Number of runs since the id most recently became bad.
     */
    public function __construct(
        public string $id,
        public string $kind,
        public array $meta,
        public int $ageRuns,
    ) {}
}
