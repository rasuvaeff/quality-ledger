<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * Moves the ledger's serialized bytes for one scope somewhere and back — a
 * local file, a CI cache artifact, an S3 object. Deliberately byte-oriented:
 * an implementor never sees {@see Datum}, a transition, or any other domain
 * type, so a storage backend can be written without depending on this
 * package's internal representation at all.
 *
 * @api
 */
interface StoragePort
{
    /**
     * @param non-empty-string $scope
     */
    public function read(string $scope): ?string;

    /**
     * @param non-empty-string $scope
     */
    public function write(string $scope, string $bytes): void;
}
