<?php

declare(strict_types=1);

namespace Rasuvaeff\QualityLedger\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\QualityLedger\Badge;
use Rasuvaeff\QualityLedger\BadgeColor;
use Rasuvaeff\QualityLedger\BadgeSvg;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(BadgeSvg::class)]
final class BadgeSvgTest
{
    private BadgeSvg $renderer;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->renderer = new BadgeSvg();
    }

    public function theDocumentIsOneSvgElement(): void
    {
        $svg = $this->renderer->render(new Badge('msi', '98.7%', BadgeColor::BrightGreen));

        Assert::true(str_starts_with($svg, '<svg xmlns="http://www.w3.org/2000/svg"'));
        Assert::true(str_ends_with($svg, '</svg>'));
        Assert::same(substr_count($svg, '<svg'), 1);
    }

    public function bothHalvesOfTheTextAreDrawn(): void
    {
        $svg = $this->renderer->render(new Badge('msi', '98.7%', BadgeColor::BrightGreen));

        // Twice each: the shadow copy and the foreground copy. The <title>
        // carries both halves in one string, so it matches neither.
        Assert::same(substr_count($svg, '>msi<'), 2);
        Assert::same(substr_count($svg, '>98.7%<'), 2);
    }

    public function theColourIsTheStepsHex(): void
    {
        $svg = $this->renderer->render(new Badge('msi', '40.0%', BadgeColor::Orange));

        Assert::true(str_contains($svg, 'fill="#fe7d37"'));
    }

    public function theBadgeIsLabelledForAScreenReader(): void
    {
        $svg = $this->renderer->render(new Badge('msi', '98.7%', BadgeColor::BrightGreen));

        Assert::true(str_contains($svg, 'role="img"'));
        Assert::true(str_contains($svg, 'aria-label="msi: 98.7%"'));
        Assert::true(str_contains($svg, '<title>msi: 98.7%</title>'));
    }

    /**
     * The label and the message reach an XML document as text: a caller
     * naming a metric `a<b` must not be able to inject markup into it.
     */
    public function markupInTheTextIsEscaped(): void
    {
        $svg = $this->renderer->render(new Badge('a<b&c', '"x"', BadgeColor::Red));

        Assert::true(str_contains($svg, 'a&lt;b&amp;c'));
        Assert::true(str_contains($svg, '&quot;x&quot;'));
        Assert::false(str_contains($svg, 'a<b&c'));
        Assert::same(substr_count($svg, '<svg'), 1);
    }

    /**
     * Invalid UTF-8 renders as replacement characters rather than blanking
     * the half it appears in: htmlspecialchars() without ENT_SUBSTITUTE
     * answers such a byte with an empty string.
     */
    public function invalidUtf8DoesNotBlankTheLabel(): void
    {
        $svg = $this->renderer->render(new Badge("m\xFFsi", '1.0%', BadgeColor::Red));

        Assert::true(str_contains($svg, "m\u{FFFD}si"));
    }

    public function aLongerTextMakesAWiderBadge(): void
    {
        $narrow = $this->renderer->render(new Badge('msi', '1.0%', BadgeColor::Red));
        $wide = $this->renderer->render(new Badge('mutation score index', '1.0%', BadgeColor::Red));

        Assert::true(self::width($wide) > self::width($narrow));
    }

    /**
     * The width is `round(chars * 6.6 + 10)` per half, and the two halves
     * add up to the document width — pinned exactly, because an off-by-one
     * here is what makes a badge's text sit outside its own coloured box.
     */
    public function theWidthIsTheSumOfBothHalves(): void
    {
        $svg = $this->renderer->render(new Badge('msi', '98.7%', BadgeColor::BrightGreen));

        // 'msi' = 3 chars -> round(3 * 6.6 + 10) = 30
        // '98.7%' = 5 chars -> round(5 * 6.6 + 10) = 43
        Assert::same(self::width($svg), 73);
        Assert::true(str_contains($svg, '<rect width="30" height="20" fill="#555"/>'));
        Assert::true(str_contains($svg, '<rect x="30" width="43" height="20" fill="#4c1"/>'));
    }

    public function aMultibyteLabelIsMeasuredInCharactersNotBytes(): void
    {
        $ascii = $this->renderer->render(new Badge('abcd', '1.0%', BadgeColor::Red));
        $cyrillic = $this->renderer->render(new Badge('мутанты', '1.0%', BadgeColor::Red));

        // Both messages are 4 characters (36 wide), so the difference is the
        // label alone: 4 ASCII characters (36) against 7 Cyrillic ones (56).
        // Measured in bytes the Cyrillic label would count 14 characters.
        Assert::same(self::width($ascii), 72);
        Assert::same(self::width($cyrillic), 92);
    }

    /**
     * The whole document, pinned byte for byte. Every coordinate in a badge
     * is derived arithmetic — the text is anchored at the middle of its half
     * at ten times the scale — and structural assertions cannot tell a
     * correct multiplier from a wrong one: the SVG still parses, it just
     * draws the label outside its own coloured box. Update this string
     * deliberately when the geometry changes; do not relax it.
     */
    public function theRenderedDocumentIsPinned(): void
    {
        $expected = '<svg xmlns="http://www.w3.org/2000/svg" width="73" height="20" role="img" aria-label="msi: 98.7%">'
            . '<title>msi: 98.7%</title>'
            . '<linearGradient id="s" x2="0" y2="100%"><stop offset="0" stop-color="#bbb" stop-opacity=".1"/><stop offset="1" stop-opacity=".1"/></linearGradient>'
            . '<clipPath id="r"><rect width="73" height="20" rx="3" fill="#fff"/></clipPath>'
            . '<g clip-path="url(#r)">'
            . '<rect width="30" height="20" fill="#555"/>'
            . '<rect x="30" width="43" height="20" fill="#4c1"/>'
            . '<rect width="73" height="20" fill="url(#s)"/>'
            . '</g>'
            . '<g fill="#fff" text-anchor="middle" font-family="Verdana,DejaVu Sans,Geneva,sans-serif" font-size="110" text-rendering="geometricPrecision">'
            . '<text x="150" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)">msi</text>'
            . '<text x="150" y="140" transform="scale(.1)">msi</text>'
            . '<text x="515" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)">98.7%</text>'
            . '<text x="515" y="140" transform="scale(.1)">98.7%</text>'
            . '</g>'
            . '</svg>';

        Assert::same($this->renderer->render(new Badge('msi', '98.7%', BadgeColor::BrightGreen)), $expected);
    }

    /**
     * A second, differently sized badge: with one pinned document a
     * multiplier and a constant offset are indistinguishable — `x = w * 5`
     * and `x = w + 120` both produce 150 for a 30-wide label.
     */
    public function aWiderBadgeMovesBothTextAnchors(): void
    {
        $svg = $this->renderer->render(new Badge('coverage', '100.0%', BadgeColor::BrightGreen));

        // 'coverage' = 8 chars -> round(8 * 6.6 + 10) = 63
        // '100.0%'   = 6 chars -> round(6 * 6.6 + 10) = 50
        Assert::same(self::width($svg), 113);
        Assert::true(str_contains($svg, '<text x="315" y="140" transform="scale(.1)">coverage</text>'));
        Assert::true(str_contains($svg, '<text x="880" y="140" transform="scale(.1)">100.0%</text>'));
    }

    public function renderingIsDeterministic(): void
    {
        $badge = new Badge('msi', '98.7%', BadgeColor::BrightGreen);

        Assert::same($this->renderer->render($badge), $this->renderer->render($badge));
    }

    /**
     * Whatever the caller's label and message, the document stays one well
     * formed `<svg>` element and nothing from the input escapes as markup.
     */
    #[Property(runs: 200, timeoutMs: 1000)]
    public function noInputEscapesAsMarkup(string $label, string $message): void
    {
        $svg = $this->renderer->render(new Badge($label, $message, BadgeColor::Green));

        Assert::same(substr_count($svg, '<svg'), 1);
        Assert::true(str_ends_with($svg, '</svg>'));
        Assert::same(substr_count($svg, '<text'), 4);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function noInputEscapesAsMarkupGenerators(): array
    {
        $text = Gen::stringFrom("<>&\"'/ msi%0123abcюж", minLength: 1, maxLength: 20);

        return ['label' => $text, 'message' => $text];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function noInputEscapesAsMarkupExamples(): iterable
    {
        yield 'a closing tag in the label' => ['</text><script>x</script>', '1%'];
        yield 'an entity in the message' => ['msi', '&lt;'];
        yield 'quotes on both sides' => ['"a"', "'b'"];
    }

    private static function width(string $svg): int
    {
        $matched = preg_match('/^<svg [^>]*width="(\d+)"/', $svg, $matches);
        Assert::same($matched, 1);

        return (int) $matches[1];
    }
}
