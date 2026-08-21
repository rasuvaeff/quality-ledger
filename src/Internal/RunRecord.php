<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Internal;

/**
 * A run's identity and its run-level metrics, independent of any single
 * datum — the row {@see Ledger::trend()} reads.
 *
 * @internal
 */
final readonly class RunRecord
{
    /**
     * @param array<array-key, int|float> $metrics Keyed as `array-key` for the reason given on {@see Transition::__construct()}.
     */
    public function __construct(
        public int $runIndex,
        public string $run,
        public int $ts,
        public array $metrics,
    ) {}
}
