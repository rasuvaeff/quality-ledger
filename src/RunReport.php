<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

use Rasuvaeff\QualityLedger\Internal\Assert;

/**
 * One analyzer run, ready to append: every datum it observed, plus whatever
 * scalar metrics the run produces as a whole (an MSI, a pass count, ...).
 *
 * `run` and `ts` are supplied by the caller — the ledger never calls git or
 * reads the clock, so a test can construct any sequence of runs deterministically.
 *
 * @api
 */
final readonly class RunReport
{
    /**
     * @param non-empty-string $run Identifies this run (a commit sha, a UUID); unique per {@see Ledger::append()} call.
     * @param non-empty-string $scope A history namespace — typically one package/repository.
     * @param list<Datum> $data
     * @param array<non-empty-string, int|float> $metrics Run-level scalars (e.g. `msi`, `total`), independent of any single datum.
     */
    public function __construct(
        public string $run,
        public int $ts,
        public string $scope,
        public array $data,
        public array $metrics = [],
    ) {
        Assert::nonEmpty($this->run, 'run');
        Assert::nonEmpty($this->scope, 'scope');

        Assert::nonEmptyKeys($this->metrics, 'metric name');
    }
}
