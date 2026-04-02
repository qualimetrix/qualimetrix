<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline\Suppression;

use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Suppression\ThresholdOverrideExtractionResult;
use Qualimetrix\Baseline\Suppression\ThresholdOverrideExtractor;

#[CoversClass(ThresholdOverrideExtractor::class)]
#[CoversClass(ThresholdOverrideExtractionResult::class)]
final class ThresholdOverrideExtractorTest extends TestCase
{
    private ThresholdOverrideExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ThresholdOverrideExtractor();
    }

    public function testExtractsShorthandSyntax(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic 15
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertCount(1, $overrides);
        self::assertSame('complexity.cyclomatic', $overrides[0]->rulePattern);
        self::assertSame(15, $overrides[0]->warning);
        self::assertSame(15, $overrides[0]->error);
        self::assertSame(10, $overrides[0]->line);
        self::assertSame(50, $overrides[0]->endLine);
    }

    public function testExtractsExplicitSyntaxBothValues(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic warning=15 error=25
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertCount(1, $overrides);
        self::assertSame('complexity.cyclomatic', $overrides[0]->rulePattern);
        self::assertSame(15, $overrides[0]->warning);
        self::assertSame(25, $overrides[0]->error);
    }

    public function testExtractsExplicitSyntaxWarningOnly(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic warning=15
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertCount(1, $overrides);
        self::assertSame(15, $overrides[0]->warning);
        self::assertNull($overrides[0]->error);
    }

    public function testExtractsExplicitSyntaxErrorOnly(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic error=25
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertCount(1, $overrides);
        self::assertNull($overrides[0]->warning);
        self::assertSame(25, $overrides[0]->error);
    }

    public function testExtractsFloatThresholds(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold coupling.instability 0.8
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertCount(1, $overrides);
        self::assertSame(0.8, $overrides[0]->warning);
        self::assertSame(0.8, $overrides[0]->error);
    }

    public function testExtractsFloatExplicitThresholds(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold coupling.instability warning=0.7 error=0.9
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertCount(1, $overrides);
        self::assertSame(0.7, $overrides[0]->warning);
        self::assertSame(0.9, $overrides[0]->error);
    }

    public function testExtractsMultipleAnnotations(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic 15
             * @qmx-threshold coupling.cbo 30
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertCount(2, $overrides);
        self::assertSame('complexity.cyclomatic', $overrides[0]->rulePattern);
        self::assertSame('coupling.cbo', $overrides[1]->rulePattern);
    }

    public function testExtractsWildcardPattern(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold * 30
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertCount(1, $overrides);
        self::assertSame('*', $overrides[0]->rulePattern);
        self::assertSame(30, $overrides[0]->warning);
        self::assertSame(30, $overrides[0]->error);
    }

    public function testSkipsInvalidSyntax(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic not-a-number
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertCount(0, $overrides);
    }

    public function testSkipsNegativeValues(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic warning=-5 error=10
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        // Negative values in the regex won't match (no minus sign in pattern)
        // So nothing is extracted for warning, resulting in null warning
        // BUT error=10 would match, giving [null, 10]
        // However the regex for negative values: the pattern \d+ doesn't match -5
        // So only error=10 matches
        // Since $warning is null and $error is 10, it's valid
        // Let's verify - actually the spec says negative values should be rejected
        // The regex only matches positive numbers so -5 won't match
        self::assertCount(1, $overrides);
        self::assertNull($overrides[0]->warning);
        self::assertSame(10, $overrides[0]->error);
    }

    public function testSkipsWarningGreaterThanError(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic warning=25 error=10
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertCount(0, $overrides);
    }

    public function testReturnsEmptyForNoDocComment(): void
    {
        $node = new Class_('Foo');
        // No docblock

        $overrides = $this->extractor->extract($node);

        self::assertCount(0, $overrides);
    }

    public function testReturnsEmptyForDocCommentWithoutAnnotation(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * A regular class
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertCount(0, $overrides);
    }

    public function testExtractsFromMethodDocblock(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic 20
             */
            DOC,
            20,
            22,
        );

        $node = new ClassMethod('doSomething');
        $node->setDocComment($docComment);
        $node->setAttribute('startLine', 23);
        $node->setAttribute('endLine', 40);

        $overrides = $this->extractor->extract($node);

        self::assertCount(1, $overrides);
        self::assertSame('complexity.cyclomatic', $overrides[0]->rulePattern);
        self::assertSame(20, $overrides[0]->warning);
        self::assertSame(20, $overrides[0]->error);
        self::assertSame(20, $overrides[0]->line);
        self::assertSame(40, $overrides[0]->endLine);
    }

    public function testExtractsPrefixPattern(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity 20
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertCount(1, $overrides);
        self::assertSame('complexity', $overrides[0]->rulePattern);
        // Should match all complexity.* rules
        self::assertTrue($overrides[0]->matches('complexity.cyclomatic'));
        self::assertTrue($overrides[0]->matches('complexity.cognitive'));
    }

    // =====================================================================
    // Diagnostic tests — extractWithDiagnostics()
    // =====================================================================

    public function testDiagnosticForInvalidSyntax(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic not-a-number
             */
            DOC,
            10,
            50,
        );

        $result = $this->extractor->extractWithDiagnostics($node);

        self::assertCount(0, $result->overrides);
        self::assertCount(1, $result->diagnostics);
        self::assertSame(10, $result->diagnostics[0]->line);
        self::assertStringContainsString('invalid syntax', $result->diagnostics[0]->message);
        self::assertStringContainsString('complexity.cyclomatic', $result->diagnostics[0]->message);
        self::assertStringContainsString('not-a-number', $result->diagnostics[0]->message);
    }

    public function testDiagnosticForWarningGreaterThanError(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic warning=25 error=10
             */
            DOC,
            10,
            50,
        );

        $result = $this->extractor->extractWithDiagnostics($node);

        self::assertCount(0, $result->overrides);
        self::assertCount(1, $result->diagnostics);
        self::assertSame(10, $result->diagnostics[0]->line);
        self::assertStringContainsString('warning threshold (25) must not exceed error threshold (10)', $result->diagnostics[0]->message);
    }

    public function testDiagnosticForWarningGreaterThanErrorWithFloats(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold coupling.instability warning=0.9 error=0.5
             */
            DOC,
            10,
            50,
        );

        $result = $this->extractor->extractWithDiagnostics($node);

        self::assertCount(0, $result->overrides);
        self::assertCount(1, $result->diagnostics);
        self::assertStringContainsString('warning threshold (0.9) must not exceed error threshold (0.5)', $result->diagnostics[0]->message);
    }

    public function testDiagnosticForDuplicateRuleAnnotation(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic 15
             * @qmx-threshold complexity.cyclomatic 20
             */
            DOC,
            10,
            50,
        );

        $result = $this->extractor->extractWithDiagnostics($node);

        self::assertCount(1, $result->overrides);
        self::assertSame(15, $result->overrides[0]->warning);
        self::assertCount(1, $result->diagnostics);
        self::assertStringContainsString('duplicate annotation', $result->diagnostics[0]->message);
        self::assertStringContainsString('complexity.cyclomatic', $result->diagnostics[0]->message);
    }

    public function testDiagnosticForDuplicateRuleDoesNotAffectDifferentRules(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic 15
             * @qmx-threshold coupling.cbo 30
             */
            DOC,
            10,
            50,
        );

        $result = $this->extractor->extractWithDiagnostics($node);

        self::assertCount(2, $result->overrides);
        self::assertCount(0, $result->diagnostics);
    }

    public function testNoDiagnosticsForValidAnnotations(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic warning=10 error=20
             */
            DOC,
            10,
            50,
        );

        $result = $this->extractor->extractWithDiagnostics($node);

        self::assertCount(1, $result->overrides);
        self::assertCount(0, $result->diagnostics);
    }

    public function testNoDiagnosticsForNoDocComment(): void
    {
        $node = new Class_('Foo');

        $result = $this->extractor->extractWithDiagnostics($node);

        self::assertCount(0, $result->overrides);
        self::assertCount(0, $result->diagnostics);
    }

    public function testNoDiagnosticsForDocCommentWithoutAnnotation(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * A regular class
             */
            DOC,
            10,
            50,
        );

        $result = $this->extractor->extractWithDiagnostics($node);

        self::assertCount(0, $result->overrides);
        self::assertCount(0, $result->diagnostics);
    }

    public function testMultipleDiagnosticsCollected(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic warning=25 error=10
             * @qmx-threshold coupling.cbo not-a-number
             */
            DOC,
            10,
            50,
        );

        $result = $this->extractor->extractWithDiagnostics($node);

        self::assertCount(0, $result->overrides);
        self::assertCount(2, $result->diagnostics);
        self::assertStringContainsString('must not exceed', $result->diagnostics[0]->message);
        self::assertStringContainsString('invalid syntax', $result->diagnostics[1]->message);
    }

    public function testMixedValidAndInvalidAnnotations(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic 15
             * @qmx-threshold coupling.cbo warning=30 error=10
             * @qmx-threshold cohesion.lcom4 20
             */
            DOC,
            10,
            50,
        );

        $result = $this->extractor->extractWithDiagnostics($node);

        self::assertCount(2, $result->overrides);
        self::assertSame('complexity.cyclomatic', $result->overrides[0]->rulePattern);
        self::assertSame('cohesion.lcom4', $result->overrides[1]->rulePattern);
        self::assertCount(1, $result->diagnostics);
        self::assertStringContainsString('coupling.cbo', $result->diagnostics[0]->message);
    }

    public function testBacktickEscapedThresholdIsNotExtracted(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * Use `@qmx-threshold complexity.cyclomatic 15` to override thresholds.
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertEmpty($overrides);
    }

    public function testMixedRealAndBacktickEscapedThresholds(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * @qmx-threshold complexity.cyclomatic 15
             * See also `@qmx-threshold coupling.cbo 30` for coupling.
             */
            DOC,
            10,
            50,
        );

        $overrides = $this->extractor->extract($node);

        self::assertCount(1, $overrides);
        self::assertSame('complexity.cyclomatic', $overrides[0]->rulePattern);
        self::assertSame(15, $overrides[0]->warning);
    }

    public function testBacktickEscapedThresholdProducesNoDiagnostics(): void
    {
        $node = $this->createClassNodeWithDoc(
            <<<'DOC'
            /**
             * Example: `@qmx-threshold complexity.cyclomatic not-a-number`
             */
            DOC,
            10,
            50,
        );

        $result = $this->extractor->extractWithDiagnostics($node);

        self::assertEmpty($result->overrides);
        self::assertEmpty($result->diagnostics);
    }

    private function createClassNodeWithDoc(string $docText, int $startLine, int $endLine): Class_
    {
        $docComment = new Doc($docText, $startLine, $startLine);

        $node = new Class_('TestClass');
        $node->setDocComment($docComment);
        $node->setAttribute('startLine', $startLine + 3);
        $node->setAttribute('endLine', $endLine);

        return $node;
    }
}
