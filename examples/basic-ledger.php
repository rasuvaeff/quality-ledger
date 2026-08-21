<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\DefaultStableId;
use Rasuvaeff\QualityLedger\Ledger;
use Rasuvaeff\QualityLedger\LocalFileStorage;
use Rasuvaeff\QualityLedger\RatchetGate;
use Rasuvaeff\QualityLedger\RunReport;

$dir = sys_get_temp_dir() . '/quality-ledger-example-' . bin2hex(random_bytes(4));
$ledger = new Ledger(id: new DefaultStableId(), storage: new LocalFileStorage($dir));

$isBad = static fn(string $status): bool => $status === 'escaped';

// Run 1: two mutants, both killed.
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

// Run 2: the second mutant now escapes — a real regression.
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

$diff = $ledger->diff(scope: 'acme/widgets', base: 'run-1', head: 'run-2', isBad: $isBad);
$gate = (new RatchetGate())->evaluate($diff);

printf("newBad: %d, fixed: %d, stillBad: %d, unchanged: %d\n", \count($diff->newBad), \count($diff->fixed), \count($diff->stillBad), $diff->unchangedCount);
printf("gate ok: %s\n", $gate->ok ? 'yes' : 'no');

foreach ($gate->regressions as $entry) {
    printf("  regression: %s (%s)\n", $entry->id, $entry->kind);
}

$trend = $ledger->trend(scope: 'acme/widgets', metric: 'msi');

foreach ($trend->points as $point) {
    printf("trend %s: msi=%s\n", $point->run, $point->value);
}

// The example owns this directory, so it cleans it up; a real caller points
// LocalFileStorage at a directory that outlives the process (that is the
// point of a ledger).
foreach (glob($dir . '/*') ?: [] as $file) {
    unlink($file);
}

rmdir($dir);
