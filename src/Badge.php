<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

use Rasuvaeff\QualityLedger\Internal\Assert;

/**
 * What a badge says, with no opinion on how it is drawn: a label ("msi"), a
 * message ("98.7%") and a colour. {@see BadgeRenderer} turns one into bytes.
 *
 * Deliberately not built from a {@see Trend} or a {@see GateResult} inside
 * this class: which metric a badge should show, whether it is a percentage,
 * and what counts as good are the caller's domain, not the ledger's. The one
 * concession is {@see forPercentage()}, because "a percentage, coloured by
 * the usual thresholds" is what nearly every quality badge is.
 *
 * @api
 */
final readonly class Badge
{
    /**
     * @param non-empty-string $label Left half, e.g. `msi`.
     * @param non-empty-string $message Right half, e.g. `98.7%`.
     */
    public function __construct(
        public string $label,
        public string $message,
        public BadgeColor $color,
    ) {
        Assert::nonEmpty($this->label, 'label');
        Assert::nonEmpty($this->message, 'message');
    }

    /**
     * A percentage rendered with one decimal and coloured by
     * {@see BadgeColor::forPercentage()} — `msi` at `98.65` reads `98.7%`.
     *
     * @param non-empty-string $label
     */
    public static function forPercentage(string $label, float $percentage): self
    {
        return new self(
            label: $label,
            message: \sprintf('%.1f%%', $percentage),
            color: BadgeColor::forPercentage($percentage),
        );
    }

    /**
     * The most recent point of a trend, as a percentage badge. An empty trend
     * is `n/a` in red rather than an exception: a badge for a scope with no
     * runs yet is a legitimate state of a CI job, and the alternative is
     * every caller writing the same `count($points) === 0` branch.
     *
     * @param non-empty-string|null $label Defaults to the trend's metric name.
     */
    public static function fromTrend(Trend $trend, ?string $label = null): self
    {
        $name = $label ?? $trend->metric;
        $last = $trend->points === [] ? null : $trend->points[\count($trend->points) - 1];

        if ($last === null) {
            return new self(label: $name, message: 'n/a', color: BadgeColor::Red);
        }

        return self::forPercentage($name, (float) $last->value);
    }
}
