<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * The six-step palette shields.io uses, so a badge this package renders sits
 * next to the CI and coverage badges of a README without looking foreign.
 *
 * @api
 */
enum BadgeColor: string
{
    case BrightGreen = 'brightgreen';
    case Green = 'green';
    case YellowGreen = 'yellowgreen';
    case Yellow = 'yellow';
    case Orange = 'orange';
    case Red = 'red';

    /**
     * @return non-empty-string
     */
    public function hex(): string
    {
        return match ($this) {
            self::BrightGreen => '#4c1',
            self::Green => '#97ca00',
            self::YellowGreen => '#a4a61d',
            self::Yellow => '#dfb317',
            self::Orange => '#fe7d37',
            self::Red => '#e05d44',
        };
    }

    /**
     * The step a percentage falls into. The thresholds are shields.io's own
     * for coverage badges; a value outside `0..100` is clamped rather than
     * refused — a badge is a display, and a caller that computed 103% has a
     * bug this class must not turn into a fatal one.
     */
    public static function forPercentage(float $percentage): self
    {
        $clamped = max(0.0, min(100.0, $percentage));

        if ($clamped >= 95.0) {
            return self::BrightGreen;
        }

        if ($clamped >= 90.0) {
            return self::Green;
        }

        if ($clamped >= 75.0) {
            return self::YellowGreen;
        }

        if ($clamped >= 60.0) {
            return self::Yellow;
        }

        if ($clamped >= 40.0) {
            return self::Orange;
        }

        return self::Red;
    }
}
