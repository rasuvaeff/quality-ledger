<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\QualityLedger\Badge;
use Rasuvaeff\QualityLedger\BadgeColor;
use Rasuvaeff\QualityLedger\Internal\Assert as Guard;
use Rasuvaeff\QualityLedger\Trend;
use Rasuvaeff\QualityLedger\TrendPoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Badge::class)]
#[Covers(Guard::class)]
final class BadgeTest
{
    public function aBadgeCarriesItsLabelMessageAndColour(): void
    {
        $badge = new Badge(label: 'msi', message: '98.7%', color: BadgeColor::BrightGreen);

        Assert::same($badge->label, 'msi');
        Assert::same($badge->message, '98.7%');
        Assert::same($badge->color, BadgeColor::BrightGreen);
    }

    public function anEmptyLabelIsRejected(): void
    {
        try {
            new Badge(label: '', message: 'x', color: BadgeColor::Red);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('label must not be empty');
        }
    }

    public function anEmptyMessageIsRejected(): void
    {
        try {
            new Badge(label: 'msi', message: '', color: BadgeColor::Red);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('message must not be empty');
        }
    }

    public function forPercentageRendersOneDecimalAndPicksTheColour(): void
    {
        $badge = Badge::forPercentage('msi', 98.65);

        Assert::same($badge->label, 'msi');
        Assert::same($badge->message, '98.7%');
        Assert::same($badge->color, BadgeColor::BrightGreen);
    }

    public function forPercentageKeepsTheDecimalOnAWholeNumber(): void
    {
        Assert::same(Badge::forPercentage('msi', 90.0)->message, '90.0%');
    }

    public function fromTrendUsesTheMostRecentPoint(): void
    {
        $trend = new Trend('msi', [
            new TrendPoint(run: 'r1', ts: 1, value: 40.0),
            new TrendPoint(run: 'r2', ts: 2, value: 96.5),
        ]);

        $badge = Badge::fromTrend($trend);

        Assert::same($badge->label, 'msi');
        Assert::same($badge->message, '96.5%');
        Assert::same($badge->color, BadgeColor::BrightGreen);
    }

    public function fromTrendAcceptsAnIntValuedPoint(): void
    {
        $trend = new Trend('coverage', [new TrendPoint(run: 'r1', ts: 1, value: 61)]);

        Assert::same(Badge::fromTrend($trend)->message, '61.0%');
    }

    public function fromTrendLabelsWithTheMetricUnlessToldOtherwise(): void
    {
        $trend = new Trend('msi', [new TrendPoint(run: 'r1', ts: 1, value: 50.0)]);

        Assert::same(Badge::fromTrend($trend)->label, 'msi');
        Assert::same(Badge::fromTrend($trend, 'mutation score')->label, 'mutation score');
    }

    /**
     * A scope with no runs yet is a legitimate state of a CI job — the first
     * one, in fact — so it renders rather than throws.
     */
    public function anEmptyTrendIsNotAvailableInRed(): void
    {
        $badge = Badge::fromTrend(new Trend('msi', []));

        Assert::same($badge->message, 'n/a');
        Assert::same($badge->color, BadgeColor::Red);
        Assert::same($badge->label, 'msi');
    }
}
