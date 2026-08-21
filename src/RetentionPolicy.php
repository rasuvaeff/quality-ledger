<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

use Rasuvaeff\QualityLedger\Internal\LedgerState;

/**
 * Bounds how long a dead id (one that stopped appearing in runs) is kept
 * before it is dropped. Every id still active — appearing in the most recent
 * run, or currently "bad" — is never pruned regardless of this policy; only
 * ids that vanished from the observed set age out.
 *
 * @api
 */
final readonly class RetentionPolicy
{
    public function __construct(
        private int $tombstoneTtlRuns = 50,
    ) {
        if ($this->tombstoneTtlRuns < 0) {
            throw new \InvalidArgumentException('tombstoneTtlRuns must not be negative');
        }
    }

    public function apply(LedgerState $state): LedgerState
    {
        return $state->withTombstonesPruned($this->tombstoneTtlRuns);
    }
}
