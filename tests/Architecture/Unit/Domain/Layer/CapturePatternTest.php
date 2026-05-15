<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Domain\Layer;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Domain\Layer\CapturePattern;

#[CoversClass(CapturePattern::class)]
final class CapturePatternTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Variable extraction
    // -------------------------------------------------------------------------

    #[Test]
    public function extractVariables_returnsEmptyListForNonCapturePattern(): void
    {
        self::assertSame([], CapturePattern::extractVariables('App\\Service\\**'));
    }

    #[Test]
    public function extractVariables_collectsAllNames(): void
    {
        self::assertSame(
            ['tenant', 'module'],
            CapturePattern::extractVariables('App\\{tenant}\\Module\\{module}\\Domain\\**'),
        );
    }

    #[Test]
    public function extractVariables_returnsListAcceptingMultiSegmentCapture(): void
    {
        self::assertSame(
            ['module'],
            CapturePattern::extractVariables('App\\Module\\{module:**}\\Domain'),
        );
    }

    #[Test]
    public function isCaptureProducing_distinguishesPlainGlobsFromCaptures(): void
    {
        self::assertFalse(CapturePattern::isCaptureProducing('App\\Service\\**'));
        self::assertTrue(CapturePattern::isCaptureProducing('App\\Module\\{module}\\Service'));
    }

    // -------------------------------------------------------------------------
    // Compile + match
    // -------------------------------------------------------------------------

    #[Test]
    public function compile_singleSegmentCapture_matchesAndExtractsBinding(): void
    {
        $pattern = CapturePattern::compile('App\\Module\\{module}\\Domain');

        self::assertSame(['module' => 'Order'], $pattern->match('App\\Module\\Order\\Domain'));
        self::assertSame(['module' => 'Audit'], $pattern->match('App\\Module\\Audit\\Domain'));
    }

    #[Test]
    public function compile_singleSegmentCapture_doesNotMatchAcrossSeparator(): void
    {
        $pattern = CapturePattern::compile('App\\Module\\{module}\\Domain');

        self::assertNull($pattern->match('App\\Module\\Sub\\Order\\Domain'));
    }

    #[Test]
    public function compile_multiSegmentCapture_matchesAcrossSeparators(): void
    {
        $pattern = CapturePattern::compile('App\\Module\\{module:**}\\Domain');

        self::assertSame(['module' => 'Audit'], $pattern->match('App\\Module\\Audit\\Domain'));
        self::assertSame(
            ['module' => 'Reporting\\Sub'],
            $pattern->match('App\\Module\\Reporting\\Sub\\Domain'),
        );
    }

    #[Test]
    public function compile_doubleStarGlob_matchesAcrossSeparators(): void
    {
        $pattern = CapturePattern::compile('App\\Module\\{module}\\Domain\\**');

        self::assertSame(
            ['module' => 'Order'],
            $pattern->match('App\\Module\\Order\\Domain\\Entity\\Customer'),
        );
        self::assertSame(
            ['module' => 'Order'],
            $pattern->match('App\\Module\\Order\\Domain\\Customer'),
        );
    }

    #[Test]
    public function compile_singleStarGlob_doesNotMatchAcrossSeparator(): void
    {
        $pattern = CapturePattern::compile('App\\Service\\*');

        self::assertSame([], $pattern->match('App\\Service\\Customer'));
        self::assertNull($pattern->match('App\\Service\\Subspace\\Customer'));
    }

    #[Test]
    public function compile_questionMark_matchesSingleNonSeparatorChar(): void
    {
        $pattern = CapturePattern::compile('App\\?');

        self::assertSame([], $pattern->match('App\\X'));
        self::assertNull($pattern->match('App\\XY'));
        self::assertNull($pattern->match('App\\\\'));
    }

    #[Test]
    public function compile_nonCapturePattern_returnsEmptyArrayOnMatch(): void
    {
        $pattern = CapturePattern::compile('App\\Service\\**');

        self::assertSame([], $pattern->match('App\\Service\\User'));
        self::assertNull($pattern->match('App\\Other\\User'));
    }

    #[Test]
    public function compile_multiVariableCapture_extractsAllBindings(): void
    {
        $pattern = CapturePattern::compile('App\\{tenant}\\Module\\{module}\\Domain\\**');

        self::assertSame(
            ['tenant' => 'AcmeCorp', 'module' => 'Order'],
            $pattern->match('App\\AcmeCorp\\Module\\Order\\Domain\\Entity\\Customer'),
        );
    }

    #[Test]
    public function compile_backslashIsAlwaysLiteralSeparator(): void
    {
        // `App\Module\{m}` — `\` between `App` and `Module` is literal, and
        // `\` between `Module` and `{m}` is also literal (no escape semantics
        // for braces — FQNs cannot contain literal braces anyway).
        $pattern = CapturePattern::compile('App\\Module\\{m}');

        self::assertSame(['m' => 'Foo'], $pattern->match('App\\Module\\Foo'));
    }

    // -------------------------------------------------------------------------
    // Grammar errors
    // -------------------------------------------------------------------------

    #[Test]
    public function compile_emptyPattern_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source pattern must not be empty');

        CapturePattern::compile('');
    }

    #[DataProvider('invalidGrammarProvider')]
    #[Test]
    public function compile_invalidGrammar_rejected(string $pattern, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        CapturePattern::compile($pattern);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function invalidGrammarProvider(): iterable
    {
        yield 'unbalanced open' => ['App\\{var', "unbalanced '{'"];
        yield 'unbalanced close' => ['App}var', "unbalanced '}'"];
        yield 'empty capture' => ['App\\{}', "empty capture '{}'"];
        yield 'invalid name digit start' => ['App\\{1var}', "invalid capture name '1var'"];
        yield 'invalid name with hyphen' => ['App\\{a-b}', "invalid capture name 'a-b'"];
        yield 'unknown quantifier' => ['App\\{var:*}', "unknown capture quantifier ':*'"];
        yield 'nested capture' => ['App\\{outer{inner}}', "nested '{'"];
        yield 'duplicate capture name' => ['App\\{m}\\Module\\{m}', "duplicate capture name 'm'"];
        yield 'adjacent captures no separator' => ['App\\{a}{b}', "adjacent captures '{a}{...}'"];
    }

    // -------------------------------------------------------------------------
    // Substitution
    // -------------------------------------------------------------------------

    #[Test]
    public function substitute_replacesVariablesPreservingGlobs(): void
    {
        $pattern = CapturePattern::compile('App\\{tenant}\\Module\\{module}\\Domain\\**');

        self::assertSame(
            'App\\AcmeCorp\\Module\\Order\\Domain\\**',
            $pattern->substitute(['tenant' => 'AcmeCorp', 'module' => 'Order']),
        );
    }

    #[Test]
    public function substitute_passesUnknownVariablesThrough(): void
    {
        $pattern = CapturePattern::compile('App\\{tenant}\\{module}');

        self::assertSame(
            'App\\AcmeCorp\\{module}',
            $pattern->substitute(['tenant' => 'AcmeCorp']),
        );
    }

    #[Test]
    public function applySubstitution_supportsMultiSegmentCaptureSyntax(): void
    {
        self::assertSame(
            'App\\Module\\Foo\\Bar\\Domain',
            CapturePattern::applySubstitution('App\\Module\\{m:**}\\Domain', ['m' => 'Foo\\Bar']),
        );
    }

    #[Test]
    public function applySubstitution_preservesGlobs(): void
    {
        self::assertSame(
            'App\\Module\\Foo\\Sub\\**',
            CapturePattern::applySubstitution('App\\Module\\{m}\\Sub\\**', ['m' => 'Foo']),
        );
    }
}
