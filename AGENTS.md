# AGENTS.md — quality-ledger

Guidance for AI agents working on this package. Read before changing code.

## What this is

A framework- and domain-agnostic ledger for one-shot quality signals: it
turns a stream of `RunReport`s (a mutation-testing run, a flaky-test sweep,
anything) into append-only history, a per-id diff between any two recorded
runs (`newBad`/`fixed`/`stillBad`), and a `RatchetGate` that fails only on a
genuinely new regression. Public namespace `Rasuvaeff\QualityLedger`
(`Ledger`, `Datum`, `RunReport`, `StableIdInterface`/`DefaultStableId`,
`StoragePort`/`LocalFileStorage`, `RetentionPolicy`, `DiffReport`/`DiffEntry`/
`StillBadEntry`, `RatchetGate`, `Trend`/`TrendPoint`); `@internal` under
`Rasuvaeff\QualityLedger\Internal` (`LedgerState`, `Transition`, `RunRecord`,
`AbsentStatus`, `Codec`).

This is the substrate for `mutation-history`, `doc-exec` and
`flaky-detector` (see `../QUALITY-INFRA-PLAN.md` and
`../QUALITY-LEDGER-PLAN.md` in the monorepo root) — every domain-specific
analyzer is meant to be a thin id-function plus a parser on top of this
engine, not a fork of it.

## Versioning

**The first release is `0.1.0`, and that number is already load-bearing.**
`mutation-history` requires `rasuvaeff/quality-ledger: ^0.1` and its path
repository invents the version `0.1.0` to satisfy it. A path repository always
satisfies its own constraint, so a first tag of `0.2.0` or `1.0.0` would break
that package only once it is installed from Packagist — long after the choice
was made. `CHANGELOG.md`'s top heading is therefore `## 0.1.0 — <date>`, not
`## Unreleased`: the bc-check tolerance step reads that heading, and a release
PR is what stamps the date onto it.

The on-disk format was free to change up to that first tag — that is why
`FORMAT_VERSION` is already `2` while the package is at `0.1.0`: the layout was
reworked before release, and the version field has to detect a file written by
the earlier one rather than let a structural check trip over it. From the tag
on it is a published contract: a further change bumps `FORMAT_VERSION` and
refuses the older file by version, as version 2 already does with version 1.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **The engine reads no clock, no environment, no VCS.** Every `run` id and
   `ts` in a `RunReport` is supplied by the caller. This is what makes
   `Ledger` deterministic to test — do not reach for `time()` or
   `random_bytes()` inside `src/`.
4. **Preserve the public contract.** Update README (both languages) + tests
   with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer docs
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver; a
prebuilt `composer-apcu-pcov-redis:local`-style image (see the monorepo's
[[php-mutation-local-run]] convention) is much faster for iterating.

## Invariants & gotchas

- **A `Transition` is recorded only when the status changes**
  (`LedgerState::withObservations()`). This is retention *and* compaction in
  one mechanism — an id that stays `killed` for a thousand runs costs one
  `Transition`, not one row per run. The consequence: `meta` on a `DiffEntry`
  reflects the *last status-changing* observation, not literally the most
  recent run — two data with the same status but different `meta` do not
  produce a new transition. `metaOnAnIdPresentAtBothPointsComesFromHead` in
  `LedgerTest` documents this with a scenario built to actually trigger a
  transition (two distinct bad-classified statuses), not the trap of "same
  status, different meta" that looks like it should but does not.
- **An id absent from a run's data is recorded as `AbsentStatus`, not
  dropped and not left at its last status.** See the class docblock — this
  is what lets `diff()` classify "removed from the code, no longer
  applicable" as `fixed` rather than a permanent `stillBad`.
- **Four mutation-tested equivalent mutants are documented in place, not
  chased with contrived tests:** `LocalFileStorage::read()`'s early `return
  null` (removing it still returns `null` via the `file_get_contents() ===
  false` fallthrough), `Ledger::ageAt()`'s `>` boundary check and `max(0,
  …)` floor (both provably unreachable given the loop's own invariants —
  see the docblock above `ageAt()`), and `LedgerState::transitionAt()`'s
  `break` (equivalent to `continue` because the transition list ascends by
  `runIndex` — appended in run order, and validated strictly ascending by
  `Codec::decode()` on the way in from disk). Verified by literally applying
  each mutation and running `composer test` before accepting; don't
  re-litigate without doing the same. **These four are the entire gap between
  the 98.69% the suite scores and 100** — `minMsi` is 98, and it is honest:
  every `src/` class is named in some test class's `#[Covers]`, so every one
  of them generates mutants.
- **The mutation gate is `#[Covers]`-scoped, and that is a trap.** Testo maps
  a mutant to a test by the `#[Covers]` attribute, not by what the test
  actually executes. A class no test class names in `#[Covers]` generates
  **zero** mutants and its MSI contribution is silently nothing — which is
  exactly how `Codec`, `LedgerState`, `RatchetGate`, `RetentionPolicy` and
  `DiffReport` once sat outside a "96%" gate. Adding a class to `src/`
  without a test class that `#[Covers]` it re-opens that hole. A test in
  `LedgerTest` never kills a `Codec` mutant however much `Codec` code it runs.
- **`RunReport::$run` appended twice is a no-op**, checked before any state
  mutation (`Ledger::append()`) — this is what makes a retried CI step safe
  to call `append()` from again.
- **`Ledger::trend()`'s `window: 0` means "give me zero runs", not "no
  limit."** A negative window throws. This is a deliberate, tested API
  choice, not a leftover from a simpler implementation — see
  `aZeroWindowYieldsAnEmptyTrendRatherThanNoLimit` /
  `aNegativeWindowIsRejected` in `LedgerTest`.
- **`Codec::encode()` uses `JSON_PRESERVE_ZERO_FRACTION`.** Without it, a
  metric value like `90.0` round-trips through JSON as the integer `90` —
  `RunReport::$metrics`' `int|float` distinction would silently flip on
  every load. If you touch `Codec`, keep this flag or re-derive the fix.
- **`LocalFileStorage::write()` creates its temp file with `fopen(…, 'x')`
  (`O_EXCL`), not a plain write.** The temp path is derived from `sha256(scope)`
  and the pid — predictable on a directory shared between processes. See
  `refusesToFollowASymlinkPlantedAtTheTempPath` in `LocalFileStorageTest` and
  the equivalent hardening in `rasuvaeff/property-testing-core`'s
  `FilesystemCorpus` (same lesson, same fix, applied here from the start
  rather than found by a later review).
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types, `non-empty-string`/`int<0, max>` on the **public** VOs a
  caller constructs directly (`Datum`, `RunReport`, `Transition`'s public
  fields are the one deliberate exception — see the next point).
- **`Internal\Transition`/`Internal\RunRecord`/`Internal\LedgerState` use
  plain `string`/`int`, not `non-empty-string`/`int<0, max>`, even though the
  public VOs that feed them do.** These round-trip through `Codec`'s JSON —
  Psalm cannot statically prove non-emptiness survives a file read, and
  pretending otherwise (annotating them as strict, then fighting Psalm at
  every deserialization call site) was tried and reverted. The public-facing
  contract lives on `Datum`/`RunReport`/`StoragePort` — and it is *checked*
  there, in the constructor, via `Internal\Assert`. That check is the whole
  reason this two-tier scheme is legitimate rather than a rationalisation; if
  you ever remove it, the argument above stops holding.
- **A `@param non-empty-string` docblock is not a runtime check, and Psalm
  will tell you the runtime check is dead code.** That is why validation goes
  through `Internal\Assert::nonEmpty()`/`nonEmptyKeys()`: the helper takes a
  plain `string`/`array` and asserts back out with `@psalm-assert`, so the
  body is analysable and the call site keeps its narrow type. Do not "simplify"
  it back to an inline `if ($x === '')` — Psalm fails with
  `TypeDoesNotContainType` and the only ways out are a suppression (banned) or
  dropping the annotation (worse).
- **Ids never leave `LedgerState` as raw array keys.** PHP stores a digit-only
  string key as an `int` the moment it is written, so `ids()`/`activeIds()`
  cast back with `(string)` and `$transitionsById` is private specifically so
  nothing else can read a key directly. `StableIdInterface` permits any
  non-empty string, and a caller returning `(string) crc32(...)` is squarely
  inside that — it used to kill the first `append()` with a `TypeError`.
  For the same reason the on-disk `ids` is a JSON **list** of `{id,
  transitions}` records, not an object keyed by id: an id set of `"0"`,
  `"1"`, `"2"` would otherwise re-encode as a JSON array.
- **`Codec::decode()` narrows field by field and throws `RuntimeException`
  naming the field.** Do not replace that with a `/** @var */` over
  `json_decode()` output — an annotation asserting a shape over file bytes is
  a suppression in everything but name, and it is what made `composer psalm`
  green on code that raised `TypeError`s at runtime. Untrusted input is
  `array<array-key, mixed>` and is narrowed inside.
- `examples/` is part of the public contract: `examples/basic-ledger.php` is
  a real, runnable script (`php examples/basic-ledger.php`, no server
  needed). Keep it green and update `examples/README.md` when usage changes.
- **The README `Usage` block is executed by `composer docs`, in both
  languages.** It is fenced ```` ```php doc-exec ```` and its `// =>` comments
  are assertions compared via `var_export()`; `@docs` is part of `composer
  build`, so a sample that drifts from the API fails the build. `doc-exec`
  splits statements on a `}` that returns bracket depth to zero, so a
  `doc-exec` block must avoid brace-bearing *expressions* and multi-part
  constructs — `function () {}`, `match`, `if/else`, `try/catch`, anonymous
  classes all mis-split, and a compile error kills every block in the scope
  group, not one. Arrow functions, plain `if`, `foreach` and array literals
  are fine. Quote a signature in an ordinary ```` ```php ```` fence (doc-exec
  ignores those) rather than making it executable — re-declaring a type the
  autoloader already provides is a fatal error.
- **Benchmarks are comparative by construction.** `Testo\Bench` requires
  `callables`, so `benchmarks/LedgerBench.php` measures 4 000 ids against
  1 000 and the *ratio* is the signal: ~4× is linear, ~16× means the
  quadratic `append()` (an `in_array()` duplicate scan, one id-table copy per
  observation) has come back.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the
  comment. Workflows also carry `permissions: { contents: read }` at
  workflow level and `persist-credentials: false` on every
  `actions/checkout` step. Verify with `zizmor --persona=auditor .github/` —
  must report no `unpinned-uses`, `excessive-permissions`, or `artipacked`
  findings.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit;
  and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release
  safety, also run `make release-check`. Paste the output.
