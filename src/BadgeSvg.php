<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger;

/**
 * A self-contained flat badge, in the geometry and palette shields.io uses,
 * with no external service in the loop: the SVG is the whole artifact, so it
 * works in an offline runner, inside a private network, and as a build
 * artifact that no third party has to fetch.
 *
 * The one approximation is text width. A faithful renderer measures every
 * glyph in the font; this one charges a fixed 6.6px per character at the
 * 11px font size, which is the average width of DejaVu Sans over the ASCII
 * a quality badge actually carries (digits, `%`, lowercase). A label of
 * mostly `i`/`l` therefore renders slightly wide and one of mostly `W`
 * slightly narrow — a cosmetic difference, and the alternative is shipping a
 * glyph table this package would then have to maintain.
 *
 * @api
 */
final readonly class BadgeSvg implements BadgeRenderer
{
    private const int HEIGHT = 20;
    private const float CHAR_WIDTH = 6.6;
    private const float SIDE_PADDING = 10.0;

    #[\Override]
    public function render(Badge $badge): string
    {
        $labelWidth = self::textWidth($badge->label);
        $messageWidth = self::textWidth($badge->message);
        $total = $labelWidth + $messageWidth;

        $label = self::escape($badge->label);
        $message = self::escape($badge->message);

        // Text is anchored at the middle of its half, at 10× scale with a
        // transform, exactly as shields does: it keeps the glyph positions
        // integral at the scale the font hinting works at, instead of
        // rounding a fractional x down to a visibly off-centre label.
        return \sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" role="img" aria-label="%s: %s">'
            . '<title>%s: %s</title>'
            . '<linearGradient id="s" x2="0" y2="100%%"><stop offset="0" stop-color="#bbb" stop-opacity=".1"/><stop offset="1" stop-opacity=".1"/></linearGradient>'
            . '<clipPath id="r"><rect width="%d" height="%d" rx="3" fill="#fff"/></clipPath>'
            . '<g clip-path="url(#r)">'
            . '<rect width="%d" height="%d" fill="#555"/>'
            . '<rect x="%d" width="%d" height="%d" fill="%s"/>'
            . '<rect width="%d" height="%d" fill="url(#s)"/>'
            . '</g>'
            . '<g fill="#fff" text-anchor="middle" font-family="Verdana,DejaVu Sans,Geneva,sans-serif" font-size="110" text-rendering="geometricPrecision">'
            . '<text x="%d" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)">%s</text>'
            . '<text x="%d" y="140" transform="scale(.1)">%s</text>'
            . '<text x="%d" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)">%s</text>'
            . '<text x="%d" y="140" transform="scale(.1)">%s</text>'
            . '</g>'
            . '</svg>',
            $total,
            self::HEIGHT,
            $label,
            $message,
            $label,
            $message,
            $total,
            self::HEIGHT,
            $labelWidth,
            self::HEIGHT,
            $labelWidth,
            $messageWidth,
            self::HEIGHT,
            $badge->color->hex(),
            $total,
            self::HEIGHT,
            $labelWidth * 5,
            $label,
            $labelWidth * 5,
            $label,
            ($labelWidth * 10) + ($messageWidth * 5),
            $message,
            ($labelWidth * 10) + ($messageWidth * 5),
            $message,
        );
    }

    private static function textWidth(string $text): int
    {
        // preg_match_all with /u rather than mb_strlen(): counting code
        // points must not add ext-mbstring to a package that otherwise needs
        // nothing beyond core. A label that is not valid UTF-8 falls back to
        // its byte length, which is never smaller — and is drawn as
        // replacement characters, see escape().
        $characters = preg_match_all('/./u', $text);
        $length = $characters === false ? \strlen($text) : $characters;

        return (int) round(((float) $length * self::CHAR_WIDTH) + self::SIDE_PADDING);
    }

    private static function escape(string $text): string
    {
        // ENT_SUBSTITUTE matters: without it htmlspecialchars() answers an
        // invalid UTF-8 byte with an empty string, and a badge whose label
        // silently vanished is worse than one showing U+FFFD.
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_XML1 | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
