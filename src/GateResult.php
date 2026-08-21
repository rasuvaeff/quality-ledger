<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * @api
 */
final readonly class GateResult
{
    /**
     * @param list<DiffEntry> $regressions
     */
    public function __construct(
        public bool $ok,
        public array $regressions,
    ) {}
}
