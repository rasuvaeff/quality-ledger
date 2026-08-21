<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * Every id observed at `base` and/or `head`, partitioned by the (baseBad,
 * headBad) pair — exactly one bucket per id, no overlaps:
 *
 * | base | head | bucket |
 * |---|---|---|
 * | not bad (or unseen) | bad | `newBad` |
 * | bad | not bad (or gone) | `fixed` |
 * | bad | bad | `stillBad` |
 * | not bad | not bad | folded into `unchangedCount` |
 *
 * `unchangedCount` is a count, not a list: it is usually the largest bucket
 * and the least interesting one — nobody needs to see every mutant that has
 * always been killed. `newBad` and `stillBad` are what {@see RatchetGate} and
 * a worklist actually consume.
 *
 * @api
 */
final readonly class DiffReport
{
    /**
     * @param non-empty-string $base
     * @param non-empty-string $head
     * @param list<DiffEntry> $newBad
     * @param list<DiffEntry> $fixed
     * @param list<StillBadEntry> $stillBad
     * @param int<0, max> $unchangedCount
     */
    public function __construct(
        public string $base,
        public string $head,
        public array $newBad,
        public array $fixed,
        public array $stillBad,
        public int $unchangedCount,
    ) {}

    /**
     * @return int<0, max>
     */
    public function totalIdsCompared(): int
    {
        return \count($this->newBad) + \count($this->fixed) + \count($this->stillBad) + $this->unchangedCount;
    }
}
