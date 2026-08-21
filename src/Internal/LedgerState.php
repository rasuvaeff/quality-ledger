<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Internal;

/**
 * The whole ledger for one scope: every run recorded, and every id's
 * transition history. Immutable — every mutating operation in {@see \Rasuvaeff\QualityLedger\Ledger}
 * returns a new state built from `with*()` copies, the same discipline
 * `property-testing-core`'s `CorpusDocument` uses for exactly the same reason:
 * a half-applied mutation must never be observable.
 *
 * @internal
 */
final readonly class LedgerState
{
    /**
     * Bumped to 2 when `ids` became a list of `{id, transitions}` records
     * instead of an object keyed by id, and `kind` moved onto each transition.
     * A file written by the earlier layout still says `"v":1` and is refused
     * by version, which is what the field is for — without the bump it would
     * be refused by whichever structural check happened to notice first, and
     * the error would name a field rather than the format.
     */
    public const int FORMAT_VERSION = 2;

    /**
     * @param int<0, max> $nextRunIndex Monotonic counter — explicit rather than derived from `count($runs)`, so a future run-pruning feature does not have to renumber history to keep it correct.
     * @param list<RunRecord> $runs
     * @param array<array-key, list<Transition>> $transitionsById Ordered by `runIndex` ascending. Private, and the key type is `array-key` rather than `string`, because PHP silently stores a digit-only id (`"42"`) under an integer key — see {@see ids()}.
     */
    private function __construct(
        public int $nextRunIndex,
        public array $runs,
        private array $transitionsById,
    ) {}

    public static function empty(): self
    {
        return new self(nextRunIndex: 0, runs: [], transitionsById: []);
    }

    /**
     * @param int<0, max> $nextRunIndex
     * @param list<RunRecord> $runs
     * @param array<array-key, list<Transition>> $transitionsById
     */
    public static function fromParts(int $nextRunIndex, array $runs, array $transitionsById): self
    {
        return new self($nextRunIndex, $runs, $transitionsById);
    }

    public function hasRun(string $run): bool
    {
        foreach ($this->runs as $record) {
            if ($record->run === $run) {
                return true;
            }
        }

        return false;
    }

    public function runIndexOf(string $run): ?int
    {
        foreach ($this->runs as $record) {
            if ($record->run === $run) {
                return $record->runIndex;
            }
        }

        return null;
    }

    /**
     * Every id this state knows about, oldest first.
     *
     * The `(string)` cast is the whole reason ids are never read straight off
     * the array: PHP converts a digit-only string key to an integer the moment
     * it is written (`$a['42']` is `$a[42]`), so an id function returning
     * `(string) crc32(...)` — squarely inside what
     * {@see \Rasuvaeff\QualityLedger\StableIdInterface} promises — would
     * otherwise hand callers an `int` and break every `string`-typed signature
     * downstream. Casting here and in {@see activeIds()} keeps that leak inside
     * this one class.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = [];

        foreach (array_keys($this->transitionsById) as $id) {
            $ids[] = (string) $id;
        }

        return $ids;
    }

    /**
     * @return list<Transition>
     */
    public function transitionsFor(string $id): array
    {
        return $this->transitionsById[$id] ?? [];
    }

    /**
     * Every id whose most recent transition is not {@see AbsentStatus} — what
     * the next `append()` compares its incoming ids against to detect
     * disappearance.
     *
     * @return list<string>
     */
    public function activeIds(): array
    {
        $active = [];

        foreach ($this->transitionsById as $id => $transitions) {
            $last = $this->last($transitions);

            if ($last instanceof Transition && $last->status !== AbsentStatus::VALUE) {
                $active[] = (string) $id;
            }
        }

        return $active;
    }

    public function lastTransition(string $id): ?Transition
    {
        return $this->last($this->transitionsById[$id] ?? []);
    }

    /**
     * @param list<Transition> $transitions
     */
    private function last(array $transitions): ?Transition
    {
        $last = null;

        foreach ($transitions as $transition) {
            $last = $transition;
        }

        return $last;
    }

    /**
     * The transition in effect for `$id` at (i.e. current as of) `$runIndex`:
     * the last one recorded at or before it. `null` means the id had not been
     * observed yet at that point in history.
     *
     * The `break` is mutation-tested and confirmed equivalent to `continue`,
     * documented here rather than chased with a contrived test: a `continue`
     * would skip the assignment for exactly the same entries the `break` stops
     * at, because the list ascends by `runIndex` — appended in run order by
     * {@see withObservations()} and validated strictly ascending by
     * {@see Codec::decode()} on the way in from disk. Once one entry is past
     * `$runIndex`, every entry behind it is too.
     */
    public function transitionAt(string $id, int $runIndex): ?Transition
    {
        $result = null;

        foreach ($this->transitionsById[$id] ?? [] as $transition) {
            if ($transition->runIndex > $runIndex) {
                break;
            }

            $result = $transition;
        }

        return $result;
    }

    public function withRecordedRun(RunRecord $run): self
    {
        return new self($this->nextRunIndex + 1, [...$this->runs, $run], $this->transitionsById);
    }

    /**
     * Appends each transition only when its id has none yet or its status
     * differs from the last one — the mechanism that makes retention automatic
     * rather than a separate compaction pass.
     *
     * A whole run's observations are applied in one call rather than one state
     * copy per datum: copying the id table per observation is quadratic in the
     * number of ids, and a mutation run has tens of thousands of them. The
     * batch is still atomic — the copy is built to completion locally and only
     * then wrapped in a new state, so a half-applied run is never observable.
     *
     * @param list<Transition> $transitions
     */
    public function withObservations(array $transitions): self
    {
        $transitionsById = $this->transitionsById;
        $changed = false;

        foreach ($transitions as $transition) {
            $existing = $transitionsById[$transition->id] ?? [];
            $last = $this->last($existing);

            if ($last instanceof Transition && $last->status === $transition->status) {
                continue;
            }

            $transitionsById[$transition->id] = [...$existing, $transition];
            $changed = true;
        }

        return $changed ? new self($this->nextRunIndex, $this->runs, $transitionsById) : $this;
    }

    /**
     * Drops ids whose last transition is {@see AbsentStatus} and has stayed
     * that way for more than `$tombstoneTtlRuns` — otherwise a ledger that
     * tracks a churning codebase grows a dead id for every mutant/test that
     * ever existed and was later removed.
     */
    public function withTombstonesPruned(int $tombstoneTtlRuns): self
    {
        $transitionsById = $this->transitionsById;

        foreach ($transitionsById as $id => $transitions) {
            $last = $this->last($transitions);

            // Split from the age check rather than chained into one condition:
            // an id with no transitions at all (a decoded file may carry one)
            // makes `$last` null, and a single chained condition reads
            // `$last->status` on it the moment the operator changes. Two
            // statements make the null case structurally unreachable instead
            // of relying on `&&` ordering.
            if (!$last instanceof Transition || $last->status !== AbsentStatus::VALUE) {
                continue;
            }

            if (($this->nextRunIndex - 1 - $last->runIndex) > $tombstoneTtlRuns) {
                unset($transitionsById[$id]);
            }
        }

        return new self($this->nextRunIndex, $this->runs, $transitionsById);
    }
}
