<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Internal;

/**
 * The sentinel status recorded when an id that was present in a prior run is
 * missing from the current one's data — a mutant whose line was refactored
 * away, a flaky test that was deleted. Explicit rather than either of the two
 * silent alternatives: leaving the last known status stands (a removed mutant
 * would stay "escaped" forever, a false regression that never clears) or
 * dropping the id with no transition at all (its history becomes
 * unreconstructable — {@see Ledger::diff()} could not tell "still applicable,
 * still bad" from "no longer applicable"). Never surfaced to a caller of
 * {@see Datum}; internal to how {@see Ledger} classifies bad/not-bad.
 *
 * @internal
 */
final readonly class AbsentStatus
{
    public const string VALUE = "\0__quality-ledger:absent__";

    private function __construct()
    {
        // Not instantiable — the value is the whole point.
    }
}
