# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.2.0 — 2026-08-21

- Badges: `Badge` (label, message, colour), `BadgeColor` (the six-step
  shields.io palette, with `forPercentage()` thresholds), `BadgeRenderer`
  (the output port) and `BadgeSvg` (a self-contained flat SVG — no external
  service, so it renders on an offline runner and inside a private network).
  `Badge::fromTrend()` turns a metric's most recent point into a badge and
  answers an empty trend with `n/a` in red rather than an exception.

## 0.1.0 — 2026-08-21

- Initial engine: `Datum`, `RunReport`, `StableIdInterface`/`DefaultStableId`,
  `StoragePort`/`LocalFileStorage`, `RetentionPolicy`, `Ledger` (`append`,
  `diff`, `trend`), `DiffReport`/`DiffEntry`/`StillBadEntry`, `RatchetGate`,
  `Trend`/`TrendPoint`.
- Fixed: an id function returning a digit-only string (`(string) crc32(...)`,
  inside what `StableIdInterface` permits) made the first `append()` fail with
  a `TypeError` and left the scope unusable. Ids are now cast back to `string`
  on the way out of `LedgerState`, and the on-disk `ids` block is a JSON list
  of records rather than an object keyed by id.
- Fixed: `DiffReport::$unchangedCount` counted ids observed at *neither* of the
  compared runs — a tombstone still inside its retention window inflated it,
  and `totalIdsCompared()` with it, by up to `tombstoneTtlRuns` runs' worth of
  removed ids. Such an id is now in no bucket at all, as the documentation
  already claimed.
- Fixed: `meta` on an id that was removed between `base` and `head` now comes
  from `base`, as documented, instead of from the empty bookkeeping transition.
- The on-disk format is version 2: `ids` is a JSON list of `{id, transitions}`
  records rather than an object keyed by id, and `kind` sits on each transition.
  A version-1 file is refused by version.
- `Codec::decode()` validates the decoded payload field by field and raises
  `RuntimeException` naming the offending field, including a strictly ascending
  `runIndex` order. Previously only the format version was checked: a truncated
  file surfaced as a `TypeError` from an internal constructor, and an id record
  with no transitions re-serialized as `"kind":null`, corrupting the file
  further on every save.
- `Datum` and `RunReport` validate their inputs: empty `kind`, `signature`,
  `status`, `run`, `scope` or metric name now throws `InvalidArgumentException`,
  as does an `append()` whose id function returns an empty string. `Datum`
  also rejects the ledger's internal absent sentinel as a domain status, and a
  metric name PHP would store as an integer array key (`'1'`) is rejected
  rather than silently re-typed. `Trend` enforces the same non-empty contract
  on its own `metric`, so `Ledger::trend($scope, '')` throws instead of
  answering with an empty trend.
- `LocalFileStorage::write()` reports a directory it cannot create instead of
  misattributing the failure to the temp file two steps later.
- `Ledger::append()` is no longer quadratic in the number of ids: the
  duplicate check is a hash lookup, and a run's observations are applied in one
  state copy rather than one per datum. `benchmarks/LedgerBench.php` measures
  the scaling.
- Documented: `diff()` is directional and does not sort `base`/`head`;
  `append()` is a read-modify-write and is not safe to run concurrently against
  one scope.
