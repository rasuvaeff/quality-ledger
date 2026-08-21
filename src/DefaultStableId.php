<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * `sha256(kind + "\0" + signature)`. Good enough when the caller already
 * normalizes `signature` into whatever should count as "the same observation"
 * (a mutation-history caller, for instance, would normalize a diff snippet
 * before handing it to {@see Datum::$signature}). The `kind` prefix keeps two
 * different analyzers' data from colliding on an incidentally identical
 * signature string.
 *
 * @api
 */
final readonly class DefaultStableId implements StableIdInterface
{
    #[\Override]
    public function id(Datum $datum): string
    {
        return hash('sha256', $datum->kind . "\0" . $datum->signature);
    }
}
