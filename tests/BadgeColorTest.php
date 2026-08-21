<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\QualityLedger\BadgeColor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(BadgeColor::class)]
final class BadgeColorTest
{
    #[DataProvider('percentageProvider')]
    public function aPercentageMapsToItsStep(float $percentage, BadgeColor $expected): void
    {
        Assert::same(BadgeColor::forPercentage($percentage), $expected);
    }

    /**
     * Every threshold is asserted from both sides, because a mutation that
     * turns `>=` into `>` (or moves a boundary by one step) is invisible to
     * a test that only checks the middle of each band.
     *
     * @return iterable<string, array{float, BadgeColor}>
     */
    public static function percentageProvider(): iterable
    {
        yield '100' => [100.0, BadgeColor::BrightGreen];
        yield 'exactly 95' => [95.0, BadgeColor::BrightGreen];
        yield 'just under 95' => [94.9, BadgeColor::Green];
        yield 'exactly 90' => [90.0, BadgeColor::Green];
        yield 'just under 90' => [89.9, BadgeColor::YellowGreen];
        yield 'exactly 75' => [75.0, BadgeColor::YellowGreen];
        yield 'just under 75' => [74.9, BadgeColor::Yellow];
        yield 'exactly 60' => [60.0, BadgeColor::Yellow];
        yield 'just under 60' => [59.9, BadgeColor::Orange];
        yield 'exactly 40' => [40.0, BadgeColor::Orange];
        yield 'just under 40' => [39.9, BadgeColor::Red];
        yield 'zero' => [0.0, BadgeColor::Red];
    }

    /**
     * A caller that computed 103% has a bug; a badge renderer is not the
     * place to turn it into a fatal error.
     */
    public function anOutOfRangePercentageIsClampedNotRefused(): void
    {
        Assert::same(BadgeColor::forPercentage(150.0), BadgeColor::BrightGreen);
        Assert::same(BadgeColor::forPercentage(-1.0), BadgeColor::Red);
    }

    #[DataProvider('hexProvider')]
    public function eachStepHasItsShieldsHex(BadgeColor $color, string $hex): void
    {
        Assert::same($color->hex(), $hex);
    }

    /**
     * @return iterable<string, array{BadgeColor, string}>
     */
    public static function hexProvider(): iterable
    {
        yield 'brightgreen' => [BadgeColor::BrightGreen, '#4c1'];
        yield 'green' => [BadgeColor::Green, '#97ca00'];
        yield 'yellowgreen' => [BadgeColor::YellowGreen, '#a4a61d'];
        yield 'yellow' => [BadgeColor::Yellow, '#dfb317'];
        yield 'orange' => [BadgeColor::Orange, '#fe7d37'];
        yield 'red' => [BadgeColor::Red, '#e05d44'];
    }

    public function theCaseValueIsTheShieldsColourName(): void
    {
        Assert::same(BadgeColor::YellowGreen->value, 'yellowgreen');
    }
}
