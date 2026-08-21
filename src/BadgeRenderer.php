<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * Turns a {@see Badge} into the bytes of one file. `BadgeSvg` is the
 * implementation this package ships; a caller wanting a shields.io endpoint
 * document, a PNG or a terminal line writes its own and keeps the rest of
 * the pipeline unchanged.
 *
 * @api
 */
interface BadgeRenderer
{
    /**
     * @return non-empty-string
     */
    public function render(Badge $badge): string;
}
