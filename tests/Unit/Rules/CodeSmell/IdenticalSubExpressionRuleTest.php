<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\CodeSmell;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Rules\CodeSmell\IdenticalSubExpressionOptions;
use AiMessDetector\Rules\CodeSmell\IdenticalSubExpressionRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdenticalSubExpressionRule::class)]
#[CoversClass(IdenticalSubExpressionOptions::class)]
final class IdenticalSubExpressionRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());
        self::assertSame('code-smell.identical-subexpression', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());
        self::assertNotEmpty($rule->getDescription());
    }

    public function testGetCategory(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());
        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());
        $requires = $rule->requires();

        self::assertContains('identicalSubExpression.identical_operands.count', $requires);
        self::assertContains('identicalSubExpression.duplicate_condition.count', $requires);
        self::assertContains('identicalSubExpression.identical_ternary.count', $requires);
        self::assertContains('identicalSubExpression.duplicate_match_arm.count', $requires);
        self::assertContains('identicalSubExpression.duplicate_switch_case.count', $requires);
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(IdenticalSubExpressionOptions::class, IdenticalSubExpressionRule::getOptionsClass());
    }

    public function testAnalyzeDisabledReturnsEmpty(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);
        self::assertSame([], $rule->analyze($context));
    }

    public function testNoFindings(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = $this->createEmptyMetricBag();
        $context = $this->createContext($metricBag);

        self::assertSame([], $rule->analyze($context));
    }

    public function testWithIdenticalOperands(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = $this->createEmptyMetricBag()
            ->with('identicalSubExpression.identical_operands.count', 1)
            ->with('identicalSubExpression.identical_operands.line.0', 10);

        $context = $this->createContext($metricBag);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(10, $violations[0]->location->line);
        self::assertStringContainsString('operator', $violations[0]->message);
        self::assertSame('code-smell.identical-subexpression', $violations[0]->violationCode);
        self::assertSame(1.0, $violations[0]->metricValue);
    }

    public function testWithDuplicateCondition(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = $this->createEmptyMetricBag()
            ->with('identicalSubExpression.duplicate_condition.count', 1)
            ->with('identicalSubExpression.duplicate_condition.line.0', 5);

        $context = $this->createContext($metricBag);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('if/elseif', $violations[0]->message);
    }

    public function testWithIdenticalTernary(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = $this->createEmptyMetricBag()
            ->with('identicalSubExpression.identical_ternary.count', 1)
            ->with('identicalSubExpression.identical_ternary.line.0', 3);

        $context = $this->createContext($metricBag);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('ternary', $violations[0]->message);
    }

    public function testWithDuplicateMatchArm(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = $this->createEmptyMetricBag()
            ->with('identicalSubExpression.duplicate_match_arm.count', 1)
            ->with('identicalSubExpression.duplicate_match_arm.line.0', 7);

        $context = $this->createContext($metricBag);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('match', $violations[0]->message);
    }

    public function testMultipleFindings(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = $this->createEmptyMetricBag()
            ->with('identicalSubExpression.identical_operands.count', 2)
            ->with('identicalSubExpression.identical_operands.line.0', 5)
            ->with('identicalSubExpression.identical_operands.line.1', 8)
            ->with('identicalSubExpression.duplicate_condition.count', 1)
            ->with('identicalSubExpression.duplicate_condition.line.0', 12);

        $context = $this->createContext($metricBag);
        $violations = $rule->analyze($context);

        self::assertCount(3, $violations);
    }

    // ── Options Tests ───────────────────────────────────────────────

    public function testOptionsDefaultEnabled(): void
    {
        $options = new IdenticalSubExpressionOptions();
        self::assertTrue($options->isEnabled());
    }

    public function testOptionsFromArrayEnabled(): void
    {
        $options = IdenticalSubExpressionOptions::fromArray(['enabled' => true]);
        self::assertTrue($options->isEnabled());
    }

    public function testOptionsFromArrayDisabled(): void
    {
        $options = IdenticalSubExpressionOptions::fromArray(['enabled' => false]);
        self::assertFalse($options->isEnabled());
    }

    public function testOptionsFromEmptyArray(): void
    {
        $options = IdenticalSubExpressionOptions::fromArray([]);
        self::assertTrue($options->isEnabled());
    }

    public function testOptionsSeverityPositiveValue(): void
    {
        $options = new IdenticalSubExpressionOptions();
        self::assertSame(Severity::Warning, $options->getSeverity(1));
    }

    public function testOptionsSeverityZeroValue(): void
    {
        $options = new IdenticalSubExpressionOptions();
        self::assertNull($options->getSeverity(0));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function createEmptyMetricBag(): MetricBag
    {
        return (new MetricBag())
            ->with('identicalSubExpression.identical_operands.count', 0)
            ->with('identicalSubExpression.duplicate_condition.count', 0)
            ->with('identicalSubExpression.identical_ternary.count', 0)
            ->with('identicalSubExpression.duplicate_match_arm.count', 0)
            ->with('identicalSubExpression.duplicate_switch_case.count', 0);
    }

    private function createContext(MetricBag $metricBag): AnalysisContext
    {
        $symbolPath = SymbolPath::forFile('src/file.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/file.php', 1);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        return new AnalysisContext($repository);
    }
}
