<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Inline\Contract\DocumentationRegions;

/** Physical placement controls whether a tag is live; quoting controls its diagnosis. */
#[CoversClass(DocumentationRegions::class)]
final class DocumentationRegionsTest extends TestCase
{
    private const string TAG = '@qmx-ignore complexity.ccn';

    #[Test]
    public function itRefusesMidlineProseEvenInsideACodeSpan(): void
    {
        $text = '* A ` stray, then ' . self::TAG . '` here';
        self::assertStringNotContainsString('@qmx-ignore', DocumentationRegions::mask($text));
        $mentions = DocumentationRegions::mentions($text);
        self::assertCount(1, $mentions);
        self::assertSame('not-at-line-start', $mentions[0]['reason']->value);
        self::assertSame(strpos($text, '@qmx-ignore'), $mentions[0]['offset']);
    }

    #[Test]
    public function itDistinguishesCommentStartsFromQuotedAndUnquotedMentions(): void
    {
        foreach (['/*** ', '/// ', ' ** ', '# ', '    '] as $prefix) {
            self::assertStringContainsString(self::TAG, DocumentationRegions::mask($prefix . self::TAG));
            self::assertSame([], DocumentationRegions::mentions($prefix . self::TAG));
        }
        foreach (['* - ', '* @internal ', '/** Summary. ', '* `', '* a stray ` then `'] as $prefix) {
            $text = $prefix . self::TAG;
            self::assertStringNotContainsString('@qmx-ignore', DocumentationRegions::mask($text));
            self::assertCount(1, DocumentationRegions::mentions($text), $text);
        }
        foreach (['* ``' . self::TAG . '``', '* `// ' . self::TAG . '`', '* `` `' . self::TAG . '` ``', '* \\`' . self::TAG . '`'] as $text) {
            self::assertSame([], DocumentationRegions::mentions($text), $text);
        }
        self::assertCount(1, DocumentationRegions::mentions('* `' . self::TAG . '``'));
        self::assertCount(1, DocumentationRegions::mentions('* a stray ` here, and `' . self::TAG . '`'));
        self::assertStringContainsString(self::TAG, DocumentationRegions::mask("* `above\n* " . self::TAG . "\n* below`"));
    }

    #[Test]
    public function itRequiresAFenceToCloseWithTheSameCharacterAndEnoughDelimiters(): void
    {
        foreach (['```php', '~~~php', '````php'] as $opening) {
            $text = "/**\n * " . $opening . "\n * " . self::TAG . "\n * " . $opening[0] . $opening[0] . $opening[0] . $opening[0] . " */";
            self::assertSame([], DocumentationRegions::mentions($text), $text);
            self::assertStringNotContainsString('@qmx-ignore', DocumentationRegions::mask($text));
        }
        foreach (['```', '~~~~', '````php'] as $opening) {
            $text = "/**\n * " . $opening . "\n * " . self::TAG . "\n * ~~\n * " . self::TAG . "\n */";
            $mentions = DocumentationRegions::mentions($text);
            self::assertCount(2, $mentions, $text);
            self::assertSame('inside-unclosed-fence', $mentions[0]['reason']->value);
            self::assertSame(2, $mentions[0]['fenceLine']);
            self::assertStringNotContainsString('@qmx-ignore', DocumentationRegions::mask($text));
        }
        self::assertSame([], DocumentationRegions::mentions("* ~~~~~~~\n* no tags"));
        self::assertStringContainsString(self::TAG, DocumentationRegions::mask("* ```bad`info\n* " . self::TAG));
        self::assertCount(1, DocumentationRegions::mentions("* ```\n* " . self::TAG . "\n* ``` trailing"));
        self::assertCount(1, DocumentationRegions::mentions("* ```\n* " . self::TAG . "\n* ~~~"));
        self::assertSame([], DocumentationRegions::mentions('// ```'));
        self::assertStringContainsString(self::TAG, DocumentationRegions::mask('// ' . self::TAG));
    }

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

    #[Test]
    public function itMasksATagWhoseOpeningBacktickFollowsAStrayOneOnTheSameLine(): void
    {
        $masked = DocumentationRegions::mask("     * Don't put ` in names; quote the tag as `" . self::TAG . '` instead.');

        self::assertStringNotContainsString('@qmx-ignore', $masked);
    }

    #[Test]
    public function itReadsATagThatNoBacktickStandsDirectlyBefore(): void
    {
        foreach ([
            '     * ' . self::TAG . ' -- keep `code` in the reason',
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
