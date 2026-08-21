<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\QualityLedger\Internal\AbsentStatus;
use Rasuvaeff\QualityLedger\Internal\LedgerState;
use Rasuvaeff\QualityLedger\Internal\RunRecord;
use Rasuvaeff\QualityLedger\Internal\Transition;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The state machine underneath {@see \Rasuvaeff\QualityLedger\Ledger}, tested
 * directly rather than only through it: the transition-collapsing rule, the
 * as-of lookup, tombstone pruning and — the reason ids never leave this class
 * as raw array keys — the digit-only id coercion.
 */
#[Test]
#[Covers(LedgerState::class)]
final class LedgerStateTest
{
    public function anEmptyStateHasNothingInIt(): void
    {
        $state = LedgerState::empty();

        Assert::same($state->nextRunIndex, 0);
        Assert::same($state->runs, []);
        Assert::same($state->ids(), []);
        Assert::same($state->activeIds(), []);
        Assert::null($state->lastTransition('missing'));
        Assert::null($state->transitionAt('missing', 0));
        Assert::same($state->transitionsFor('missing'), []);
        Assert::false($state->hasRun('r1'));
        Assert::null($state->runIndexOf('r1'));
    }

    /**
     * `$a['42']` is `$a[42]` in PHP — the key coerces on the way *in*, well
     * before any JSON is involved. Every id therefore leaves this class
     * through a `(string)` cast; without it a caller-supplied id function
     * returning `(string) crc32(...)` hands `int`s to `string` parameters and
     * the first `append()` dies with a `TypeError`.
     */
    public function aDigitOnlyIdComesBackAsAString(): void
    {
        $state = LedgerState::empty()->withObservations([$this->transition('42', 'bad', 0)]);

        Assert::same($state->ids(), ['42']);
        Assert::same($state->activeIds(), ['42']);
        Assert::same(\count($state->transitionsFor('42')), 1);
    }

    public function anObservationWithTheSameStatusAsTheLastOneIsCollapsed(): void
    {
        $state = LedgerState::empty()->withObservations([
            $this->transition('a', 'bad', 0),
            $this->transition('a', 'bad', 1),
        ]);

        Assert::same(\count($state->transitionsFor('a')), 1);
    }

    public function aChangedStatusStartsANewTransition(): void
    {
        $state = LedgerState::empty()->withObservations([
            $this->transition('a', 'bad', 0),
            $this->transition('a', 'good', 1),
        ]);

        Assert::same(\count($state->transitionsFor('a')), 2);
    }

    /**
     * A batch that collapses entirely must not allocate a new state: the
     * "nothing changed" answer is the same object, which is what makes
     * appending an unchanged run cheap rather than a full table copy.
     */
    public function aBatchThatChangesNothingReturnsTheSameState(): void
    {
        $state = LedgerState::empty()->withObservations([$this->transition('a', 'bad', 0)]);

        Assert::same($state->withObservations([$this->transition('a', 'bad', 1)]), $state);
        Assert::same($state->withObservations([]), $state);
    }

    public function anIdWhoseLastTransitionIsAbsentIsNotActive(): void
    {
        $state = LedgerState::empty()->withObservations([
            $this->transition('a', 'bad', 0),
            $this->transition('a', AbsentStatus::VALUE, 1),
            $this->transition('b', 'good', 1),
            $this->transition('c', 'good', 1),
        ]);

        Assert::same($state->activeIds(), ['b', 'c']);
        Assert::same($state->ids(), ['a', 'b', 'c']);
    }

    /**
     * A batch is applied whole. Collapsing one observation must not abandon
     * the rest of the run — with a `break` in place of the `continue`, an
     * unchanged id early in the batch silently swallowed every id behind it.
     */
    public function anObservationThatCollapsesDoesNotAbandonTheRestOfTheBatch(): void
    {
        $state = LedgerState::empty()
            ->withObservations([$this->transition('a', 'bad', 0)])
            ->withObservations([
                $this->transition('a', 'bad', 1),
                $this->transition('b', 'good', 1),
                $this->transition('c', 'bad', 1),
            ]);

        Assert::same($state->ids(), ['a', 'b', 'c']);
        Assert::same(\count($state->transitionsFor('a')), 1);
    }

    /**
     * A decoded file may legitimately carry an id with no transitions at all
     * (nothing downstream needs one), so every walk over the id table has to
     * survive it — pruning included, where the "is there a last transition"
     * check is the only thing standing between it and a null dereference.
     */
    public function anIdWithNoTransitionsIsInertRatherThanFatal(): void
    {
        $state = LedgerState::fromParts(1, [new RunRecord(runIndex: 0, run: 'r0', ts: 0, metrics: [])], ['a' => []]);

        Assert::same($state->ids(), ['a']);
        Assert::same($state->activeIds(), []);
        Assert::null($state->lastTransition('a'));
        Assert::same($state->withTombstonesPruned(0)->ids(), ['a']);
    }

    public function theTransitionInEffectIsTheLastOneAtOrBeforeTheIndex(): void
    {
        $state = LedgerState::empty()->withObservations([
            $this->transition('a', 'bad', 0),
            $this->transition('a', 'good', 2),
        ]);

        Assert::null($state->transitionAt('a', -1));
        Assert::same($state->transitionAt('a', 0)?->status, 'bad');
        Assert::same($state->transitionAt('a', 1)?->status, 'bad');
        Assert::same($state->transitionAt('a', 2)?->status, 'good');
        Assert::same($state->transitionAt('a', 99)?->status, 'good');
    }

    public function lastTransitionIsTheMostRecentOneRegardlessOfIndex(): void
    {
        $state = LedgerState::empty()->withObservations([
            $this->transition('a', 'bad', 0),
            $this->transition('a', 'good', 5),
        ]);

        Assert::same($state->lastTransition('a')?->status, 'good');
    }

    public function recordingARunAdvancesTheCounterAndIsFindableByName(): void
    {
        $state = LedgerState::empty()->withRecordedRun(new RunRecord(runIndex: 0, run: 'r1', ts: 10, metrics: []));

        Assert::same($state->nextRunIndex, 1);
        Assert::true($state->hasRun('r1'));
        Assert::false($state->hasRun('r2'));
        Assert::same($state->runIndexOf('r1'), 0);
        Assert::null($state->runIndexOf('r2'));
    }

    /**
     * Recording a run appends to the history rather than replacing it — the
     * whole `trend()` feature is this list, so it is asserted element by
     * element instead of by length.
     */
    public function recordedRunsAccumulateInOrder(): void
    {
        $state = LedgerState::empty()
            ->withRecordedRun(new RunRecord(runIndex: 0, run: 'r1', ts: 10, metrics: ['msi' => 90.0]))
            ->withRecordedRun(new RunRecord(runIndex: 1, run: 'r2', ts: 20, metrics: ['msi' => 95.0]))
            ->withRecordedRun(new RunRecord(runIndex: 2, run: 'r3', ts: 30, metrics: []));

        Assert::same(array_map(static fn(RunRecord $run): string => $run->run, $state->runs), ['r1', 'r2', 'r3']);
        Assert::same($state->nextRunIndex, 3);
        Assert::same($state->runIndexOf('r1'), 0);
        Assert::same($state->runIndexOf('r3'), 2);
        Assert::same($state->runs[1]->metrics, ['msi' => 95.0]);
    }

    /**
     * The pruning boundary, stated as the test rather than left to a comment:
     * a tombstone recorded at run index 0 in a ledger whose last run is index
     * 2 has aged two runs, so a TTL of 2 keeps it and a TTL of 1 drops it.
     */
    public function aTombstoneIsDroppedOnlyOnceItIsOlderThanTheTtl(): void
    {
        $state = LedgerState::empty()
            ->withObservations([$this->transition('a', 'bad', 0), $this->transition('a', AbsentStatus::VALUE, 0)])
            ->withRecordedRun(new RunRecord(runIndex: 0, run: 'r1', ts: 0, metrics: []))
            ->withRecordedRun(new RunRecord(runIndex: 1, run: 'r2', ts: 0, metrics: []))
            ->withRecordedRun(new RunRecord(runIndex: 2, run: 'r3', ts: 0, metrics: []));

        Assert::same($state->withTombstonesPruned(2)->ids(), ['a']);
        Assert::same($state->withTombstonesPruned(1)->ids(), []);
    }

    /**
     * The same boundary with the tombstone somewhere other than run 0 — at
     * index 0 the age arithmetic is symmetric and a sign error in it cannot
     * show up at all.
     */
    public function theTombstoneBoundaryIsMeasuredFromWhereTheTombstoneActuallyIs(): void
    {
        $state = LedgerState::empty()
            ->withObservations([$this->transition('a', 'bad', 0), $this->transition('a', AbsentStatus::VALUE, 3)])
            ->withRecordedRun(new RunRecord(runIndex: 0, run: 'r0', ts: 0, metrics: []))
            ->withRecordedRun(new RunRecord(runIndex: 1, run: 'r1', ts: 0, metrics: []))
            ->withRecordedRun(new RunRecord(runIndex: 2, run: 'r2', ts: 0, metrics: []))
            ->withRecordedRun(new RunRecord(runIndex: 3, run: 'r3', ts: 0, metrics: []))
            ->withRecordedRun(new RunRecord(runIndex: 4, run: 'r4', ts: 0, metrics: []));

        Assert::same($state->withTombstonesPruned(1)->ids(), ['a']);
        Assert::same($state->withTombstonesPruned(0)->ids(), []);
    }

    /**
     * An id that is still being observed is never pruned however old its last
     * status change is — retention ages *tombstones*, not history. The three
     * recorded runs matter: with only one, the age arithmetic yields zero and
     * a policy that ignored the absent check entirely would still keep the id.
     */
    public function aLiveIdIsNeverPrunedHoweverOldItIs(): void
    {
        $state = LedgerState::empty()
            ->withObservations([$this->transition('a', 'bad', 0)])
            ->withRecordedRun(new RunRecord(runIndex: 0, run: 'r1', ts: 0, metrics: []))
            ->withRecordedRun(new RunRecord(runIndex: 1, run: 'r2', ts: 0, metrics: []))
            ->withRecordedRun(new RunRecord(runIndex: 2, run: 'r3', ts: 0, metrics: []));

        Assert::same($state->withTombstonesPruned(0)->ids(), ['a']);
    }

    /**
     * Pruning examines every id, not just up to the first one it keeps.
     */
    public function pruningKeepsGoingPastAnIdItDoesNotPrune(): void
    {
        $state = LedgerState::empty()
            ->withObservations([
                $this->transition('live', 'bad', 0),
                $this->transition('dead', 'bad', 0),
                $this->transition('dead', AbsentStatus::VALUE, 0),
            ])
            ->withRecordedRun(new RunRecord(runIndex: 0, run: 'r1', ts: 0, metrics: []))
            ->withRecordedRun(new RunRecord(runIndex: 1, run: 'r2', ts: 0, metrics: []))
            ->withRecordedRun(new RunRecord(runIndex: 2, run: 'r3', ts: 0, metrics: []));

        Assert::same($state->withTombstonesPruned(0)->ids(), ['live']);
    }

    /**
     * The immutability the class docblock claims, checked instead of asserted:
     * no `with*()` call may be observable on the receiver.
     *
     * @param list<array{0: string, 1: string}> $observations Each pair is [id, status].
     */
    #[Property(runs: 200, timeoutMs: 1_000)]
    public function everyWithCallLeavesTheReceiverUntouched(array $observations): void
    {
        Classify::cover($observations === [], 'no observations', 5.0);
        Classify::cover(\count($observations) > 3, 'more than three observations', 25.0);

        $state = LedgerState::empty();
        $ids = $state->ids();
        $runs = $state->runs;

        $transitions = [];

        foreach ($observations as $index => [$id, $status]) {
            $transitions[] = $this->transition($id, $status, $index);
        }

        $next = $state->withObservations($transitions);
        $next->withRecordedRun(new RunRecord(runIndex: 0, run: 'r1', ts: 0, metrics: []));
        $next->withTombstonesPruned(0);

        Assert::same($state->ids(), $ids);
        Assert::same($state->runs, $runs);
        Assert::same($state->nextRunIndex, 0);

        foreach ($next->ids() as $id) {
            Assert::same($state->transitionsFor($id), []);
        }
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function everyWithCallLeavesTheReceiverUntouchedGenerators(): array
    {
        return [
            'observations' => Gen::arrayOf(
                Gen::tuple(Gen::stringFrom('ab01', 1, 2), Gen::elements(['good', 'bad', AbsentStatus::VALUE])),
                minSize: 0,
                maxSize: 8,
            ),
        ];
    }

    /**
     * @return iterable<string, array{0: list<array{0: string, 1: string}>}>
     */
    public static function everyWithCallLeavesTheReceiverUntouchedExamples(): iterable
    {
        yield 'nothing observed' => [[]];
        yield 'a digit-only id' => [[['0', 'bad']]];
        yield 'the same id twice with the same status' => [[['a', 'bad'], ['a', 'bad']]];
        yield 'an id that goes absent' => [[['a', 'bad'], ['a', AbsentStatus::VALUE]]];
    }

    private function transition(string $id, string $status, int $runIndex): Transition
    {
        return new Transition(
            id: $id,
            kind: 'thing',
            runIndex: $runIndex,
            run: 'r' . $runIndex,
            ts: $runIndex,
            status: $status,
            meta: [],
        );
    }
}
