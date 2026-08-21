<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\Internal\AbsentStatus;
use Rasuvaeff\QualityLedger\Internal\Assert as Guard;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * `Datum` is one of the two types the package's own architecture notes call
 * "where the contract is actually enforceable" — the justification for
 * `Internal\Transition` being typed loosely. It used to enforce nothing.
 */
#[Test]
#[Covers(Datum::class)]
#[Covers(Guard::class)]
final class DatumTest
{
    public function aFullyPopulatedDatumIsAccepted(): void
    {
        $datum = new Datum(kind: 'mutant', signature: 'src/X.php:1', status: 'escaped', meta: ['file' => 'src/X.php']);

        Assert::same($datum->kind, 'mutant');
        Assert::same($datum->signature, 'src/X.php:1');
        Assert::same($datum->status, 'escaped');
        Assert::same($datum->meta, ['file' => 'src/X.php']);
    }

    public function metaDefaultsToEmpty(): void
    {
        Assert::same((new Datum(kind: 'mutant', signature: 'x', status: 'escaped'))->meta, []);
    }

    public function anEmptyKindIsRejected(): void
    {
        $this->assertRejected(static fn(): Datum => new Datum(kind: '', signature: 'x', status: 'escaped'), 'kind must not be empty');
    }

    public function anEmptySignatureIsRejected(): void
    {
        $this->assertRejected(static fn(): Datum => new Datum(kind: 'mutant', signature: '', status: 'escaped'), 'signature must not be empty');
    }

    public function anEmptyStatusIsRejected(): void
    {
        $this->assertRejected(static fn(): Datum => new Datum(kind: 'mutant', signature: 'x', status: ''), 'status must not be empty');
    }

    /**
     * The sentinel is how the ledger records "this id stopped appearing". A
     * caller that could supply it as a domain status could forge a
     * disappearance the analyzer never observed, so it is rejected at the
     * boundary rather than trusted not to collide.
     */
    public function theInternalAbsentSentinelIsNotAcceptableAsADomainStatus(): void
    {
        $this->assertRejected(
            static fn(): Datum => new Datum(kind: 'mutant', signature: 'x', status: AbsentStatus::VALUE),
            'internal absent sentinel',
        );
    }

    /**
     * @param \Closure(): Datum $construct
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
