# rasuvaeff/quality-ledger

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/quality-ledger/v)](https://packagist.org/packages/rasuvaeff/quality-ledger)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/quality-ledger/downloads)](https://packagist.org/packages/rasuvaeff/quality-ledger)
[![Build](https://github.com/rasuvaeff/quality-ledger/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/quality-ledger/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/quality-ledger/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/quality-ledger/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/quality-ledger/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/quality-ledger/php)](https://packagist.org/packages/rasuvaeff/quality-ledger)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

A framework-agnostic ledger for quality signals with history: append a
CI tool's one-shot output (a mutation testing run, a flaky-test sweep, a
doc-check pass — anything), and get cross-run history, a diff between any
two recorded runs, and a ratchet gate that fails a build only on a
**regression it actually introduced** — never on background debt an
unrelated change did not create.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Why

Most quality tools (mutation testers, static analyzers) are one-shot: run
them, read the result, throw the log away. A global pass/fail threshold then
either blocks unrelated PRs on pre-existing debt, or lets a small regression
slide because the aggregate number still clears the bar. `quality-ledger`
gives any such tool a memory: an id that used to be "good" and just turned
"bad" is a real regression (`newBad`); an id that has been "bad" for a while
is background debt (`stillBad`, with how many runs it has been that way).
Only the first should ever block a PR.

The package has no opinion on what a "quality signal" is — that is entirely
up to the caller.

## Requirements

- PHP 8.3–8.5
- No runtime dependencies

## Installation

```bash
composer require rasuvaeff/quality-ledger
```

## Usage

```php doc-exec
use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\DefaultStableId;
use Rasuvaeff\QualityLedger\Ledger;
use Rasuvaeff\QualityLedger\LocalFileStorage;
use Rasuvaeff\QualityLedger\RatchetGate;
use Rasuvaeff\QualityLedger\RunReport;

$ledger = new Ledger(
    id: new DefaultStableId(),
    storage: new LocalFileStorage(sys_get_temp_dir() . '/quality-ledger-readme'),
);

$ledger->append(new RunReport(
    run: 'run-1',
    ts: 1_700_000_000,
    scope: 'acme/widgets',
    data: [
        new Datum(kind: 'mutant', signature: 'src/Foo.php:12:TrueValue', status: 'killed'),
        new Datum(kind: 'mutant', signature: 'src/Foo.php:20:FalseValue', status: 'killed'),
    ],
    metrics: ['msi' => 100.0],
));

$ledger->append(new RunReport(
    run: 'run-2',
    ts: 1_700_003_600,
    scope: 'acme/widgets',
    data: [
        new Datum(kind: 'mutant', signature: 'src/Foo.php:12:TrueValue', status: 'killed'),
        new Datum(kind: 'mutant', signature: 'src/Foo.php:20:FalseValue', status: 'escaped'),
    ],
    metrics: ['msi' => 50.0],
));

$isBad = static fn(string $status): bool => $status === 'escaped';
$diff = $ledger->diff(scope: 'acme/widgets', base: 'run-1', head: 'run-2', isBad: $isBad);

count($diff->newBad);  // => 1
count($diff->fixed);   // => 0
$diff->unchangedCount; // => 1

$gate = (new RatchetGate())->evaluate($diff);

$gate->ok;                   // => false
$gate->regressions[0]->kind; // => 'mutant'

$ledger->trend(scope: 'acme/widgets', metric: 'msi')->points[1]->value; // => 50.0
```

The block above is executed on every build (`composer docs`, via
[doc-exec](https://github.com/rasuvaeff/doc-exec)) — the `// =>` comments are
assertions, not decoration, so this sample cannot drift from the code. Point
`LocalFileStorage` at a directory that outlives the process in real use;
appending the same `run` again is a no-op, which is what makes a retried CI
step safe.


### `StableIdInterface`

The main extension point, and the only thing that decides what "the same
observation across two runs" means:

```php
interface StableIdInterface
{
    /** @return non-empty-string */
    public function id(Datum $datum): string;
}
```

`DefaultStableId` hashes `kind + "\0" + signature` with sha256, which is right
whenever the caller already normalizes `signature` into whatever should count
as the same observation. Supply your own when it should not — a mutant that
moved by one line is honestly a different id unless you say otherwise, and the
ledger has no way to guess.

Any non-empty string is a valid id, digits included; an id function that
returns an empty string is rejected by `append()` rather than silently
recorded.

### `Ledger`

| Method | Does |
|---|---|
| `append(RunReport $report)` | Records one run's data and metrics. A repeated `$report->run` is a no-op. |
| `diff(scope, base, head, isBad)` | Every id observed at `base` and/or `head`, classified into `newBad`, `fixed`, `stillBad`, or a rolled-up `unchangedCount`. |
| `trend(scope, metric, window = null)` | The named run-level metric across the most recent `window` runs (`null` = all, `0` = none). |

`isBad` is a `Closure(string): bool` you supply — the ledger stores whatever
status strings your domain uses and never interprets them itself.

`diff()` is directional and does not sort its arguments: it reports the change
*from* `base` *to* `head`, so passing the newer run as `base` reports a
regression as a fix. Pass them in chronological order.

`Datum` and `RunReport` validate their own inputs — an empty `kind`,
`signature`, `status`, `run`, `scope` or metric name is an
`InvalidArgumentException` at construction, not a corrupt entry discovered
later.

### Result types

Everything a call hands back, all plain readonly value objects:

| Type | Fields |
|---|---|
| `DiffReport` | `base`, `head`, `newBad: list<DiffEntry>`, `fixed: list<DiffEntry>`, `stillBad: list<StillBadEntry>`, `unchangedCount: int`, plus `totalIdsCompared()` |
| `DiffEntry` | `id`, `kind`, `meta` — the `meta` as of `head` when the id is present there, otherwise as of `base` |
| `StillBadEntry` | the same three, plus `ageRuns`: how many runs the id has been continuously bad |
| `GateResult` | `ok: bool`, `regressions: list<DiffEntry>` (returned by `RatchetGate::evaluate()`) |
| `Trend` | `metric`, `points: list<TrendPoint>`, oldest first |
| `TrendPoint` | `run`, `ts`, `value: int\|float` |

A run missing the requested metric is skipped in a `Trend`, not zero-filled.

### Storage

`StoragePort` is two methods, `read(scope): ?string` and `write(scope,
bytes): void` — implement it against S3, a CI cache, anything. The shipped
`LocalFileStorage` writes one file per scope, atomically (temp file +
`O_EXCL` create + rename), under a directory you choose.

### Retention

`RetentionPolicy(tombstoneTtlRuns: 50)` (the default) drops an id once it has
been absent for more than that many runs, so a ledger tracking a churning
codebase does not grow one dead row per mutant or test ever removed. An id
that is present, or currently bad, is never pruned regardless of this
setting.

## Security

The engine reads no environment and calls no clock or VCS — every
identifier and timestamp is supplied by the caller. `LocalFileStorage` writes
are hardened against a predictable-temp-path symlink attack on a shared
directory (`O_EXCL` create, refuses to follow an existing path). Storage
files are plain JSON containing whatever `meta` your `Datum`s carry — treat
them as build artifacts, not secrets.

**`append()` is not concurrency-safe.** A single write is atomic, but
`append()` is a read-modify-write over the whole scope and `StoragePort` has
no compare-and-swap: two CI jobs appending to one scope at the same time lose
one of the two runs, with no error. Give each scope a single writer — one job
per scope, or a scope per job — or serialize the appends yourself. A read
(`diff()`, `trend()`) concurrent with a write is safe: it sees either the old
file or the new one, never a partial one.

A corrupt or hand-edited ledger file is refused with a `RuntimeException`
naming the offending field, rather than being partially believed.

## Examples

See [examples/](examples/).

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer docs
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

## License

[BSD-3-Clause](LICENSE.md)
