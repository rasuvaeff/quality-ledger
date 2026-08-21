<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\QualityLedger\DiffEntry;
use Rasuvaeff\QualityLedger\DiffReport;
use Rasuvaeff\QualityLedger\RatchetGate;
use Rasuvaeff\QualityLedger\StillBadEntry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The ratchet's entire decision logic is two expressions, and until this class
 * existed no mutant of either was ever generated — the whole point of the
 * package over a threshold gate rested on code the gate could not see.
 */
#[Test]
#[Covers(RatchetGate::class)]
final class RatchetGateTest
{
    private RatchetGate $gate;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->gate = new RatchetGate();
    }

    public function anEmptyDiffPasses(): void
    {
        $result = $this->gate->evaluate($this->report(newBad: [], stillBad: []));

        Assert::true($result->ok);
        Assert::same($result->regressions, []);
    }

    public function aNewRegressionFails(): void
    {
        $entry = new DiffEntry('a', 'thing', ['file' => 'src/X.php']);

        $result = $this->gate->evaluate($this->report(newBad: [$entry], stillBad: []));

        Assert::false($result->ok);
        Assert::same($result->regressions, [$entry]);
    }

    /**
     * The reason the package exists: pre-existing debt does not block a PR
     * that did not introduce it, however much of it there is.
     */
    public function existingDebtAloneNeverFails(): void
    {
        $result = $this->gate->evaluate($this->report(
            newBad: [],
            stillBad: [
                new StillBadEntry('a', 'thing', [], 7),
                new StillBadEntry('b', 'thing', [], 120),
            ],
        ));

        Assert::true($result->ok);
        Assert::same($result->regressions, []);
    }

    public function debtAlongsideARegressionStillFailsOnTheRegressionOnly(): void
    {
        $entry = new DiffEntry('new', 'thing', []);

        $result = $this->gate->evaluate($this->report(
            newBad: [$entry],
            stillBad: [new StillBadEntry('old', 'thing', [], 3)],
        ));

        Assert::false($result->ok);
        Assert::same($result->regressions, [$entry]);
    }

    /**
     * `ok` is exactly `newBad === []` and `regressions` is exactly `newBad` —
     * no filtering, no reordering, no dependence on the other buckets. Stated
     * as a property because the gate's value is that it is unconditional.
     *
     * @param list<string> $newBadIds
     * @param list<string> $stillBadIds
     */
    #[Property(runs: 200, timeoutMs: 1_000)]
    public function theGateFailsExactlyWhenThereIsANewRegression(array $newBadIds, array $stillBadIds): void
    {
        Classify::cover($newBadIds === [], 'no regressions', 10.0);
        Classify::cover($newBadIds !== [], 'at least one regression', 40.0);
        Classify::when($stillBadIds !== [], 'debt present');

        $newBad = array_map(static fn(string $id): DiffEntry => new DiffEntry($id, 'thing', []), $newBadIds);
        $stillBad = array_map(static fn(string $id): StillBadEntry => new StillBadEntry($id, 'thing', [], 1), $stillBadIds);

        $result = $this->gate->evaluate($this->report(newBad: $newBad, stillBad: $stillBad));

        Assert::same($result->ok, $newBadIds === []);
        Assert::same($result->regressions, $newBad);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theGateFailsExactlyWhenThereIsANewRegressionGenerators(): array
    {
        return [
            'newBadIds' => Gen::uniqueArrayOf(Gen::stringFrom('abcd', 1, 3), minSize: 0, maxSize: 5),
            'stillBadIds' => Gen::uniqueArrayOf(Gen::stringFrom('wxyz', 1, 3), minSize: 0, maxSize: 5),
        ];
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: list<string>}>
     */
    public static function theGateFailsExactlyWhenThereIsANewRegressionExamples(): iterable
    {
        yield 'clean' => [[], []];
        yield 'debt only' => [[], ['a', 'b']];
        yield 'one regression' => [['a'], []];
        yield 'a regression buried in debt' => [['a'], ['b', 'c', 'd']];
    }

    /**
     * @param list<DiffEntry> $newBad
     * @param list<StillBadEntry> $stillBad
     */
    private function report(array $newBad, array $stillBad): DiffReport
    {
        return new DiffReport('base', 'head', $newBad, [], $stillBad, 0);
    }
}
