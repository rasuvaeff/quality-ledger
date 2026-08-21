<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * @api
 */
final readonly class Trend
{
    /**
     * @param non-empty-string $metric
     * @param list<TrendPoint> $points Ordered oldest to newest; a run missing `$metric` is skipped, not zero-filled.
     */
    public function __construct(
        public string $metric,
        public array $points,
    ) {}
}
