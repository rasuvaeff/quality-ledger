<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests\Support;

use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\StableIdInterface;

/**
 * Uses the signature verbatim as the id. {@see \Rasuvaeff\QualityLedger\DefaultStableId}
 * hashes, so every id it produces is 64 hex characters and no test using it
 * can ever exercise a digit-only id — the shape PHP silently turns into an
 * integer array key. This double is how the tests reach that case, and it is
 * squarely inside what {@see StableIdInterface} permits: any non-empty string.
 */
final readonly class SignatureId implements StableIdInterface
{
    #[\Override]
    public function id(Datum $datum): string
    {
        return $datum->signature;
    }
}
