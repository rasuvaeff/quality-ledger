<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Internal;

/**
 * Runtime checks for contracts the public types only *document*.
 *
 * Why a helper rather than an inline `if ($x === '')` in each constructor:
 * a `@param non-empty-string` docblock makes Psalm treat the inline check as
 * dead code (`TypeDoesNotContainType`) — the annotation binds only the call
 * sites Psalm can see, which is precisely nobody's third-party
 * {@see \Rasuvaeff\QualityLedger\StableIdInterface} implementation. Taking the
 * value through a plain `string` parameter and asserting back out with
 * `@psalm-assert` keeps both halves: the static promise at the call site and
 * an actual check at runtime.
 *
 * @internal
 */
final readonly class Assert
{
    private function __construct()
    {
        // Static helper; not instantiable.
    }

    /**
     * @psalm-assert non-empty-string $value
     */
    public static function nonEmpty(string $value, string $name): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException($name . ' must not be empty');
        }
    }

    /**
     * @param array<array-key, mixed> $values
     * @psalm-assert array<non-empty-string, mixed> $values
     */
    public static function nonEmptyKeys(array $values, string $name): void
    {
        foreach (array_keys($values) as $key) {
            if ($key === '') {
                throw new \InvalidArgumentException($name . ' must not be empty');
            }
        }
    }
}
