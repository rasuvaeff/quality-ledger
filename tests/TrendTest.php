<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\QualityLedger\Internal\Assert as Guard;
use Rasuvaeff\QualityLedger\Trend;
use Rasuvaeff\QualityLedger\TrendPoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Trend::class)]
#[Covers(Guard::class)]
final class TrendTest
{
    public function aTrendCarriesItsMetricAndPoints(): void
    {
        $point = new TrendPoint(run: 'r1', ts: 0, value: 90.0);
        $trend = new Trend(metric: 'msi', points: [$point]);

        Assert::same($trend->metric, 'msi');
        Assert::same($trend->points, [$point]);
    }

    public function anEmptyTrendIsFine(): void
    {
        Assert::same((new Trend(metric: 'msi', points: []))->points, []);
    }

    /**
     * The metric is documented `non-empty-string`, and {@see \Rasuvaeff\QualityLedger\Ledger::trend()}
     * hands its own argument straight through — without this check an empty
     * metric name came back as an empty trend, indistinguishable from a metric
     * no run happened to record.
     */
    public function anEmptyMetricIsRejected(): void
    {
        try {
            new Trend(metric: '', points: []);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('metric must not be empty');
        }
    }
}
