<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * Turns a {@see Datum} into the id its history is tracked under. Must be
 * deterministic and stable across runs for "the same observation" — a mutant
 * at the same file/line/mutator/diff, a flaky test in the same dimension, a
 * doc-block at the same position. A source that moved (a shifted line, an
 * edited snippet) is honestly a different id: the ledger has no way to know
 * it is "the same" observation without the caller saying so, and guessing
 * would silently misattribute history to the wrong thing.
 *
 * @api
 */
interface StableIdInterface
{
    /**
     * @return non-empty-string
     */
    public function id(Datum $datum): string;
}
