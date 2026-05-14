<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Architecture\Allow;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Architecture\Allow\CaptureBinding;
use Qualimetrix\Core\Architecture\Allow\InvalidSelectorException;
use Qualimetrix\Core\Architecture\Allow\LayerSelector;
use Qualimetrix\Core\Architecture\Allow\LayerSelectorParser;
use Qualimetrix\Core\Architecture\Allow\SelectorKind;

/**
 * Direct coverage of the D4 selector grammar — kind detection from content,
 * source/target match semantics, and parser error surface. Configuration-layer
 * tests in {@see \Qualimetrix\Tests\Unit\Configuration\Architecture\Validation\AllowValidatorTest}
 * verify the rewrap-as-{@code ConfigLoadException} behaviour.
 */
#[CoversClass(LayerSelector::class)]
#[CoversClass(LayerSelectorParser::class)]
#[CoversClass(CaptureBinding::class)]
final class LayerSelectorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Kind detection
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string, 1: SelectorKind}>
     */
    public static function kindDetectionProvider(): iterable
    {
        yield 'bare alphanumeric' => ['controller', SelectorKind::Exact];
        yield 'kebab-case' => ['domain-shared', SelectorKind::Exact];
        yield 'snake_case' => ['app_module', SelectorKind::Exact];
        yield 'numeric suffix' => ['layer42', SelectorKind::Exact];

        yield 'star wildcard' => ['domain-*', SelectorKind::Glob];
        yield 'question wildcard' => ['domain-?', SelectorKind::Glob];
        yield 'character class' => ['domain-[ab]', SelectorKind::Glob];
        yield 'leading star' => ['*-repository', SelectorKind::Glob];

        yield 'single capture' => ['app-{m}', SelectorKind::Captured];
        yield 'two captures' => ['{a}-{b}', SelectorKind::Captured];
        yield 'capture with quantifier' => ['app-{path:**}', SelectorKind::Captured];
    }

    #[Test]
    #[DataProvider('kindDetectionProvider')]
    public function parserDetectsKindFromContent(string $raw, SelectorKind $expectedKind): void
    {
        $selector = LayerSelectorParser::parse($raw);

        self::assertSame($expectedKind, $selector->kind);
        self::assertSame($raw, $selector->originalString());
    }

    // -------------------------------------------------------------------------
    // Exact selector
    // -------------------------------------------------------------------------

    #[Test]
    public function exactSelectorMatchSourceReturnsEmptyBindingOnMatch(): void
    {
        $selector = LayerSelector::exact('controller');

        $binding = $selector->matchSource('controller');

        self::assertNotNull($binding);
        self::assertTrue($binding->isEmpty());
    }

    #[Test]
    public function exactSelectorMatchSourceReturnsNullOnMiss(): void
    {
        $selector = LayerSelector::exact('controller');

        self::assertNull($selector->matchSource('service'));
        self::assertNull($selector->matchSource('controllers'));
        self::assertNull($selector->matchSource(''));
    }

    #[Test]
    public function exactSelectorMatchesTargetIgnoresBinding(): void
    {
        $selector = LayerSelector::exact('service');

        $populated = new CaptureBinding(['m' => 'Order']);
        self::assertTrue($selector->matchesTarget('service', $populated));
        self::assertFalse($selector->matchesTarget('domain', $populated));
    }

    // -------------------------------------------------------------------------
    // Glob selector
    // -------------------------------------------------------------------------

    #[Test]
    public function globSelectorMatchesViaFnmatchOnBothSides(): void
    {
        $selector = LayerSelector::glob('domain-*');

        $sourceBinding = $selector->matchSource('domain-Order');
        self::assertNotNull($sourceBinding);
        self::assertTrue($sourceBinding->isEmpty());

        self::assertNull($selector->matchSource('controller'));

        self::assertTrue($selector->matchesTarget('domain-Inventory', CaptureBinding::empty()));
        self::assertFalse($selector->matchesTarget('shared', CaptureBinding::empty()));
    }

    #[Test]
    public function globSelectorSupportsCharacterClasses(): void
    {
        $selector = LayerSelector::glob('layer-[ab]');

        self::assertNotNull($selector->matchSource('layer-a'));
        self::assertNotNull($selector->matchSource('layer-b'));
        self::assertNull($selector->matchSource('layer-c'));
    }

    // -------------------------------------------------------------------------
    // Captured selector
    // -------------------------------------------------------------------------

    #[Test]
    public function capturedSelectorMatchSourceReturnsBindingWithCapturedValue(): void
    {
        $selector = LayerSelectorParser::parse('app-{m}');

        $binding = $selector->matchSource('app-Order');

        self::assertNotNull($binding);
        self::assertSame('Order', $binding->get('m'));
    }

    #[Test]
    public function capturedSelectorMatchSourceFailsOnShapeMismatch(): void
    {
        $selector = LayerSelectorParser::parse('app-{m}');

        self::assertNull($selector->matchSource('controller'));
        self::assertNull($selector->matchSource('app-'));
        // Multi-segment value would cross a namespace separator, default is
        // single-segment capture.
        self::assertNull($selector->matchSource('app-Order\\Sub'));
    }

    #[Test]
    public function multiSegmentCaptureMatchesAcrossSeparators(): void
    {
        $selector = LayerSelectorParser::parse('app-{m:**}');

        $binding = $selector->matchSource('app-Order\\Sub\\Leaf');

        self::assertNotNull($binding);
        self::assertSame('Order\\Sub\\Leaf', $binding->get('m'));
    }

    #[Test]
    public function multipleCapturesInOneSelector(): void
    {
        $selector = LayerSelectorParser::parse('{a}-{b}');

        $binding = $selector->matchSource('foo-bar');

        self::assertNotNull($binding);
        self::assertSame('foo', $binding->get('a'));
        self::assertSame('bar', $binding->get('b'));
    }

    #[Test]
    public function capturedTargetCurrentlyIgnoresSourceBindingInStepC(): void
    {
        // Pins Step C's deliberately loose target match: a captured target
        // selector matches any value of the right shape, regardless of what
        // the source side bound. Step E switches to {@code buildRegex($binding)}
        // and tightens this to require substitution match.
        $target = LayerSelectorParser::parse('domain-{m}');

        $sourceBinding = new CaptureBinding(['m' => 'Order']);

        // Same value as source: matches.
        self::assertTrue($target->matchesTarget('domain-Order', $sourceBinding));
        // Different value: also matches in Step C (Step E will reject).
        self::assertTrue($target->matchesTarget('domain-Inventory', $sourceBinding));
        // Wrong shape: doesn't match.
        self::assertFalse($target->matchesTarget('controller', $sourceBinding));
    }

    // -------------------------------------------------------------------------
    // Parser errors
    // -------------------------------------------------------------------------

    #[Test]
    public function emptyStringIsRejected(): void
    {
        $this->expectException(InvalidSelectorException::class);
        $this->expectExceptionMessage('non-empty');

        LayerSelectorParser::parse('');
    }

    #[Test]
    public function unbalancedOpenBraceIsRejected(): void
    {
        $this->expectException(InvalidSelectorException::class);
        $this->expectExceptionMessage("unbalanced '{'");

        LayerSelectorParser::parse('domain-{m');
    }

    #[Test]
    public function unbalancedCloseBraceIsRejected(): void
    {
        $this->expectException(InvalidSelectorException::class);
        $this->expectExceptionMessage("unbalanced '}'");

        LayerSelectorParser::parse('domain-m}');
    }

    #[Test]
    public function nestedBracesAreRejected(): void
    {
        $this->expectException(InvalidSelectorException::class);
        $this->expectExceptionMessage("nested '{'");

        LayerSelectorParser::parse('app-{a{b}}');
    }

    #[Test]
    public function emptyCaptureIsRejected(): void
    {
        $this->expectException(InvalidSelectorException::class);
        $this->expectExceptionMessage('empty capture');

        LayerSelectorParser::parse('app-{}');
    }

    #[Test]
    public function unknownCaptureQuantifierIsRejected(): void
    {
        $this->expectException(InvalidSelectorException::class);
        $this->expectExceptionMessage("only ':**' is supported");

        LayerSelectorParser::parse('app-{m:weird}');
    }

    #[Test]
    public function invalidCaptureNameStartingWithDigitIsRejected(): void
    {
        $this->expectException(InvalidSelectorException::class);
        $this->expectExceptionMessage('invalid capture name');

        LayerSelectorParser::parse('app-{0bad}');
    }

    #[Test]
    public function invalidCaptureNameContainingHyphenIsRejected(): void
    {
        $this->expectException(InvalidSelectorException::class);
        $this->expectExceptionMessage('invalid capture name');

        LayerSelectorParser::parse('app-{bad-name}');
    }

    #[Test]
    public function duplicateCaptureNameIsRejectedAtParseTime(): void
    {
        // PCRE rejects {@code (?P<m>…)(?P<m>…)} at compile time with "two named
        // subpatterns have the same name", which previously surfaced as a
        // runtime warning + silent allow-list miss. Step C catches this at
        // config-load time instead.
        $this->expectException(InvalidSelectorException::class);
        $this->expectExceptionMessage("duplicate capture name 'm'");

        LayerSelectorParser::parse('{m}-{m}');
    }

    #[Test]
    public function trailingBackslashIsRejected(): void
    {
        // Without this guard a dangling backslash would silently be appended
        // to the literal buffer, producing a regex that requires a `\` in the
        // layer name — which the layer-name regex forbids. Result: the entry
        // silently never matches. ADR 0007 D4 rejects this kind of silent
        // fall-through.
        $this->expectException(InvalidSelectorException::class);
        $this->expectExceptionMessage("dangling '\\'");

        LayerSelectorParser::parse('app-{m}\\');
    }

    #[Test]
    public function variableNamesAreCaseSensitive(): void
    {
        $selector = LayerSelectorParser::parse('app-{Module}');

        $binding = $selector->matchSource('app-Order');

        self::assertNotNull($binding);
        self::assertSame('Order', $binding->get('Module'));
        self::assertNull($binding->get('module'), 'Case-sensitive lookup must not coalesce names.');
    }

    // -------------------------------------------------------------------------
    // Predicate accessors
    // -------------------------------------------------------------------------

    #[Test]
    public function kindPredicatesReflectSelectorKind(): void
    {
        $exact = LayerSelector::exact('a');
        self::assertTrue($exact->isExact());
        self::assertFalse($exact->isGlob());
        self::assertFalse($exact->isCaptured());

        $glob = LayerSelector::glob('a-*');
        self::assertFalse($glob->isExact());
        self::assertTrue($glob->isGlob());
        self::assertFalse($glob->isCaptured());

        $captured = LayerSelectorParser::parse('a-{m}');
        self::assertFalse($captured->isExact());
        self::assertFalse($captured->isGlob());
        self::assertTrue($captured->isCaptured());
    }
}
