<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\DefaultStableId;
use Rasuvaeff\QualityLedger\Internal\Codec;
use Rasuvaeff\QualityLedger\Internal\Transition;
use Rasuvaeff\QualityLedger\Ledger;
use Rasuvaeff\QualityLedger\RunReport;
use Rasuvaeff\QualityLedger\StableIdInterface;
use Rasuvaeff\QualityLedger\Tests\Support\InMemoryStorage;
use Rasuvaeff\QualityLedger\Tests\Support\SignatureId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The on-disk format. Two halves, and the second is the reason this class
 * exists at all: `encode()`/`decode()` used to be reachable only through
 * `Ledger`'s happy path, so no mutant of either was ever generated — every
 * guard here was, in mutation terms, invisible.
 */
#[Test]
#[Covers(Codec::class)]
final class CodecTest
{
    private const string SCOPE = 'acme/widgets';

    public function aDecodedStateReEncodesToTheSameBytes(): void
    {
        $bytes = $this->ledgerBytes([
            ['a' => 'good', 'b' => 'bad'],
            ['a' => 'bad'],
            ['a' => 'bad', 'c' => 'good'],
        ]);

        Assert::same($this->reEncode($bytes), $bytes);
    }

    /**
     * Byte identity across a round trip is necessary but nowhere near
     * sufficient: `encode()` dropping every transition on the floor satisfies
     * it perfectly, because the bytes being compared against were produced by
     * the same `encode()`. So the content is asserted against what the runs
     * actually observed, independently of the format.
     */
    public function everyStatusChangeIsPresentInTheDecodedState(): void
    {
        $bytes = $this->ledgerBytes([
            ['a' => 'good', 'b' => 'bad'],
            ['a' => 'bad'],
            ['a' => 'bad', 'c' => 'good'],
        ]);

        $state = Codec::decode($bytes);
        $id = (new DefaultStableId())->id(new Datum(kind: 'thing', signature: 'a', status: 'good'));

        Assert::same(
            array_map(static fn(Transition $transition): string => $transition->status, $state->transitionsFor($id)),
            ['good', 'bad'],
        );
        Assert::same($state->transitionsFor($id)[0]->kind, 'thing');
        Assert::same($state->transitionsFor($id)[0]->run, 'r0');
        Assert::same($state->transitionsFor($id)[1]->runIndex, 1);
        Assert::same(\count($state->runs), 3);
    }

    /**
     * `JSON_UNESCAPED_SLASHES`, the other half of the encode flag set. A file
     * path is the single most common thing a caller puts in `meta`, and
     * without the flag every one of them is written as `src\/X.php`.
     */
    public function slashesInMetaAreWrittenUnescaped(): void
    {
        $storage = new InMemoryStorage();
        $ledger = new Ledger(new DefaultStableId(), $storage);
        $ledger->append(new RunReport(
            run: 'r1',
            ts: 1,
            scope: self::SCOPE,
            data: [new Datum(kind: 'thing', signature: 'a', status: 'bad', meta: ['file' => 'src/X.php'])],
        ));

        Assert::string($storage->read(self::SCOPE) ?? '')->contains('"src/X.php"');
    }

    /**
     * The regression behind the `ids`-as-a-list format. PHP stores a
     * digit-only array key as an integer, so an id set of `"0"`, `"1"`, `"2"`
     * used to serialize as a JSON *array* instead of an object — and every
     * id read back out of the decoded map came back as an `int`, breaking the
     * `string` signatures downstream on the very first `append()`.
     */
    public function digitOnlyIdsStaySeparateStringKeyedRecords(): void
    {
        $bytes = $this->ledgerBytes([['0' => 'bad', '1' => 'good', '2' => 'bad']], new SignatureId());

        // Asserted on the bytes, not on a decoded structure: the failure this
        // guards against is the id block serializing as a JSON *array*
        // (`"ids":[{...}]` is the record list; `{"0":{...}}` was the old
        // object form that collapsed into one).
        Assert::string($bytes)->contains('"ids":[{"id":"0",');

        $state = Codec::decode($bytes);

        Assert::same($state->ids(), ['0', '1', '2']);
        Assert::same($this->reEncode($bytes), $bytes);
    }

    /**
     * `JSON_PRESERVE_ZERO_FRACTION` is what keeps this true — without it a
     * metric of `90.0` renders as `90` and reads back as an `int`.
     */
    public function anIntegralFloatMetricStaysAFloatAcrossARoundTrip(): void
    {
        $storage = new InMemoryStorage();
        $ledger = new Ledger(new DefaultStableId(), $storage);
        $ledger->append(new RunReport(run: 'r1', ts: 1, scope: self::SCOPE, data: [], metrics: ['msi' => 90.0, 'total' => 7]));

        $bytes = $storage->read(self::SCOPE) ?? '';
        $state = Codec::decode($bytes);

        Assert::same($state->runs[0]->metrics['msi'], 90.0);
        Assert::same($state->runs[0]->metrics['total'], 7);
    }

    public function aDocumentThatIsNotAJsonObjectIsRejected(): void
    {
        $this->assertRejected('42', 'the document must be a JSON object');
    }

    /**
     * Both directions, because the version field earns its place only if it
     * catches the layout that actually existed: `v:1` keyed `ids` by id and
     * carried `kind` once per id, so a file from it must be refused *by
     * version* rather than by whichever structural check trips first.
     */
    public function aFileFromAnotherFormatVersionIsRejected(): void
    {
        $this->assertRejected('{"v":1,"seq":0,"runs":[],"ids":{"a":{"kind":"k","transitions":[]}}}', 'expected version 2');
        $this->assertRejected('{"v":3,"seq":0,"runs":[],"ids":[]}', 'expected version 2');
        $this->assertRejected('{"seq":0,"runs":[],"ids":[]}', 'expected version 2');
    }

    /**
     * The truncation case: `{"v":2}` carries a version the guard accepts and
     * nothing else. Before the field-by-field narrowing this surfaced as a
     * raw `TypeError` from an `@internal` constructor four frames down.
     */
    public function aTruncatedFileIsRejectedWithTheFieldThatIsMissing(): void
    {
        $this->assertRejected('{"v":2}', 'the document."seq" must be an integer');
    }

    public function aNegativeSequenceIsRejected(): void
    {
        $this->assertRejected('{"v":2,"seq":-1,"runs":[],"ids":[]}', '"seq" must be a non-negative integer');
    }

    public function aNonNumericSequenceIsRejected(): void
    {
        $this->assertRejected('{"v":2,"seq":"3","runs":[],"ids":[]}', 'the document."seq" must be an integer');
    }

    public function runsThatAreNotAJsonArrayAreRejected(): void
    {
        $this->assertRejected('{"v":2,"seq":0,"runs":{"a":1},"ids":[]}', 'the document."runs" must be a JSON array');
    }

    public function aRunThatIsNotAnObjectIsRejected(): void
    {
        $this->assertRejected('{"v":2,"seq":0,"runs":[7],"ids":[]}', '"runs"[0] must be a JSON object');
    }

    public function aNegativeRunIndexIsRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":1,"runs":[{"i":-1,"run":"r1","ts":0,"metrics":{}}],"ids":[]}',
            '"runs"[0]."i" must be a non-negative integer',
        );
    }

    public function aRunWithANonStringNameIsRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":1,"runs":[{"i":0,"run":5,"ts":0,"metrics":{}}],"ids":[]}',
            '"runs"[0]."run" must be a string',
        );
    }

    public function aNonNumericMetricValueIsRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":1,"runs":[{"i":0,"run":"r1","ts":0,"metrics":{"msi":"90"}}],"ids":[]}',
            '"runs"[0]."metrics"."msi" must be a number',
        );
    }

    public function anEmptyMetricNameIsRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":1,"runs":[{"i":0,"run":"r1","ts":0,"metrics":{"":90}}],"ids":[]}',
            '"runs"[0]."metrics" must be non-empty metric names',
        );
    }

    public function metricsThatAreAJsonArrayAreRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":1,"runs":[{"i":0,"run":"r1","ts":0,"metrics":[90]}],"ids":[]}',
            '"runs"[0]."metrics" must be a JSON object',
        );
    }

    /**
     * `{}` and `[]` are the same value once JSON is decoded associatively, so
     * an empty metric map must not be mistaken for the rejected list form.
     */
    public function emptyMetricsAreAccepted(): void
    {
        $state = Codec::decode('{"v":2,"seq":1,"runs":[{"i":0,"run":"r1","ts":0,"metrics":[]}],"ids":[]}');

        Assert::same($state->runs[0]->metrics, []);
    }

    /**
     * A digit-only metric name comes back as an `int` key, and cannot be made
     * to come back otherwise — PHP re-coerces it on write. Recorded as the
     * behaviour rather than hidden behind a cast that does nothing: nothing
     * downstream cares, because a metric lookup by string coerces identically.
     */
    public function aDigitOnlyMetricNameIsStillFoundByItsStringName(): void
    {
        $state = Codec::decode('{"v":2,"seq":1,"runs":[{"i":0,"run":"r1","ts":0,"metrics":{"7":90}}],"ids":[]}');

        Assert::same(array_keys($state->runs[0]->metrics), [7]);
        Assert::true(\array_key_exists('7', $state->runs[0]->metrics));
    }

    public function idsThatAreNotAJsonArrayAreRejected(): void
    {
        $this->assertRejected('{"v":2,"seq":0,"runs":[],"ids":{"a":{}}}', 'the document."ids" must be a JSON array');
    }

    public function anEmptyIdIsRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":0,"runs":[],"ids":[{"id":"","transitions":[]}]}',
            '"ids"[0]."id" must be a non-empty string',
        );
    }

    public function aRepeatedIdIsRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":0,"runs":[],"ids":[{"id":"a","transitions":[]},{"id":"a","transitions":[]}]}',
            '"ids"[1]."id" must be unique, but "a" appears twice',
        );
    }

    public function transitionsThatAreNotAJsonArrayAreRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":0,"runs":[],"ids":[{"id":"a","transitions":{"first":{}}}]}',
            '"ids"[0]."transitions" must be a JSON array',
        );
    }

    /**
     * The second half of the old version-guard gap: an id record with an
     * empty `transitions` list decoded silently, and `encode()` then read
     * `$transitions[0]->kind` off nothing and wrote `"kind":null` back to
     * disk, corrupting the file a little more on every save. `kind` now lives
     * on each transition, so there is no index-zero read to get wrong.
     */
    public function anIdWithNoTransitionsRoundTripsWithoutCorruptingTheFile(): void
    {
        $bytes = '{"v":2,"seq":0,"runs":[],"ids":[{"id":"a","transitions":[]}]}';

        Assert::same($this->reEncode($bytes), $bytes);
    }

    public function aTransitionMissingItsKindIsRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":1,"runs":[],"ids":[{"id":"a","transitions":[{"i":0,"run":"r1","ts":0,"status":"bad","meta":{}}]}]}',
            '"ids"[0]."transitions"[0]."kind" must be a string',
        );
    }

    public function aTransitionWithANonIntegerRunIndexIsRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":1,"runs":[],"ids":[{"id":"a","transitions":[{"i":"0","run":"r1","ts":0,"kind":"k","status":"bad","meta":{}}]}]}',
            '"ids"[0]."transitions"[0]."i" must be an integer',
        );
    }

    public function aTransitionWithANonIntegerTimestampIsRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":1,"runs":[],"ids":[{"id":"a","transitions":[{"i":0,"run":"r1","ts":null,"kind":"k","status":"bad","meta":{}}]}]}',
            '"ids"[0]."transitions"[0]."ts" must be an integer',
        );
    }

    public function aTransitionWhoseMetaIsAJsonArrayIsRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":1,"runs":[],"ids":[{"id":"a","transitions":[{"i":0,"run":"r1","ts":0,"kind":"k","status":"bad","meta":["x"]}]}]}',
            '"ids"[0]."transitions"[0]."meta" must be a JSON object',
        );
    }

    public function aTransitionThatIsNotAnObjectIsRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":1,"runs":[],"ids":[{"id":"a","transitions":["x"]}]}',
            '"ids"[0]."transitions"[0] must be a JSON object',
        );
    }

    public function anIdRecordThatIsNotAnObjectIsRejected(): void
    {
        $this->assertRejected('{"v":2,"seq":0,"runs":[],"ids":["a"]}', '"ids"[0] must be a JSON object');
    }

    public function anIdThatIsNotAStringIsRejected(): void
    {
        $this->assertRejected('{"v":2,"seq":0,"runs":[],"ids":[{"id":7,"transitions":[]}]}', '"ids"[0]."id" must be a string');
    }

    /**
     * The list order is load-bearing: {@see \Rasuvaeff\QualityLedger\Internal\LedgerState::transitionAt()}
     * stops at the first entry past the index it is asked about, which is only
     * correct while the list ascends. A hand-edited file that violates it
     * would produce a silently wrong diff rather than an error.
     */
    public function transitionsOutOfRunIndexOrderAreRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":2,"runs":[],"ids":[{"id":"a","transitions":['
            . '{"i":1,"run":"r2","ts":0,"kind":"k","status":"bad","meta":{}},'
            . '{"i":0,"run":"r1","ts":0,"kind":"k","status":"good","meta":{}}]}]}',
            '"ids"[0]."transitions"[1]."i" must be greater than the previous transition\'s 1',
        );
    }

    public function twoTransitionsAtTheSameRunIndexAreRejected(): void
    {
        $this->assertRejected(
            '{"v":2,"seq":1,"runs":[],"ids":[{"id":"a","transitions":['
            . '{"i":0,"run":"r1","ts":0,"kind":"k","status":"bad","meta":{}},'
            . '{"i":0,"run":"r1","ts":0,"kind":"k","status":"good","meta":{}}]}]}',
            '"ids"[0]."transitions"[1]."i" must be greater than the previous transition\'s 0',
        );
    }

    public function metaIsCarriedThroughVerbatim(): void
    {
        $state = Codec::decode(
            '{"v":2,"seq":1,"runs":[],"ids":[{"id":"a","transitions":[{"i":0,"run":"r1","ts":0,"kind":"k","status":"bad","meta":{"file":"src/X.php","line":42}}]}]}',
        );

        Assert::same($state->transitionsFor('a')[0]->meta, ['file' => 'src/X.php', 'line' => 42]);
    }

    /**
     * `{}` in the `meta` position is an empty map, and an empty JSON array is
     * the same decoded value — neither may be refused.
     */
    public function emptyMetaIsAccepted(): void
    {
        $state = Codec::decode(
            '{"v":2,"seq":1,"runs":[],"ids":[{"id":"a","transitions":[{"i":0,"run":"r1","ts":0,"kind":"k","status":"bad","meta":{}}]}]}',
        );

        Assert::same($state->transitionsFor('a')[0]->meta, []);
    }

    /**
     * The round trip the "Максимальное использование API property-testing"
     * checklist asks for first: `encode(decode(x)) == x` over ledgers built
     * from arbitrary run sequences, including the digit-only ids and the
     * disappear/reappear paths that used to have no mutation coverage at all.
     *
     * @param list<array<array-key, string>> $runs Each entry is one run: signature => status.
     */
    #[Property(runs: 300, timeoutMs: 2_000)]
    public function anyLedgerReEncodesToTheBytesItWasDecodedFrom(array $runs): void
    {
        $signatures = [];

        foreach ($runs as $data) {
            foreach (array_keys($data) as $signature) {
                $signatures[] = (string) $signature;
            }
        }

        Classify::cover(\count($runs) > 1, 'several runs', 30.0);
        Classify::cover(array_filter($signatures, ctype_digit(...)) !== [], 'digit-only id', 25.0);
        Classify::when($signatures === [], 'no data at all');
        Classify::when(\count($signatures) !== \count(array_unique($signatures)), 'an id observed in more than one run');

        $bytes = $this->ledgerBytes($runs, new SignatureId());

        Assert::same($this->reEncode($bytes), $bytes);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function anyLedgerReEncodesToTheBytesItWasDecodedFromGenerators(): array
    {
        return [
            'runs' => Gen::arrayOf(
                Gen::dictOf(
                    Gen::frequency([[1, Gen::stringFrom('012', 1, 2)], [1, Gen::stringFrom('ab', 1, 2)]]),
                    Gen::elements(['good', 'bad', 'flaky']),
                    minSize: 0,
                    maxSize: 4,
                ),
                minSize: 1,
                maxSize: 4,
            ),
        ];
    }

    /**
     * Edge cases pinned ahead of the random phase — each one is a shape that
     * broke, or nearly broke, the format: an id set that is exactly `0..n`
     * (the JSON-array trap), an id that disappears and comes back, a run with
     * no data, and a single id observed once.
     *
     * @return iterable<string, array{0: list<array<array-key, string>>}>
     */
    public static function anyLedgerReEncodesToTheBytesItWasDecodedFromExamples(): iterable
    {
        yield 'sequential digit ids' => [[['0' => 'bad', '1' => 'good', '2' => 'bad']]];
        yield 'a single id' => [[['a' => 'good']]];
        yield 'a run with no data' => [[['a' => 'good'], []]];
        yield 'an id that disappears and returns' => [[['a' => 'bad'], ['b' => 'good'], ['a' => 'bad']]];
        yield 'one digit id alongside a word id' => [[['7' => 'bad', 'x' => 'good']]];
    }

    /**
     * @param list<array<array-key, string>> $runs
     */
    private function ledgerBytes(array $runs, ?StableIdInterface $id = null): string
    {
        $storage = new InMemoryStorage();
        $ledger = new Ledger($id ?? new DefaultStableId(), $storage);

        foreach ($runs as $index => $data) {
            $observations = [];

            foreach ($data as $signature => $status) {
                $observations[] = new Datum(kind: 'thing', signature: (string) $signature, status: $status, meta: ['n' => $index]);
            }

            $ledger->append(new RunReport(
                run: 'r' . $index,
                ts: $index,
                scope: self::SCOPE,
                data: $observations,
                metrics: ['msi' => $index + 0.5],
            ));
        }

        return $storage->read(self::SCOPE) ?? '';
    }

    private function reEncode(string $bytes): string
    {
        return Codec::encode(Codec::decode($bytes));
    }

    private function assertRejected(string $bytes, string $expectedMessageFragment): void
    {
        try {
            Codec::decode($bytes);

            Assert::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            Assert::string($e->getMessage())->contains($expectedMessageFragment);
        }
    }
}
