<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests\Support;

/**
 * A stream wrapper over a directory that accepts only half of every write,
 * the way a nearly full disk does, so the short-write branch of
 * `LocalFileStorage` can be reached without filling a real one.
 *
 * @internal
 */
final class ShortWriteWrapper
{
    public const string PROTOCOL = 'qlshort';

    /** @var resource|null */
    public $context;

    public static function register(): void
    {
        if (!\in_array(self::PROTOCOL, stream_get_wrappers(), strict: true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    public static function unregister(): void
    {
        if (\in_array(self::PROTOCOL, stream_get_wrappers(), strict: true)) {
            stream_wrapper_unregister(self::PROTOCOL);
        }
    }

    public function mkdir(): bool
    {
        return true;
    }

    /**
     * @return array<string, int>|false
     */
    public function url_stat(): array|false
    {
        return ['mode' => 0o040_755];
    }

    public function stream_open(): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return intdiv(\strlen($data), 2);
    }

    public function unlink(): bool
    {
        return true;
    }
}
