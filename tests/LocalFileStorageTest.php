<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\QualityLedger\LocalFileStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(LocalFileStorage::class)]
final class LocalFileStorageTest
{
    private string $dir;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/quality-ledger-test-' . bin2hex(random_bytes(8));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_link($file) || is_file($file) ? unlink($file) : null;
        }

        @rmdir($this->dir);
    }

    /**
     * A directory that cannot be created is the only genuine failure of the
     * mkdir step — an existing directory and a concurrent writer's are both
     * fine. Reproduced with a plain file standing where a parent directory
     * would have to go, so `mkdir(recursive: true)` cannot succeed and never
     * will.
     */
    public function aDirectoryThatCannotBeCreatedIsReportedAsSuch(): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0o755, recursive: true);
        }

        $blocker = $this->dir . '/blocker';
        file_put_contents($blocker, 'not a directory');

        $storage = new LocalFileStorage($blocker . '/ledger');

        try {
            $storage->write('acme/widgets', '{}');

            Assert::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            Assert::string($e->getMessage())->contains('Could not create the ledger directory');
        }
    }

    /**
     * The complement, and the mutation-relevant half: writing into a
     * directory that already exists must not be mistaken for a failed
     * creation.
     */
    public function writingIntoAnExistingDirectorySucceeds(): void
    {
        mkdir($this->dir, 0o755, recursive: true);

        $storage = new LocalFileStorage($this->dir);
        $storage->write('acme/widgets', '{"v":1}');

        Assert::same($storage->read('acme/widgets'), '{"v":1}');
    }

    public function unknownScopeReadsAsNull(): void
    {
        $storage = new LocalFileStorage($this->dir);

        Assert::null($storage->read('nobody/here'));
    }

    public function writeThenReadRoundTrips(): void
    {
        $storage = new LocalFileStorage($this->dir);
        $storage->write('acme/widgets', '{"hello":"world"}');

        Assert::same($storage->read('acme/widgets'), '{"hello":"world"}');
    }

    public function theFileLivesAtTheSha256BasedPath(): void
    {
        $storage = new LocalFileStorage($this->dir);
        $storage->write('acme/widgets', 'payload');

        $expected = $this->dir . '/' . hash('sha256', 'acme/widgets') . '.json';

        Assert::true(is_file($expected));
        Assert::same(file_get_contents($expected), 'payload');
    }

    public function theDirectoryIsCreatedWithMode0755(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $previousUmask = umask(0);

        try {
            (new LocalFileStorage($this->dir))->write('acme/widgets', 'x');
        } finally {
            umask($previousUmask);
        }

        Assert::same(fileperms($this->dir) & 0o7777, 0o755);
    }

    public function creatingTheDirectoryOnFirstWriteIsIdempotent(): void
    {
        $storage = new LocalFileStorage($this->dir);
        $storage->write('a', '1');
        $storage->write('b', '2');

        Assert::same($storage->read('a'), '1');
        Assert::same($storage->read('b'), '2');
    }

    public function overwritingAScopeReplacesItsContent(): void
    {
        $storage = new LocalFileStorage($this->dir);
        $storage->write('acme/widgets', 'first');
        $storage->write('acme/widgets', 'second');

        Assert::same($storage->read('acme/widgets'), 'second');
    }

    /**
     * The temp path is `.{sha256(scope)}.{pid}.tmp` — predictable on a
     * directory shared between processes. A symlink pre-planted at that exact
     * path must be refused (`O_EXCL`), never written through: writing through
     * it would let an attacker who can predict the path overwrite an
     * arbitrary file the victim process can write to.
     */
    public function refusesToFollowASymlinkPlantedAtTheTempPath(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        mkdir($this->dir, 0o755, recursive: true);

        $victim = $this->dir . '/victim.txt';
        file_put_contents($victim, 'original');

        $tmp = $this->dir . '/.' . hash('sha256', 'acme/widgets') . '.' . getmypid() . '.tmp';
        symlink($victim, $tmp);

        try {
            $storage = new LocalFileStorage($this->dir);

            try {
                $storage->write('acme/widgets', 'attacker-controlled');
                Assert::fail('expected a RuntimeException');
            } catch (\RuntimeException) {
                // Expected: the exclusive create refused the existing symlink.
            }

            Assert::same(file_get_contents($victim), 'original');
        } finally {
            @unlink($tmp);
        }
    }
}
