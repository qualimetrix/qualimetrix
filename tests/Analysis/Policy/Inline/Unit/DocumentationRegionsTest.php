<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Inline\Contract\DocumentationRegions;

/**
 * Which parts of a comment quote a directive rather than write one.
 *
 * Every case here is a framing an author can put around the same tag, and each
 * one is checked in both directions: a quoted tag must disappear, and a written
 * tag must survive whatever quoting stands elsewhere in the same comment. The
 * pairing rule these test is what makes the two independent — while regions
 * were paired across the whole comment, one stray backtick decided both.
 */
#[CoversClass(DocumentationRegions::class)]
final class DocumentationRegionsTest extends TestCase
{
    private const string TAG = '@qmx-ignore complexity.ccn';

    #[Test]
    public function itBlanksAQuotedTagOnOneLine(): void
    {
        $masked = DocumentationRegions::mask('     * Use `' . self::TAG . '` to silence it.');

        self::assertStringNotContainsString('@qmx-ignore', $masked);
    }

    #[Test]
    public function itLeavesAnUnpairedBacktickAsAnOrdinaryCharacter(): void
    {
        $masked = DocumentationRegions::mask(
            "     * The ` character is special.\n"
            . '     * ' . self::TAG . ' -- a real directive',
        );

        self::assertStringContainsString(self::TAG, $masked);
    }

    /**
     * The defect this class exists to remove: an odd backtick above a quoted
     * example used to pair with the example's opening backtick, which left the
     * tag itself outside the region and turned documentation into a live
     * suppression.
     */
    #[Test]
    public function itQuotesAnExampleStandingUnderAnUnpairedBacktick(): void
    {
        $masked = DocumentationRegions::mask(
            "     * A literal ` is allowed in prose.\n"
            . '     * Use `' . self::TAG . '` to silence that rule.',
        );

        self::assertStringNotContainsString('@qmx-ignore', $masked);
    }

    #[Test]
    public function itBlanksAFencedBlockWholeAndItsDelimiters(): void
    {
        $masked = DocumentationRegions::mask(
            "     * Example:\n"
            . "     * ```php\n"
            . '     * ' . self::TAG . " -- inside a fence\n"
            . '     * ```',
        );

        self::assertStringNotContainsString('@qmx-ignore', $masked);
        self::assertStringNotContainsString('```', $masked);
    }

    #[Test]
    public function itReadsATagWrittenAfterAFenceHasClosed(): void
    {
        $masked = DocumentationRegions::mask(
            "     * ```php\n"
            . "     * echo 1;\n"
            . "     * ```\n"
            . '     * ' . self::TAG . ' -- after the fence',
        );

        self::assertStringContainsString(self::TAG, $masked);
    }

    #[Test]
    public function itBlanksATagInsideDoubleBacktickQuoting(): void
    {
        $masked = DocumentationRegions::mask('     * Write `` `' . self::TAG . '` `` when quoting the tag.');

        self::assertStringNotContainsString('@qmx-ignore', $masked);
    }

    /**
     * A backtick inside quoted prose is one of the two delimiters or ordinary
     * text; either way the sentence around it must not change what the lines
     * below it mean.
     */
    #[Test]
    public function itKeepsABacktickInProseFromReachingTheNextLine(): void
    {
        $masked = DocumentationRegions::mask(
            "     * Shell quoting uses the ` character, as in `ls`.\n"
            . '     * ' . self::TAG . ' -- still a real directive',
        );

        self::assertStringContainsString(self::TAG, $masked);
    }

    /**
     * Pairing left to right within a line still let a stray backtick earlier
     * on the same line take the quote's opening backtick as its partner, and
     * the quoted tag came out live — a silent suppression when the channel it
     * names exists. A backtick written directly before a tag is the author
     * quoting it, so it opens a region whatever stands before it.
     */
    #[Test]
    public function itQuotesATagWhoseOpeningBacktickFollowsAStrayOneOnTheSameLine(): void
    {
        $masked = DocumentationRegions::mask("     * Don't put ` in names; quote the tag as `" . self::TAG . '` instead.');

        self::assertStringNotContainsString('@qmx-ignore', $masked);
    }

    /**
     * The legitimate neighbours of the rule above: a tag no backtick stands
     * directly before is written, not quoted, whatever quoting surrounds it
     * on its line — and an opening backtick nothing closes quotes nothing.
     */
    #[Test]
    public function itReadsATagThatNoBacktickStandsDirectlyBefore(): void
    {
        foreach ([
            '     * ' . self::TAG . ' -- keep `code` in the reason',
            '     * See `this` first; ' . self::TAG . ' -- written after quoted prose',
            '     * A stray ` then ' . self::TAG . ' -- written after an unpaired backtick',
            '     * `' . self::TAG . ' -- an opening backtick nothing closes',
        ] as $line) {
            self::assertStringContainsString(self::TAG, DocumentationRegions::mask($line), $line);
        }
    }

    /**
     * Blanking rather than deleting is what lets a caller report the line a tag
     * was written on: every offset in the result still addresses the character
     * the author wrote.
     */
    #[Test]
    public function itPreservesEveryOffsetAndLineBreak(): void
    {
        $text = "/**\n * `quoted`\n * " . self::TAG . "\n */";
        $masked = DocumentationRegions::mask($text);

        self::assertSame(\strlen($text), \strlen($masked));
        self::assertSame(substr_count($text, "\n"), substr_count($masked, "\n"));
        self::assertSame(strpos($text, '@qmx-ignore'), strpos($masked, '@qmx-ignore'));
    }
}
