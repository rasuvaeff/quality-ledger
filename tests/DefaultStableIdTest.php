<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\DefaultStableId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(DefaultStableId::class)]
final class DefaultStableIdTest
{
    private DefaultStableId $stableId;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->stableId = new DefaultStableId();
    }

    public function theIdIsSha256OfKindNullByteSignatureInThatOrder(): void
    {
        $datum = new Datum(kind: 'mutant', signature: 'x', status: 'escaped');

        Assert::same($this->stableId->id($datum), hash('sha256', "mutant\0x"));
    }

    public function sameKindAndSignatureGiveTheSameId(): void
    {
        $a = new Datum(kind: 'mutant', signature: 'src/X.php:1:Foo', status: 'escaped');
        $b = new Datum(kind: 'mutant', signature: 'src/X.php:1:Foo', status: 'killed');

        Assert::same($this->stableId->id($a), $this->stableId->id($b));
    }

    public function differentSignaturesGiveDifferentIds(): void
    {
        $a = new Datum(kind: 'mutant', signature: 'a', status: 'escaped');
        $b = new Datum(kind: 'mutant', signature: 'b', status: 'escaped');

        Assert::false($this->stableId->id($a) === $this->stableId->id($b));
    }

    public function differentKindsWithTheSameSignatureDoNotCollide(): void
    {
        $a = new Datum(kind: 'mutant', signature: 'x', status: 'escaped');
        $b = new Datum(kind: 'flaky', signature: 'x', status: 'flaky');

        Assert::false($this->stableId->id($a) === $this->stableId->id($b));
    }

    public function aNullByteInTheKindDoesNotByItselfProduceACollision(): void
    {
        // The separator is a null byte, and it is deliberately not a security
        // boundary — just a namespacing convenience. sha256 hashes the exact
        // bytes, so unrelated inputs diverge; a kind that ends where another
        // pair's separator would fall is a different question, covered below.
        $a = new Datum(kind: 'mutant', signature: 'x', status: 'escaped');
        $b = new Datum(kind: "mutant\0x", signature: 'y', status: 'escaped');

        Assert::false($this->stableId->id($a) === $this->stableId->id($b));
    }

    public function theNullByteSeparatorIsForgeableAndTheDocsSaySo(): void
    {
        // Recorded rather than papered over: kind."\0".signature is not an
        // injective encoding, so a caller free to choose both halves can force
        // a collision. It is harmless for the intended use (one analyzer owns
        // one `kind`), and pretending otherwise would need a length-prefixed
        // encoding — a format change nobody has asked for.
        $a = new Datum(kind: 'mutant', signature: "x\0y", status: 'escaped');
        $b = new Datum(kind: "mutant\0x", signature: 'y', status: 'escaped');

        Assert::same($this->stableId->id($a), $this->stableId->id($b));
    }
}
