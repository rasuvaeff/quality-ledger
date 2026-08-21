<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Internal;

/**
 * One status change of one id, recorded only when the status differs from
 * the previous transition (or there is none yet). This is where retention
 * happens architecturally: an id that stays `killed` for a thousand runs
 * costs exactly one `Transition`, not one row per run — {@see LedgerState}
 * never materializes a raw per-run table to begin with.
 *
 * @internal
 */
final readonly class Transition
{
    /**
     * String fields are plain `string`, not `non-empty-string`: a
     * {@see Transition} round-trips through JSON via {@see \Rasuvaeff\QualityLedger\Internal\Codec},
     * and file contents cannot carry a static non-emptiness guarantee — the
     * public types a caller constructs directly ({@see \Rasuvaeff\QualityLedger\Datum},
     * {@see \Rasuvaeff\QualityLedger\RunReport}) are where that contract is
     * actually enforceable.
     *
     * @param int $runIndex Position of the run in append order — the ledger's own sequence, independent of `$run`'s content.
     * @param string $status {@see AbsentStatus::VALUE} when the id disappeared from a run's data.
     * @param array<array-key, mixed> $meta A digit-only key survives a JSON round trip as an `int` — PHP re-coerces it on every write, so the key type is `array-key` rather than a `string` the package cannot deliver.
     */
    public function __construct(
        public string $id,
        public string $kind,
        public int $runIndex,
        public string $run,
        public int $ts,
        public string $status,
        public array $meta,
    ) {}
}
