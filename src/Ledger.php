<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

use Rasuvaeff\QualityLedger\Internal\AbsentStatus;
use Rasuvaeff\QualityLedger\Internal\Assert;
use Rasuvaeff\QualityLedger\Internal\Codec;
use Rasuvaeff\QualityLedger\Internal\LedgerState;
use Rasuvaeff\QualityLedger\Internal\RunRecord;
use Rasuvaeff\QualityLedger\Internal\Transition;

/**
 * The whole engine: append a run's observations, diff any two recorded runs,
 * read a metric's trend. Framework-agnostic — everything domain-specific
 * (what a mutant is, what "bad" means) is supplied by the caller through
 * {@see Datum}, {@see StableIdInterface} and the `$isBad` predicate passed to
 * {@see diff()}.
 *
 * Not concurrency-safe: {@see append()} is a read-modify-write over the whole
 * scope, and {@see StoragePort} has no compare-and-swap. Two processes
 * appending to one scope at once lose one of the two runs — see
 * {@see LocalFileStorage} and the README's Security section for the CI-shaped
 * version of that hazard.
 *
 * @api
 */
final readonly class Ledger
{
    public function __construct(
        private StableIdInterface $id,
        private StoragePort $storage,
        private RetentionPolicy $retention = new RetentionPolicy(),
    ) {}

    /**
     * Records one run. Appending the same `$report->run` twice is a no-op —
     * the second call changes nothing, so a CI retry that reruns the same
     * job cannot double-count a run or duplicate its transitions.
     *
     * An id that was active in the previous state but is absent from this
     * run's data is recorded as {@see AbsentStatus} rather than left at its
     * last known status or silently dropped — see that class for why.
     */
    public function append(RunReport $report): void
    {
        $state = $this->load($report->scope);

        if ($state->hasRun($report->run)) {
            return;
        }

        $runIndex = $state->nextRunIndex;
        /** @var array<array-key, non-empty-string> $seenIds Id => the kind it was first seen under. Keyed by id so the duplicate check is a hash lookup rather than a linear scan: a mutation run carries tens of thousands of ids, and in_array() over a growing list makes append() quadratic. */
        $seenIds = [];
        $observations = [];

        foreach ($report->data as $datum) {
            $id = $this->id->id($datum);

            Assert::nonEmpty($id, sprintf('The id StableIdInterface returned for kind "%s"', $datum->kind));

            $firstKind = $seenIds[$id] ?? null;

            if ($firstKind !== null) {
                throw new \InvalidArgumentException(sprintf(
                    'Duplicate id "%s" within run "%s" — first seen as kind "%s", now as kind "%s"; StableIdInterface must not collide two data in the same run',
                    $id,
                    $report->run,
                    $firstKind,
                    $datum->kind,
                ));
            }

            $seenIds[$id] = $datum->kind;

            $observations[] = new Transition(
                id: $id,
                kind: $datum->kind,
                runIndex: $runIndex,
                run: $report->run,
                ts: $report->ts,
                status: $datum->status,
                meta: $datum->meta,
            );
        }

        $state = $state->withObservations($observations);
        $absences = [];

        foreach ($state->activeIds() as $activeId) {
            if (isset($seenIds[$activeId])) {
                continue;
            }

            $last = $state->lastTransition($activeId);
            \assert($last instanceof Transition);

            $absences[] = new Transition(
                id: $activeId,
                kind: $last->kind,
                runIndex: $runIndex,
                run: $report->run,
                ts: $report->ts,
                status: AbsentStatus::VALUE,
                meta: [],
            );
        }

        $state = $state->withObservations($absences);
        $state = $state->withRecordedRun(new RunRecord(runIndex: $runIndex, run: $report->run, ts: $report->ts, metrics: $report->metrics));
        $state = $this->retention->apply($state);

        $this->save($report->scope, $state);
    }

    /**
     * Every id observed at `$base` and/or `$head`, partitioned by whether it
     * was "bad" at each point (`$isBad` classifies a domain status string —
     * the ledger has no opinion on what "bad" means).
     *
     * `$base` and `$head` are not sorted for you: the report is computed from
     * `$base` towards `$head`, so passing the newer run as `$base` reports a
     * regression as a fix and vice versa. Pass them in chronological order.
     *
     * @param non-empty-string $scope
     * @param non-empty-string $base
     * @param non-empty-string $head
     * @param \Closure(string): bool $isBad
     */
    public function diff(string $scope, string $base, string $head, \Closure $isBad): DiffReport
    {
        $state = $this->load($scope);

        $baseIndex = $state->runIndexOf($base);
        $headIndex = $state->runIndexOf($head);

        if ($baseIndex === null) {
            throw new \InvalidArgumentException(sprintf('Unknown run "%s" for scope "%s"', $base, $scope));
        }

        if ($headIndex === null) {
            throw new \InvalidArgumentException(sprintf('Unknown run "%s" for scope "%s"', $head, $scope));
        }

        $newBad = [];
        $fixed = [];
        $stillBad = [];
        $unchangedCount = 0;

        foreach ($state->ids() as $id) {
            $atBase = $this->presentAt($state, $id, $baseIndex);
            $atHead = $this->presentAt($state, $id, $headIndex);

            // `meta` and `kind` come from head when the id is present there,
            // otherwise from base — and a null reference means the id is part
            // of neither snapshot: it either first appears strictly after both
            // points, or it had already gone absent before both (a tombstone
            // still inside its retention window). Counting such an id would
            // inflate unchangedCount — and with it totalIdsCompared() — with
            // ids this comparison says nothing about.
            $reference = $atHead ?? $atBase;

            if (!$reference instanceof Transition) {
                continue;
            }

            $kind = $reference->kind;
            $meta = $reference->meta;

            $baseBad = $atBase instanceof Transition && $isBad($atBase->status);
            $headBad = $atHead instanceof Transition && $isBad($atHead->status);

            if (!$baseBad && $headBad) {
                $newBad[] = new DiffEntry($id, $kind, $meta);

                continue;
            }

            if ($baseBad && !$headBad) {
                $fixed[] = new DiffEntry($id, $kind, $meta);

                continue;
            }

            if ($baseBad) {
                // The two prior checks already ruled out (baseBad, !headBad)
                // and (!baseBad, headBad) — reaching here with $baseBad true
                // means $headBad is true too.
                $stillBad[] = new StillBadEntry($id, $kind, $meta, $this->ageAt($state, $id, $headIndex, $isBad));

                continue;
            }

            ++$unchangedCount;
        }

        return new DiffReport($base, $head, $newBad, $fixed, $stillBad, $unchangedCount);
    }

    /**
     * An empty `$metric` is rejected by {@see Trend} rather than answered with
     * an empty trend — a metric name no run can carry is a caller bug, not a
     * metric nobody recorded.
     *
     * @param non-empty-string $scope
     * @param non-empty-string $metric
     * @param ?int $window Keep only the most recent `$window` runs (must not be negative); `null` (default) keeps all of them. `0` yields an empty trend rather than "no limit" — that reading of a zero window is more useful and less surprising than a silent no-op.
     */
    public function trend(string $scope, string $metric, ?int $window = null): Trend
    {
        if ($window !== null && $window < 0) {
            throw new \InvalidArgumentException(sprintf('window must not be negative, got %d', $window));
        }

        $state = $this->load($scope);
        $runs = $state->runs;

        if ($window === 0) {
            $runs = [];
        } elseif ($window !== null) {
            $runs = \array_slice($runs, -$window);
        }

        $points = [];

        foreach ($runs as $run) {
            if (!\array_key_exists($metric, $run->metrics)) {
                continue;
            }

            $points[] = new TrendPoint($run->run, $run->ts, $run->metrics[$metric]);
        }

        return new Trend($metric, $points);
    }

    /**
     * The transition in effect for `$id` at `$runIndex`, or `null` when the id
     * is not part of the ledger's picture at that point — never observed yet,
     * or observed and since gone. An {@see AbsentStatus} transition is not a
     * status the caller's `$isBad` predicate should ever see: it is the
     * ledger's own bookkeeping, not a domain status.
     */
    private function presentAt(LedgerState $state, string $id, int $runIndex): ?Transition
    {
        $transition = $state->transitionAt($id, $runIndex);

        if (!$transition instanceof Transition || $transition->status === AbsentStatus::VALUE) {
            return null;
        }

        return $transition;
    }

    /**
     * Number of runs `$id` has been continuously bad through `$runIndex` —
     * the distance back to the transition where it most recently became bad.
     *
     * Two boundary details, both mutation-tested and confirmed equivalent —
     * documented rather than left for the next reader (or Infection) to
     * re-derive:
     *
     * - `$transition->runIndex > $runIndex` (not `>=`): the caller only calls
     *   this when `$id` is known bad at `$runIndex`, which means a transition
     *   recorded exactly at `$runIndex` is itself bad — the walk finding it
     *   at the `>` boundary or skipping it at a `>=` boundary sets
     *   `$becameBadAt` to the same value either way (the loop's own
     *   initializer already primes it to `$runIndex`).
     * - `max(0, …)` (not `max(-1, …)`): `$becameBadAt` is only ever set from
     *   a `$transition->runIndex` that passed the check above, i.e. `<=
     *   $runIndex` — the subtraction is never negative, so a `-1` floor is
     *   unreachable.
     *
     * @param \Closure(string): bool $isBad
     * @return int<0, max>
     */
    private function ageAt(LedgerState $state, string $id, int $runIndex, \Closure $isBad): int
    {
        $transitions = $state->transitionsFor($id);
        $becameBadAt = $runIndex;

        for ($i = \count($transitions) - 1; $i >= 0; --$i) {
            $transition = $transitions[$i];

            if ($transition->runIndex > $runIndex) {
                continue;
            }

            $bad = $transition->status !== AbsentStatus::VALUE && $isBad($transition->status);

            if (!$bad) {
                break;
            }

            $becameBadAt = $transition->runIndex;
        }

        return max(0, $runIndex - $becameBadAt);
    }

    /**
     * @param non-empty-string $scope
     */
    private function load(string $scope): LedgerState
    {
        $bytes = $this->storage->read($scope);

        return $bytes === null ? LedgerState::empty() : Codec::decode($bytes);
    }

    /**
     * @param non-empty-string $scope
     */
    private function save(string $scope, LedgerState $state): void
    {
        $this->storage->write($scope, Codec::encode($state));
    }
}
