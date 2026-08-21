<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * One file per scope inside a directory, written atomically (temp file +
 * rename). The temp file is created with `O_EXCL` (`fopen(…, 'x')`): its name
 * is derived from the scope, so on a directory shared between CI jobs a
 * pre-planted symlink at that predictable path must not be followed and
 * overwritten — the exclusive create refuses the existing path instead of
 * writing through it.
 *
 * @api
 */
final readonly class LocalFileStorage implements StoragePort
{
    public function __construct(
        private string $directory,
    ) {}

    #[\Override]
    public function read(string $scope): ?string
    {
        $path = $this->path($scope);

        // Mutation-tested and confirmed equivalent to removing this early
        // return entirely: file_get_contents() on a missing path returns
        // false (after an E_WARNING neither raised to an exception nor
        // asserted on here), and the ternary two lines down already maps
        // false to null — the guard exists for clarity and to skip the
        // pointless syscall, not because dropping it changes the result.
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    #[\Override]
    public function write(string $scope, string $bytes): void
    {
        // mkdir-then-recheck, with no is_dir() guard in front of it: the
        // directory already existing and a concurrent writer having just
        // created it are the same failure from mkdir()'s point of view, and
        // both are success from this method's. Only "mkdir failed and the
        // directory still is not there" — no write permission on the parent,
        // a file in the way — is a real error, and it is reported here rather
        // than three lines down as "could not create a temp file", which
        // would name the wrong cause.
        if (!@mkdir($this->directory, 0o755, recursive: true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Could not create the ledger directory "%s"', $this->directory));
        }

        $pid = getmypid();

        if ($pid === false) {
            throw new \RuntimeException('Could not determine the current process id');
        }

        $path = $this->path($scope);
        $tmp = $this->directory . '/.' . hash('sha256', $scope) . '.' . $pid . '.tmp';

        $handle = @fopen($tmp, 'x');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Could not create a temp file for scope "%s"', $scope));
        }

        $written = @fwrite($handle, $bytes);
        @fclose($handle);

        if ($written !== \strlen($bytes)) {
            @unlink($tmp);

            throw new \RuntimeException(sprintf('Short write while saving the ledger for scope "%s"', $scope));
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw new \RuntimeException(sprintf('Could not commit the ledger for scope "%s"', $scope));
        }
    }

    private function path(string $scope): string
    {
        return $this->directory . '/' . hash('sha256', $scope) . '.json';
    }
}
