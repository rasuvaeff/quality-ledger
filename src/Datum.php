<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

use Rasuvaeff\QualityLedger\Internal\AbsentStatus;
use Rasuvaeff\QualityLedger\Internal\Assert;

/**
 * One observation inside a single run: a mutant, a flaky-test outcome, a
 * doc-block check — the ledger does not know or care which. `signature` is
 * the raw material a {@see StableIdInterface} normalizes into an id; the
 * ledger itself never interprets it.
 *
 * @api
 */
final readonly class Datum
{
    /**
     * @param non-empty-string $kind Domain tag ('mutant', 'flaky', 'doc-block', ...) — informational, not used for id.
     * @param non-empty-string $signature Raw material for {@see StableIdInterface::id()}.
     * @param non-empty-string $status Domain-defined ('killed', 'escaped', 'flaky', ...); {@see DiffReport} only needs a caller-supplied predicate for "bad".
     * @param array<string, mixed> $meta Arbitrary domain payload (file, line, killer, ...), carried through untouched.
     */
    public function __construct(
        public string $kind,
        public string $signature,
        public string $status,
        public array $meta = [],
    ) {
        Assert::nonEmpty($this->kind, 'kind');
        Assert::nonEmpty($this->signature, 'signature');
        Assert::nonEmpty($this->status, 'status');

        if ($this->status === AbsentStatus::VALUE) {
            throw new \InvalidArgumentException('status must not be the ledger\'s internal absent sentinel');
        }
    }
}
