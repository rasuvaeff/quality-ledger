<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * Fails only on a genuinely new regression (`newBad`) — never on `stillBad`,
 * which is background debt a `DiffReport` already separates out. This is the
 * whole point of the ledger over a global threshold gate: an unrelated PR
 * does not get blocked by debt it did not introduce.
 *
 * @api
 */
final readonly class RatchetGate
{
    public function evaluate(DiffReport $report): GateResult
    {
        return new GateResult(ok: $report->newBad === [], regressions: $report->newBad);
    }
}
