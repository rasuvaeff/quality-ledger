<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\QualityLedger\DiffEntry;
use Rasuvaeff\QualityLedger\DiffReport;
use Rasuvaeff\QualityLedger\StillBadEntry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DiffReport::class)]
final class DiffReportTest
{
    public function anEmptyReportComparesNothing(): void
    {
        Assert::same((new DiffReport('base', 'head', [], [], [], 0))->totalIdsCompared(), 0);
    }

    public function everyBucketContributesToTheTotal(): void
    {
        $report = new DiffReport(
            'base',
            'head',
            [new DiffEntry('a', 'thing', [])],
            [new DiffEntry('b', 'thing', []), new DiffEntry('c', 'thing', [])],
            [new StillBadEntry('d', 'thing', [], 1)],
            4,
        );

        Assert::same($report->totalIdsCompared(), 8);
    }

    /**
     * The denominator a caller divides by: it must be the sum of all four
     * buckets, with none of them dropped or double-counted.
     *
     * @param int<0, 20> $newBad
     * @param int<0, 20> $fixed
     * @param int<0, 20> $stillBad
     * @param int<0, 20> $unchanged
     */
    #[Property(runs: 200)]
    public function theTotalIsTheSumOfEveryBucket(int $newBad, int $fixed, int $stillBad, int $unchanged): void
    {
        $report = new DiffReport(
            'base',
            'head',
            $this->diffEntries('n', $newBad),
            $this->diffEntries('f', $fixed),
            array_map(
                static fn(DiffEntry $entry): StillBadEntry => new StillBadEntry($entry->id, $entry->kind, $entry->meta, 1),
                $this->diffEntries('s', $stillBad),
            ),
            $unchanged,
        );

        Assert::same($report->totalIdsCompared(), $newBad + $fixed + $stillBad + $unchanged);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theTotalIsTheSumOfEveryBucketGenerators(): array
    {
        return [
            'newBad' => Gen::intBetween(0, 20),
            'fixed' => Gen::intBetween(0, 20),
            'stillBad' => Gen::intBetween(0, 20),
            'unchanged' => Gen::intBetween(0, 20),
        ];
    }

    /**
     * @return iterable<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function theTotalIsTheSumOfEveryBucketExamples(): iterable
    {
        yield 'nothing at all' => [0, 0, 0, 0];
        yield 'only unchanged' => [0, 0, 0, 5];
        yield 'one of each' => [1, 1, 1, 1];
    }

    /**
     * @return list<DiffEntry>
     */
    private function diffEntries(string $prefix, int $count): array
    {
        $entries = [];

        for ($i = 0; $i < $count; ++$i) {
            $entries[] = new DiffEntry($prefix . $i, 'thing', []);
        }

        return $entries;
    }
}
