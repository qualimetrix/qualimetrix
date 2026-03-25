<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\CodeSmell;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\CodeSmell\IdenticalSubExpressionOptions;
use Qualimetrix\Rules\CodeSmell\IdenticalSubExpressionRule;

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

        self::assertContains('identicalSubExpression.identical_operands', $requires);
        self::assertContains('identicalSubExpression.duplicate_condition', $requires);
        self::assertContains('identicalSubExpression.identical_ternary', $requires);
        self::assertContains('identicalSubExpression.duplicate_match_arm', $requires);
        self::assertContains('identicalSubExpression.duplicate_switch_case', $requires);
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

        $metricBag = new MetricBag();
        $context = $this->createContext($metricBag);

        self::assertSame([], $rule->analyze($context));
    }

    public function testWithIdenticalOperands(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = (new MetricBag())
            ->withEntry('identicalSubExpression.identical_operands', ['line' => 10]);

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

        $metricBag = (new MetricBag())
            ->withEntry('identicalSubExpression.duplicate_condition', ['line' => 5]);

        $context = $this->createContext($metricBag);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('if/elseif', $violations[0]->message);
    }

    public function testWithIdenticalTernary(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = (new MetricBag())
            ->withEntry('identicalSubExpression.identical_ternary', ['line' => 3]);

        $context = $this->createContext($metricBag);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('ternary', $violations[0]->message);
    }

    public function testWithDuplicateMatchArm(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = (new MetricBag())
            ->withEntry('identicalSubExpression.duplicate_match_arm', ['line' => 7]);

        $context = $this->createContext($metricBag);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('match', $violations[0]->message);
    }

    public function testMultipleFindings(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = (new MetricBag())
            ->withEntry('identicalSubExpression.identical_operands', ['line' => 5])
            ->withEntry('identicalSubExpression.identical_operands', ['line' => 8])
            ->withEntry('identicalSubExpression.duplicate_condition', ['line' => 12]);

        $context = $this->createContext($metricBag);
        $violations = $rule->analyze($context);

        self::assertCount(3, $violations);
    }

    // -- Options Tests ---------------------------------------------------

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

    // -- Helpers ----------------------------------------------------------

    private function createContext(MetricBag $metricBag): AnalysisContext
    {
        $symbolPath = SymbolPath::forFile('src/file.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/file.php', 1);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        return new AnalysisContext($repository);
    }
}
