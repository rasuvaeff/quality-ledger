<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\QualityLedger\Internal\AbsentStatus;
use Rasuvaeff\QualityLedger\Internal\LedgerState;
use Rasuvaeff\QualityLedger\Internal\RunRecord;
use Rasuvaeff\QualityLedger\Internal\Transition;
use Rasuvaeff\QualityLedger\RetentionPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The negative-TTL guard used to be executed by nothing at all, and the policy
 * itself only ever ran through `Ledger` at its default setting.
 */
#[Test]
#[Covers(RetentionPolicy::class)]
final class RetentionPolicyTest
{
    public function aNegativeTtlIsRejected(): void
    {
        try {
            new RetentionPolicy(tombstoneTtlRuns: -1);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('tombstoneTtlRuns must not be negative');
        }
    }

    public function aZeroTtlIsAccepted(): void
    {
        Assert::instanceOf(new RetentionPolicy(tombstoneTtlRuns: 0), RetentionPolicy::class);
    }

    /**
     * A zero TTL is "drop it as soon as it has aged at all", not "drop it
     * immediately": the tombstone recorded by the newest run survives that
     * run and is dropped by the next one.
     */
    public function aZeroTtlKeepsATombstoneUntilTheRunAfterTheOneThatRecordedIt(): void
    {
        $policy = new RetentionPolicy(tombstoneTtlRuns: 0);

        Assert::same($policy->apply($this->stateWithTombstoneAt(0, runsRecorded: 1))->ids(), ['a']);
        Assert::same($policy->apply($this->stateWithTombstoneAt(0, runsRecorded: 2))->ids(), []);
    }

    public function theDefaultTtlKeepsATombstoneForFiftyRuns(): void
    {
        $policy = new RetentionPolicy();

        Assert::same($policy->apply($this->stateWithTombstoneAt(0, runsRecorded: 51))->ids(), ['a']);
        Assert::same($policy->apply($this->stateWithTombstoneAt(0, runsRecorded: 52))->ids(), []);
    }

    public function anActiveIdIsNeverAffected(): void
    {
        $state = LedgerState::empty()
            ->withObservations([$this->transition('a', 'bad', 0)])
            ->withRecordedRun(new RunRecord(runIndex: 0, run: 'r0', ts: 0, metrics: []));

        Assert::same((new RetentionPolicy(tombstoneTtlRuns: 0))->apply($state)->ids(), ['a']);
    }

    private function stateWithTombstoneAt(int $runIndex, int $runsRecorded): LedgerState
    {
        $state = LedgerState::empty()->withObservations([
            $this->transition('a', 'bad', $runIndex),
            $this->transition('a', AbsentStatus::VALUE, $runIndex),
        ]);

        for ($i = 0; $i < $runsRecorded; ++$i) {
            $state = $state->withRecordedRun(new RunRecord(runIndex: $i, run: 'r' . $i, ts: $i, metrics: []));
        }

        return $state;
    }

    private function transition(string $id, string $status, int $runIndex): Transition
    {
        return new Transition(id: $id, kind: 'thing', runIndex: $runIndex, run: 'r' . $runIndex, ts: $runIndex, status: $status, meta: []);
    }
}
