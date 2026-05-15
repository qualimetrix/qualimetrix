<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Domain\Allow;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Domain\Allow\CaptureBinding;
use Qualimetrix\Architecture\Domain\Allow\InvalidSelectorException;
use Qualimetrix\Architecture\Domain\Allow\LayerSelector;
use Qualimetrix\Architecture\Domain\Allow\LayerSelectorParser;
use Qualimetrix\Architecture\Domain\Allow\SelectorKind;

/**
 * Direct coverage of the D4 selector grammar — kind detection from content,
 * source/target match semantics, and parser error surface. Configuration-layer
 * tests in {@see \Qualimetrix\Tests\Architecture\Unit\Configuration\Validation\AllowValidatorTest}
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
    public function capturedTargetSubstitutesSourceBinding(): void
    {
        // Step E binding-aware semantics: a captured target selector
        // substitutes the bound variable values from the source side before
        // matching. {@code 'domain-{m}'} with binding {@code m → Order} matches
        // only {@code 'domain-Order'} literally — sibling instances of the
        // same shape are rejected.
        $target = LayerSelectorParser::parse('domain-{m}');

        $sourceBinding = new CaptureBinding(['m' => 'Order']);

        self::assertTrue($target->matchesTarget('domain-Order', $sourceBinding));
        self::assertFalse($target->matchesTarget('domain-Inventory', $sourceBinding));
        self::assertFalse($target->matchesTarget('controller', $sourceBinding));
    }

    #[Test]
    public function capturedTargetWithEmptyBindingDegradesToShapeMatch(): void
    {
        // When the source binding is empty (exact/glob source kinds, or the
        // policy's cross-instance escape hatch), the captured target falls
        // back to per-segment default patterns: it matches any same-shape
        // name. This is the runtime path activated by
        // {@code allow_cross_instance: true} on the allow entry.
        $target = LayerSelectorParser::parse('domain-{m}');

        $empty = CaptureBinding::empty();

        self::assertTrue($target->matchesTarget('domain-Order', $empty));
        self::assertTrue($target->matchesTarget('domain-Inventory', $empty));
        self::assertFalse($target->matchesTarget('controller', $empty));
    }

    #[Test]
    public function capturedTargetWithMultipleBindingsSubstitutesEach(): void
    {
        $target = LayerSelectorParser::parse('{a}-{b}');

        $binding = new CaptureBinding(['a' => 'app', 'b' => 'Order']);

        self::assertTrue($target->matchesTarget('app-Order', $binding));
        self::assertFalse($target->matchesTarget('app-Inventory', $binding));
        self::assertFalse($target->matchesTarget('domain-Order', $binding));
    }

    #[Test]
    public function capturedTargetWithMultiSegmentBindingSubstitutesAcrossSeparators(): void
    {
        $target = LayerSelectorParser::parse('app-{path:**}');

        $binding = new CaptureBinding(['path' => 'Order\\Sub\\Leaf']);

        self::assertTrue($target->matchesTarget('app-Order\\Sub\\Leaf', $binding));
        self::assertFalse($target->matchesTarget('app-Order\\Sub', $binding));
    }

    #[Test]
    public function captureVariablesListsDeclaredNames(): void
    {
        $exact = LayerSelector::exact('controller');
        self::assertSame([], $exact->captureVariables());

        $glob = LayerSelector::glob('domain-*');
        self::assertSame([], $glob->captureVariables());

        $singleCapture = LayerSelectorParser::parse('app-{m}');
        self::assertSame(['m'], $singleCapture->captureVariables());

        $multiCapture = LayerSelectorParser::parse('{a}-{b}');
        self::assertSame(['a', 'b'], $multiCapture->captureVariables());

        $multiSegmentCapture = LayerSelectorParser::parse('app-{path:**}');
        self::assertSame(['path'], $multiSegmentCapture->captureVariables());
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
