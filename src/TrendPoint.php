<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * @api
 */
final readonly class TrendPoint
{
    public function __construct(
        public string $run,
        public int $ts,
        public int|float $value,
    ) {}
}
