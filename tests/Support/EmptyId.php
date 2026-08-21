<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests\Support;

use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\StableIdInterface;

/**
 * Breaks {@see StableIdInterface}'s `non-empty-string` promise. A docblock
 * annotation binds only the call sites Psalm can see, which is never a
 * consumer's own id function — so the promise needs a runtime check, and a
 * runtime check needs something that violates it.
 */
final readonly class EmptyId implements StableIdInterface
{
    #[\Override]
    public function id(Datum $datum): string
    {
        return '';
    }
}
