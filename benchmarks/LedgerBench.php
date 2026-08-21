<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Benchmarks;

use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\DefaultStableId;
use Rasuvaeff\QualityLedger\Ledger;
use Rasuvaeff\QualityLedger\RunReport;
use Rasuvaeff\QualityLedger\StoragePort;
use Testo\Bench;

/**
 * The headline consumer is a mutation run with tens of thousands of mutants,
 * so the cost of a run has to grow with the number of ids, not with its
 * square. Two shapes were quadratic before: an `in_array()` duplicate check
 * over a growing list, and one full copy of the id table per observation.
 *
 * Both benchmarks are deliberately shaped as a comparison against the same
 * work at a quarter of the size, because the absolute number says nothing —
 * the *ratio* is the signal. Roughly 4× for 4× the ids is linear; roughly 16×
 * is the quadratic behaviour coming back.
 */
final class LedgerBench
{
    private const string SCOPE = 'acme/widgets';

    #[Bench(
        callables: [
            'a quarter of the ids' => [self::class, 'appendOneThousandIds'],
        ],
        calls: 5,
        iterations: 5,
    )]
    public static function appendFourThousandIds(): void
    {
        self::appendRun(4_000);
    }

    public static function appendOneThousandIds(): void
    {
        self::appendRun(1_000);
    }

    /**
     * The read path a CI gate actually runs: two runs already recorded, one
     * `diff()` over every id.
     */
    #[Bench(
        callables: [
            'a quarter of the ids' => [self::class, 'diffOneThousandIds'],
        ],
        calls: 5,
        iterations: 5,
    )]
    public static function diffFourThousandIds(): void
    {
        self::diffRuns(4_000);
    }

    public static function diffOneThousandIds(): void
    {
        self::diffRuns(1_000);
    }

    private static function appendRun(int $ids): void
    {
        $ledger = new Ledger(new DefaultStableId(), new InMemoryBenchStorage());

        $ledger->append(new RunReport(run: 'r1', ts: 0, scope: self::SCOPE, data: self::data($ids, 'killed')));
    }

    private static function diffRuns(int $ids): void
    {
        $ledger = new Ledger(new DefaultStableId(), new InMemoryBenchStorage());
        $ledger->append(new RunReport(run: 'base', ts: 0, scope: self::SCOPE, data: self::data($ids, 'killed')));
        $ledger->append(new RunReport(run: 'head', ts: 1, scope: self::SCOPE, data: self::data($ids, 'escaped')));

        $ledger->diff(self::SCOPE, 'base', 'head', static fn(string $status): bool => $status === 'escaped');
    }

    /**
     * @return list<Datum>
     */
    private static function data(int $count, string $status): array
    {
        $data = [];

        for ($i = 0; $i < $count; ++$i) {
            $data[] = new Datum(kind: 'mutant', signature: 'src/File.php:' . $i . ':TrueValue', status: $status);
        }

        return $data;
    }
}

/**
 * Storage is not what these benchmarks measure, so it stays in memory.
 */
final class InMemoryBenchStorage implements StoragePort
{
    /** @var array<string, string> */
    private array $bytesByScope = [];

    #[\Override]
    public function read(string $scope): ?string
    {
        return $this->bytesByScope[$scope] ?? null;
    }

    #[\Override]
    public function write(string $scope, string $bytes): void
    {
        $this->bytesByScope[$scope] = $bytes;
    }
}
