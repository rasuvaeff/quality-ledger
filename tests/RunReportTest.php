<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\RunReport;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RunReport::class)]
final class RunReportTest
{
    public function aFullyPopulatedReportIsAccepted(): void
    {
        $datum = new Datum(kind: 'mutant', signature: 'x', status: 'escaped');
        $report = new RunReport(run: 'abc123', ts: 42, scope: 'acme/widgets', data: [$datum], metrics: ['msi' => 90.0]);

        Assert::same($report->run, 'abc123');
        Assert::same($report->ts, 42);
        Assert::same($report->scope, 'acme/widgets');
        Assert::same($report->data, [$datum]);
        Assert::same($report->metrics, ['msi' => 90.0]);
    }

    public function metricsDefaultToEmpty(): void
    {
        Assert::same((new RunReport(run: 'r1', ts: 0, scope: 's', data: []))->metrics, []);
    }

    public function anEmptyRunIsRejected(): void
    {
        $this->assertRejected(static fn(): RunReport => new RunReport(run: '', ts: 0, scope: 's', data: []), 'run must not be empty');
    }

    /**
     * An empty scope is not merely untidy: {@see \Rasuvaeff\QualityLedger\LocalFileStorage}
     * derives its file name from `sha256($scope)`, so every caller that forgot
     * to set one would silently share a single ledger file.
     */
    public function anEmptyScopeIsRejected(): void
    {
        $this->assertRejected(static fn(): RunReport => new RunReport(run: 'r1', ts: 0, scope: '', data: []), 'scope must not be empty');
    }

    public function anEmptyMetricNameIsRejected(): void
    {
        $this->assertRejected(
            static fn(): RunReport => new RunReport(run: 'r1', ts: 0, scope: 's', data: [], metrics: ['' => 1]),
            'metric name must not be empty',
        );
    }

    public function aNegativeTimestampIsAccepted(): void
    {
        Assert::same((new RunReport(run: 'r1', ts: -1, scope: 's', data: []))->ts, -1);
    }

    /**
     * @param \Closure(): RunReport $construct
     */
    private function assertRejected(\Closure $construct, string $expectedMessageFragment): void
    {
        try {
            $construct();

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains($expectedMessageFragment);
        }
    }
}
