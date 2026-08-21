<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * One id inside a {@see DiffReport} bucket, carrying the most recent `meta`
 * observed for it (as of `head` when present there, otherwise as of `base`).
 * `id` and `kind` are plain `string`, not `non-empty-string`: both round-trip
 * through the ledger's on-disk JSON, which cannot carry a static
 * non-emptiness guarantee — in practice they are always non-empty, since
 * {@see StableIdInterface::id()} and {@see Datum::$kind} promise it.
 *
 * @api
 */
final readonly class DiffEntry
{
    /**
     * @param array<array-key, mixed> $meta Carried through from the observation verbatim; a digit-only key arrives as an `int` after a round trip through storage.
     */
    public function __construct(
        public string $id,
        public string $kind,
        public array $meta,
    ) {}
}
