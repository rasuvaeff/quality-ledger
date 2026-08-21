<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\DefaultStableId;
use Rasuvaeff\QualityLedger\DiffEntry;
use Rasuvaeff\QualityLedger\Ledger;
use Rasuvaeff\QualityLedger\RatchetGate;
use Rasuvaeff\QualityLedger\RetentionPolicy;
use Rasuvaeff\QualityLedger\RunReport;
use Rasuvaeff\QualityLedger\Tests\Support\EmptyId;
use Rasuvaeff\QualityLedger\Tests\Support\InMemoryStorage;
use Rasuvaeff\QualityLedger\Tests\Support\SignatureId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Ledger::class)]
final class LedgerTest
{
    private const string SCOPE = 'acme/widgets';

    private InMemoryStorage $storage;
    private Ledger $ledger;

    /**
     * @var \Closure(string): bool
     */
    private \Closure $isBad;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->ledger = new Ledger(new DefaultStableId(), $this->storage);
        $this->isBad = static fn(string $status): bool => $status === 'bad';
    }

    private function datum(string $signature, string $status, array $meta = []): Datum
    {
        return new Datum(kind: 'thing', signature: $signature, status: $status, meta: $meta);
    }

    private function report(string $run, array $data, array $metrics = []): RunReport
    {
        return new RunReport(run: $run, ts: 1000, scope: self::SCOPE, data: $data, metrics: $metrics);
    }

    public function comparingARunToItselfHasNoNewBadOrFixed(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'good'), $this->datum('y', 'bad')]));

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r1', $this->isBad);

        Assert::same($diff->newBad, []);
        Assert::same($diff->fixed, []);
        Assert::same(\count($diff->stillBad), 1);
        Assert::same($diff->unchangedCount, 1);
        // It just became bad in this very run — 0 runs have passed since.
        Assert::same($diff->stillBad[0]->ageRuns, 0);
    }

    public function appendingTheSameRunTwiceIsANoOp(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'good')]));
        $bytesAfterFirst = $this->storage->read(self::SCOPE);

        $this->ledger->append($this->report('r1', [$this->datum('x', 'bad')]));
        $bytesAfterSecond = $this->storage->read(self::SCOPE);

        Assert::same($bytesAfterSecond, $bytesAfterFirst);
    }

    public function anIdThatTurnsBadIsNewBad(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'good')]));
        $this->ledger->append($this->report('r2', [$this->datum('x', 'bad')]));

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r2', $this->isBad);

        Assert::same(\count($diff->newBad), 1);
        Assert::same($diff->newBad[0]->id, (new DefaultStableId())->id($this->datum('x', 'good')));
    }

    public function anIdNeverSeenBeforeBaseAndBadAtHeadIsNewBad(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'good')]));
        $this->ledger->append($this->report('r2', [$this->datum('x', 'good'), $this->datum('y', 'bad')]));

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r2', $this->isBad);

        Assert::same(\count($diff->newBad), 1);
    }

    /**
     * An id whose only observations are strictly after `$head` is not part of
     * the comparison at all — `transitionAt()` correctly returns `null` for
     * it at both `$baseIndex` and `$headIndex`, and `diff()` must not treat
     * that `null` as "absent, therefore not bad" by dereferencing it.
     */
    public function anIdFirstObservedAfterHeadIsNotPartOfTheComparison(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'good')]));
        $this->ledger->append($this->report('r2', [$this->datum('x', 'good')])); // head
        $this->ledger->append($this->report('r3', [$this->datum('x', 'good'), $this->datum('y', 'bad')])); // y appears only here

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r2', $this->isBad);

        Assert::same($diff->totalIdsCompared(), 1);
    }

    /**
     * When an id exists at both base and head, the reported `meta` comes
     * from head — the more recent, more relevant observation for whoever
     * reads a worklist built from the diff. A status change is needed to
     * produce a second transition at all: two data with the *same* status
     * but different meta are, deliberately, a single unchanged observation
     * (see {@see \Rasuvaeff\QualityLedger\Internal\LedgerState::withObservation()}) —
     * so this test uses two distinct bad-classified statuses to get one.
     */
    public function metaOnAnIdPresentAtBothPointsComesFromHead(): void
    {
        $isEitherBad = static fn(string $status): bool => $status === 'escaped' || $status === 'timeout';

        $this->ledger->append($this->report('r1', [$this->datum('x', 'escaped', ['killer' => 'old'])]));
        $this->ledger->append($this->report('r2', [$this->datum('x', 'timeout', ['killer' => 'new'])]));

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r2', $isEitherBad);

        Assert::same(\count($diff->stillBad), 1);
        Assert::same($diff->stillBad[0]->meta, ['killer' => 'new']);
    }

    /**
     * A gap of good status between two bad episodes must stop the backward
     * walk at the more recent episode — walking past the gap would wrongly
     * attribute the age to the older, unrelated episode.
     */
    public function ageRunsStopsAtAGoodGapInsteadOfWalkingPastIt(): void
    {
        $this->ledger->append($this->report('r0', [$this->datum('x', 'bad')])); // an older, unrelated bad episode
        $this->ledger->append($this->report('r1', [$this->datum('x', 'good')])); // fixed
        $this->ledger->append($this->report('r2', [$this->datum('x', 'bad')])); // bad again — base
        $this->ledger->append($this->report('r3', [$this->datum('x', 'bad')])); // head

        $diff = $this->ledger->diff(self::SCOPE, 'r2', 'r3', $this->isBad);

        Assert::same(\count($diff->stillBad), 1);
        Assert::same($diff->stillBad[0]->ageRuns, 1);
    }

    /**
     * An id that is bad from the very first run it was ever observed in has
     * exactly one transition. The backward walk must still examine that
     * single entry rather than skip straight past it.
     */
    public function ageRunsHandlesAnIdWithExactlyOneTransition(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'bad')])); // first ever appearance — base
        $this->ledger->append($this->report('r2', [$this->datum('x', 'bad')]));
        $this->ledger->append($this->report('r3', [$this->datum('x', 'bad')])); // head

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r3', $this->isBad);

        Assert::same(\count($diff->stillBad), 1);
        Assert::same($diff->stillBad[0]->ageRuns, 2);
    }

    public function anIdThatTurnsGoodIsFixed(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'bad')]));
        $this->ledger->append($this->report('r2', [$this->datum('x', 'good')]));

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r2', $this->isBad);

        Assert::same(\count($diff->fixed), 1);
    }

    public function anIdRemovedFromTheCodeCountsAsFixedNotStillBad(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'bad')]));
        $this->ledger->append($this->report('r2', [])); // x is gone — the line it lived on was refactored away

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r2', $this->isBad);

        Assert::same(\count($diff->fixed), 1);
        Assert::same($diff->stillBad, []);
    }

    public function ageRunsCountsSinceItMostRecentlyBecameBad(): void
    {
        $this->ledger->append($this->report('r0', [$this->datum('x', 'good')]));
        $this->ledger->append($this->report('r1', [$this->datum('x', 'bad')])); // became bad here — this is base
        $this->ledger->append($this->report('r2', [$this->datum('x', 'bad')]));
        $this->ledger->append($this->report('r3', [$this->datum('x', 'bad')])); // head

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r3', $this->isBad);

        Assert::same(\count($diff->stillBad), 1);
        Assert::same($diff->stillBad[0]->ageRuns, 2); // r1 -> r3 is 2 runs later
    }

    public function anAlwaysGoodIdIsUnchanged(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'good')]));
        $this->ledger->append($this->report('r2', [$this->datum('x', 'good')]));

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r2', $this->isBad);

        Assert::same($diff->unchangedCount, 1);
        Assert::same($diff->totalIdsCompared(), 1);
    }

    public function duplicateIdWithinOneRunIsRejected(): void
    {
        try {
            $this->ledger->append($this->report('r1', [$this->datum('x', 'good'), $this->datum('x', 'bad')]));

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Duplicate id');
            Assert::string($e->getMessage())->contains('within run "r1"');
        }
    }

    /**
     * The message names both kinds, because the realistic cause of a collision
     * is two analyzers sharing an id function — and "which two" is the first
     * thing the reader needs.
     */
    public function aDuplicateIdAcrossTwoKindsNamesBothOfThem(): void
    {
        $ledger = new Ledger(new SignatureId(), $this->storage);

        try {
            $ledger->append($this->report('r1', [
                new Datum(kind: 'mutant', signature: 'shared', status: 'good'),
                new Datum(kind: 'flaky', signature: 'shared', status: 'bad'),
            ]));

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('first seen as kind "mutant"');
            Assert::string($e->getMessage())->contains('now as kind "flaky"');
        }
    }

    public function diffingAnUnknownRunIsRejected(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'good')]));

        try {
            $this->ledger->diff(self::SCOPE, 'r1', 'nope', $this->isBad);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('nope');
        }
    }

    public function trendSkipsRunsMissingTheMetric(): void
    {
        $this->ledger->append($this->report('r1', [], ['msi' => 90.0]));
        $this->ledger->append($this->report('r2', [], []));
        $this->ledger->append($this->report('r3', [], ['msi' => 95.0]));

        $trend = $this->ledger->trend(self::SCOPE, 'msi');

        Assert::same(\count($trend->points), 2);
        Assert::same($trend->points[0]->value, 90.0);
        Assert::same($trend->points[1]->value, 95.0);
    }

    public function trendWindowKeepsOnlyTheMostRecentRuns(): void
    {
        $this->ledger->append($this->report('r1', [], ['msi' => 1]));
        $this->ledger->append($this->report('r2', [], ['msi' => 2]));
        $this->ledger->append($this->report('r3', [], ['msi' => 3]));

        $trend = $this->ledger->trend(self::SCOPE, 'msi', window: 2);

        Assert::same(\count($trend->points), 2);
        Assert::same($trend->points[0]->value, 2);
        Assert::same($trend->points[1]->value, 3);
    }

    public function aZeroWindowYieldsAnEmptyTrendRatherThanNoLimit(): void
    {
        $this->ledger->append($this->report('r1', [], ['msi' => 1]));

        $trend = $this->ledger->trend(self::SCOPE, 'msi', window: 0);

        Assert::same($trend->points, []);
    }

    public function aNegativeWindowIsRejected(): void
    {
        $this->ledger->append($this->report('r1', [], ['msi' => 1]));

        try {
            $this->ledger->trend(self::SCOPE, 'msi', window: -1);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('negative');
        }
    }

    public function ratchetGateFailsOnlyOnNewBad(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'bad'), $this->datum('y', 'good')]));
        $this->ledger->append($this->report('r2', [$this->datum('x', 'bad'), $this->datum('y', 'bad')]));

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r2', $this->isBad);
        $result = (new RatchetGate())->evaluate($diff);

        Assert::false($result->ok);
        Assert::same(\count($result->regressions), 1);
    }

    public function ratchetGatePassesWhenOnlyStillBadDebtRemains(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'bad')]));
        $this->ledger->append($this->report('r2', [$this->datum('x', 'bad')]));

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r2', $this->isBad);
        $result = (new RatchetGate())->evaluate($diff);

        Assert::true($result->ok);
    }

    /**
     * With the ttl exhausted, a disappeared id's history is dropped entirely
     * (not just marked absent) — the ledger does not grow one dead row per
     * mutant/test ever removed from a churning codebase. Reintroducing the
     * same id afterwards is indistinguishable from a brand-new one: it is
     * classified against base as if never seen there, not as `stillBad`
     * carried through a gap.
     */
    public function aTombstonedIdForgetsItsHistoryAndReappearsAsNewBad(): void
    {
        $ledger = new Ledger(new DefaultStableId(), $this->storage, new RetentionPolicy(tombstoneTtlRuns: 0));

        $ledger->append($this->report('r1', [$this->datum('x', 'bad')]));
        $ledger->append($this->report('r2', [])); // x disappears
        $ledger->append($this->report('r3', [])); // one full run has now passed since — pruned
        $ledger->append($this->report('r4', [$this->datum('x', 'bad')])); // x reappears

        $diff = $ledger->diff(self::SCOPE, 'r1', 'r4', $this->isBad);

        Assert::same(\count($diff->newBad), 1);
        Assert::same($diff->stillBad, []);
    }

    /**
     * The bug this property could not previously reach, pinned as a plain
     * test as well: an id that went absent *before* base still had an
     * `AbsentStatus` transition in effect at both points, so it was neither
     * bad nor not-bad — and fell through into `unchangedCount`. With the
     * default 50-run tombstone TTL that silently inflated the denominator by
     * up to fifty runs' worth of deleted mutants.
     */
    public function anIdThatWentAbsentBeforeBothPointsIsNotPartOfTheComparison(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('gone', 'good'), $this->datum('live', 'good')]));
        $this->ledger->append($this->report('r2', [$this->datum('live', 'good')])); // "gone" disappears
        $this->ledger->append($this->report('r3', [$this->datum('live', 'good')]));
        $this->ledger->append($this->report('r4', [$this->datum('live', 'good')]));

        $diff = $this->ledger->diff(self::SCOPE, 'r3', 'r4', $this->isBad);

        Assert::same($diff->unchangedCount, 1);
        Assert::same($diff->totalIdsCompared(), 1);
    }

    /**
     * An id removed between base and head is still `fixed` — only absence at
     * *both* points leaves the comparison. Guards the fix above from being
     * over-applied.
     */
    public function anIdThatIsBadAtBaseAndGoneAtHeadIsStillCountedAsFixed(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'bad')]));
        $this->ledger->append($this->report('r2', []));

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r2', $this->isBad);

        Assert::same(\count($diff->fixed), 1);
        Assert::same($diff->fixed[0]->id, (new DefaultStableId())->id($this->datum('x', 'bad')));
        Assert::same($diff->totalIdsCompared(), 1);
    }

    /**
     * A removed id's `meta` now comes from base rather than from the
     * bookkeeping `AbsentStatus` transition, which carries none — the
     * documented rule ("as of head when present there, otherwise as of base")
     * used to be false for exactly this case.
     */
    public function aRemovedIdCarriesTheMetaItHadAtBase(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'bad', ['file' => 'src/X.php'])]));
        $this->ledger->append($this->report('r2', []));

        $diff = $this->ledger->diff(self::SCOPE, 'r1', 'r2', $this->isBad);

        Assert::same($diff->fixed[0]->meta, ['file' => 'src/X.php']);
    }

    /**
     * A digit-only id is inside what {@see \Rasuvaeff\QualityLedger\StableIdInterface}
     * promises — any non-empty string — and a custom id function is the
     * package's headline extension point. PHP coerces such a key to `int` on
     * insertion, which used to make the very first `append()` die with a
     * `TypeError` and leave the scope unusable.
     */
    public function aDigitOnlyIdSurvivesAppendDiffAndTrend(): void
    {
        $ledger = new Ledger(new SignatureId(), $this->storage);

        $ledger->append(new RunReport(run: 'r1', ts: 1, scope: self::SCOPE, data: [$this->datum('42', 'good'), $this->datum('7', 'bad')], metrics: ['msi' => 90.0]));
        $ledger->append(new RunReport(run: 'r2', ts: 2, scope: self::SCOPE, data: [$this->datum('42', 'bad')], metrics: ['msi' => 80.0]));

        $diff = $ledger->diff(self::SCOPE, 'r1', 'r2', $this->isBad);

        Assert::same(array_map(static fn(DiffEntry $entry): string => $entry->id, $diff->newBad), ['42']);
        Assert::same(array_map(static fn(DiffEntry $entry): string => $entry->id, $diff->fixed), ['7']);
        Assert::same(\count($ledger->trend(self::SCOPE, 'msi')->points), 2);
    }

    /**
     * The other half of the same contract: an id function that returns an
     * empty string violates {@see \Rasuvaeff\QualityLedger\StableIdInterface}
     * and is refused where it happens, rather than silently creating an
     * unaddressable history entry.
     */
    public function anEmptyIdFromTheIdFunctionIsRejected(): void
    {
        $ledger = new Ledger(new EmptyId(), $this->storage);

        try {
            $ledger->append($this->report('r1', [$this->datum('x', 'good')]));

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('must not be empty');
        }
    }

    /**
     * `diff()` is directional and does not sort its arguments — passing the
     * newer run as base reports a regression as a fix. Documented on the
     * method and asserted here so the behaviour stays deliberate.
     */
    public function diffIsDirectionalAndReportsATransposedComparisonWhenGivenOne(): void
    {
        $this->ledger->append($this->report('r1', [$this->datum('x', 'good')]));
        $this->ledger->append($this->report('r2', [$this->datum('x', 'bad')]));

        Assert::same(\count($this->ledger->diff(self::SCOPE, 'r1', 'r2', $this->isBad)->newBad), 1);
        Assert::same(\count($this->ledger->diff(self::SCOPE, 'r2', 'r1', $this->isBad)->fixed), 1);
    }

    /**
     * Every id in the ledger falls into exactly one bucket, or into none at
     * all — checked against an independent reference classification built
     * straight from the statuses each synthetic id was generated with, not
     * from {@see Ledger}'s own logic.
     *
     * Three runs, not two: with only base and head there is no way to build
     * an id that exists in the ledger yet is absent at *both* points, which is
     * precisely the shape that used to be miscounted as `unchanged`.
     *
     * @param list<array{0: ?string, 1: ?string, 2: ?string}> $ids Each triple is the status at r1/base/head, 'g'|'b'|null (not observed in that run).
     */
    #[Property(runs: 300, timeoutMs: 2_000)]
    public function diffPartitionsEveryObservedIdExactlyOnce(array $ids): void
    {
        $runs = [[], [], []];
        $expected = ['newBad' => 0, 'fixed' => 0, 'stillBad' => 0, 'unchanged' => 0, 'ignored' => 0];

        foreach ($ids as $index => $statuses) {
            $signature = 'id-' . $index;

            foreach ($statuses as $run => $status) {
                if ($status !== null) {
                    $runs[$run][] = $this->datum($signature, $status === 'b' ? 'bad' : 'good');
                }
            }

            [, $atBase, $atHead] = $statuses;

            if ($atBase === null && $atHead === null) {
                ++$expected['ignored'];

                continue;
            }

            $baseBad = $atBase === 'b';
            $headBad = $atHead === 'b';

            $expected[match (true) {
                !$baseBad && $headBad => 'newBad',
                $baseBad && !$headBad => 'fixed',
                $baseBad && $headBad => 'stillBad',
                default => 'unchanged',
            }]++;
        }

        Classify::cover($expected['newBad'] > 0, 'a new regression', 25.0);
        Classify::cover($expected['fixed'] > 0, 'something fixed', 25.0);
        Classify::cover($expected['stillBad'] > 0, 'existing debt', 20.0);
        Classify::cover($expected['unchanged'] > 0, 'unchanged ids', 25.0);
        Classify::cover($expected['ignored'] > 0, 'ids outside the comparison', 25.0);
        Classify::when($ids === [], 'nothing observed at all');

        $storage = new InMemoryStorage();
        $ledger = new Ledger(new DefaultStableId(), $storage);
        $ledger->append(new RunReport(run: 'first', ts: 0, scope: self::SCOPE, data: $runs[0]));
        $ledger->append(new RunReport(run: 'base', ts: 1, scope: self::SCOPE, data: $runs[1]));
        $ledger->append(new RunReport(run: 'head', ts: 2, scope: self::SCOPE, data: $runs[2]));

        $diff = $ledger->diff(self::SCOPE, 'base', 'head', $this->isBad);

        Assert::same(\count($diff->newBad), $expected['newBad']);
        Assert::same(\count($diff->fixed), $expected['fixed']);
        Assert::same(\count($diff->stillBad), $expected['stillBad']);
        Assert::same($diff->unchangedCount, $expected['unchanged']);
        Assert::same($diff->totalIdsCompared(), \count($ids) - $expected['ignored']);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function diffPartitionsEveryObservedIdExactlyOnceGenerators(): array
    {
        $status = Gen::nullable(Gen::elements(['g', 'b']));

        return [
            'ids' => Gen::arrayOf(Gen::tuple($status, $status, $status), minSize: 0, maxSize: 12),
        ];
    }

    /**
     * The shapes that random generation reaches only occasionally, and the one
     * it reached constantly while still proving nothing (the miscounted
     * absent-at-both id).
     *
     * @return iterable<string, array{0: list<array{0: ?string, 1: ?string, 2: ?string}>}>
     */
    public static function diffPartitionsEveryObservedIdExactlyOnceExamples(): iterable
    {
        yield 'no ids at all' => [[]];
        yield 'observed only before base' => [[['g', null, null]]];
        yield 'bad only before base' => [[['b', null, null]]];
        yield 'one of every bucket' => [[['g', 'g', 'b'], ['g', 'b', 'g'], ['b', 'b', 'b'], ['g', 'g', 'g'], ['g', null, null]]];
        yield 'first seen at head' => [[[null, null, 'b']]];
        yield 'gone at head' => [[['b', 'b', null]]];
        yield 'absent at base but back at head' => [[['b', null, 'b']]];
    }
}
