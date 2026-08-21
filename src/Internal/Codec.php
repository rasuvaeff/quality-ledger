<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Internal;

/**
 * {@see LedgerState} as JSON. The on-disk shape carries {@see LedgerState::FORMAT_VERSION}
 * explicitly so a future incompatible change can detect and refuse (or
 * migrate) an old file instead of silently misreading it.
 *
 * The version field alone is not that guarantee, though — a truncated or
 * hand-edited file carries the right `v` and the wrong everything else. So
 * {@see decode()} narrows the decoded payload field by field and raises
 * {@see \RuntimeException} on the first thing that is not what it claims to
 * be, rather than asserting a shape over file bytes with a `@var` and letting
 * a `TypeError` surface three call frames deeper.
 *
 * `ids` is a JSON *list* of `{id, transitions}` records, not an object keyed
 * by id: a digit-only id would come back from `json_decode(assoc: true)` as an
 * `int` key, and an id set of `"0"`, `"1"`, `"2"` would re-encode as a JSON
 * array instead of an object — a silent format change on the way out.
 *
 * @internal
 */
final readonly class Codec
{
    private function __construct()
    {
        // Static helper; not instantiable.
    }

    public static function encode(LedgerState $state): string
    {
        $runs = [];

        foreach ($state->runs as $run) {
            $runs[] = ['i' => $run->runIndex, 'run' => $run->run, 'ts' => $run->ts, 'metrics' => $run->metrics];
        }

        $ids = [];

        foreach ($state->ids() as $id) {
            $encoded = [];

            foreach ($state->transitionsFor($id) as $transition) {
                $encoded[] = [
                    'i' => $transition->runIndex,
                    'run' => $transition->run,
                    'ts' => $transition->ts,
                    'kind' => $transition->kind,
                    'status' => $transition->status,
                    'meta' => $transition->meta,
                ];
            }

            $ids[] = ['id' => $id, 'transitions' => $encoded];
        }

        $payload = [
            'v' => LedgerState::FORMAT_VERSION,
            'seq' => $state->nextRunIndex,
            'runs' => $runs,
            'ids' => $ids,
        ];

        // JSON_PRESERVE_ZERO_FRACTION: without it, an integral metric float
        // (90.0) renders as "90" — json_decode() would read it back as an
        // int, not a float, silently changing RunReport::$metrics' value type
        // on a round trip.
        $encoded = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);

        return $encoded;
    }

    public static function decode(string $bytes): LedgerState
    {
        /** @var mixed $payload */
        $payload = json_decode($bytes, associative: true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($payload)) {
            throw self::malformed('the document', 'a JSON object');
        }

        if (($payload['v'] ?? null) !== LedgerState::FORMAT_VERSION) {
            throw new \RuntimeException(sprintf(
                'Unrecognized quality-ledger file format: expected version %d',
                LedgerState::FORMAT_VERSION,
            ));
        }

        $nextRunIndex = self::intAt($payload, 'seq', 'the document');

        if ($nextRunIndex < 0) {
            throw self::malformed('"seq"', 'a non-negative integer');
        }

        $runs = [];

        /** @var mixed $raw */
        foreach (self::listAt($payload, 'runs', 'the document') as $index => $raw) {
            $context = sprintf('"runs"[%d]', $index);
            $run = self::arrayOf($raw, $context);

            $runIndex = self::intAt($run, 'i', $context);

            if ($runIndex < 0) {
                throw self::malformed($context . '."i"', 'a non-negative integer');
            }

            $runs[] = new RunRecord(
                runIndex: $runIndex,
                run: self::stringAt($run, 'run', $context),
                ts: self::intAt($run, 'ts', $context),
                metrics: self::metricsAt($run, 'metrics', $context),
            );
        }

        $transitionsById = [];

        /** @var mixed $raw */
        foreach (self::listAt($payload, 'ids', 'the document') as $index => $raw) {
            $context = sprintf('"ids"[%d]', $index);
            $entry = self::arrayOf($raw, $context);
            $id = self::stringAt($entry, 'id', $context);

            if ($id === '') {
                throw self::malformed($context . '."id"', 'a non-empty string');
            }

            if (\array_key_exists($id, $transitionsById)) {
                throw self::malformed($context . '."id"', sprintf('unique, but "%s" appears twice', $id));
            }

            $transitions = [];
            $previousRunIndex = null;

            /** @var mixed $rawTransition */
            foreach (self::listAt($entry, 'transitions', $context) as $position => $rawTransition) {
                $transitionContext = sprintf('%s."transitions"[%d]', $context, $position);
                $transition = self::arrayOf($rawTransition, $transitionContext);
                $runIndex = self::intAt($transition, 'i', $transitionContext);

                // {@see LedgerState::transitionAt()} walks the list and stops
                // at the first entry past the index it wants — correct only
                // while the list ascends. That holds by construction for
                // anything this package wrote; it does not hold for a file
                // someone edited, and the failure would be a silently wrong
                // diff rather than an error.
                if ($previousRunIndex !== null && $runIndex <= $previousRunIndex) {
                    throw self::malformed($transitionContext . '."i"', sprintf('greater than the previous transition\'s %d', $previousRunIndex));
                }

                $previousRunIndex = $runIndex;

                $transitions[] = new Transition(
                    id: $id,
                    kind: self::stringAt($transition, 'kind', $transitionContext),
                    runIndex: $runIndex,
                    run: self::stringAt($transition, 'run', $transitionContext),
                    ts: self::intAt($transition, 'ts', $transitionContext),
                    status: self::stringAt($transition, 'status', $transitionContext),
                    meta: self::mapAt($transition, 'meta', $transitionContext),
                );
            }

            $transitionsById[$id] = $transitions;
        }

        return LedgerState::fromParts($nextRunIndex, $runs, $transitionsById);
    }

    /**
     * @param array<array-key, mixed> $source
     */
    private static function intAt(array $source, string $key, string $context): int
    {
        $value = $source[$key] ?? null;

        if (!\is_int($value)) {
            throw self::malformed(sprintf('%s."%s"', $context, $key), 'an integer');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $source
     */
    private static function stringAt(array $source, string $key, string $context): string
    {
        $value = $source[$key] ?? null;

        if (!\is_string($value)) {
            throw self::malformed(sprintf('%s."%s"', $context, $key), 'a string');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $source
     * @return list<mixed>
     */
    private static function listAt(array $source, string $key, string $context): array
    {
        $value = $source[$key] ?? null;

        if (!\is_array($value) || !array_is_list($value)) {
            throw self::malformed(sprintf('%s."%s"', $context, $key), 'a JSON array');
        }

        return $value;
    }

    /**
     * Run-level metrics: numeric values under non-empty names. The names are
     * left exactly as `json_decode()` produced them — a digit-only name comes
     * back as an `int` key and no amount of casting can put it back, because
     * PHP re-coerces it the moment it is written to an array. That costs
     * nothing here: {@see \Rasuvaeff\QualityLedger\Ledger::trend()} looks a
     * metric up by string, and PHP coerces the lookup the same way. An *empty*
     * name is a real string key, so that one is rejected.
     *
     * @param array<array-key, mixed> $source
     * @return array<array-key, int|float>
     */
    private static function metricsAt(array $source, string $key, string $context): array
    {
        $metrics = [];

        foreach (self::mapAt($source, $key, $context) as $name => $value) {
            if ($name === '') {
                throw self::malformed(sprintf('%s."%s"', $context, $key), 'non-empty metric names');
            }

            if (!\is_int($value) && !\is_float($value)) {
                throw self::malformed(sprintf('%s."%s"."%s"', $context, $key, $name), 'a number');
            }

            $metrics[$name] = $value;
        }

        return $metrics;
    }

    /**
     * A JSON object, as opposed to a JSON array. The two are the same value
     * once decoded associatively, so only a *non-empty* list is refused —
     * `{}` and `[]` are genuinely indistinguishable here and both mean "no
     * entries".
     *
     * @param array<array-key, mixed> $source
     * @return array<array-key, mixed>
     */
    private static function mapAt(array $source, string $key, string $context): array
    {
        $label = sprintf('%s."%s"', $context, $key);
        $value = self::arrayOf($source[$key] ?? null, $label);

        if (array_is_list($value) && $value !== []) {
            throw self::malformed($label, 'a JSON object');
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayOf(mixed $value, string $context): array
    {
        if (!\is_array($value)) {
            throw self::malformed($context, 'a JSON object');
        }

        return $value;
    }

    private static function malformed(string $what, string $expectation): \RuntimeException
    {
        return new \RuntimeException(sprintf('Malformed quality-ledger file: %s must be %s', $what, $expectation));
    }
}
