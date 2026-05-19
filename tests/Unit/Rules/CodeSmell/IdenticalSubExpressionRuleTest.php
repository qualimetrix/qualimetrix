<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\CodeSmell;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
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
    #[Test]
    public function itGetName(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());
        self::assertSame('code-smell.identical-subexpression', $rule->getName());
    }

    #[Test]
    public function itGetDescription(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());
        self::assertNotEmpty($rule->getDescription());
    }

    #[Test]
    public function itGetCategory(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());
        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    #[Test]
    public function itRequires(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());
        $requires = $rule->requires();

        self::assertContains('identicalSubExpression.identical_operands', $requires);
        self::assertContains('identicalSubExpression.duplicate_condition', $requires);
        self::assertContains('identicalSubExpression.identical_ternary', $requires);
        self::assertContains('identicalSubExpression.duplicate_match_arm', $requires);
        self::assertContains('identicalSubExpression.duplicate_switch_case', $requires);
    }

    #[Test]
    public function itGetOptionsClass(): void
    {
        self::assertSame(IdenticalSubExpressionOptions::class, IdenticalSubExpressionRule::getOptionsClass());
    }

    #[Test]
    public function itAnalyzeDisabledReturnsEmpty(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);
        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itNoFindings(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = new MetricBag();
        $context = $this->createContext($metricBag);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itWithIdenticalOperands(): void
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

    #[Test]
    public function itWithDuplicateCondition(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = (new MetricBag())
            ->withEntry('identicalSubExpression.duplicate_condition', ['line' => 5]);

        $context = $this->createContext($metricBag);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('if/elseif', $violations[0]->message);
    }

    #[Test]
    public function itWithIdenticalTernary(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = (new MetricBag())
            ->withEntry('identicalSubExpression.identical_ternary', ['line' => 3]);

        $context = $this->createContext($metricBag);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('ternary', $violations[0]->message);
    }

    #[Test]
    public function itWithDuplicateMatchArm(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = (new MetricBag())
            ->withEntry('identicalSubExpression.duplicate_match_arm', ['line' => 7]);

        $context = $this->createContext($metricBag);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('match', $violations[0]->message);
    }

    #[Test]
    public function itMultipleFindings(): void
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

    #[Test]
    public function itOptionsDefaultEnabled(): void
    {
        $options = new IdenticalSubExpressionOptions();
        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itOptionsFromArrayEnabled(): void
    {
        $options = IdenticalSubExpressionOptions::fromArray(['enabled' => true]);
        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itOptionsFromArrayDisabled(): void
    {
        $options = IdenticalSubExpressionOptions::fromArray(['enabled' => false]);
        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function itOptionsFromEmptyArray(): void
    {
        $options = IdenticalSubExpressionOptions::fromArray([]);
        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itOptionsSeverityPositiveValue(): void
    {
        $options = new IdenticalSubExpressionOptions();
        self::assertSame(Severity::Warning, $options->getSeverity(1));
    }

    #[Test]
    public function itOptionsSeverityZeroValue(): void
    {
        $options = new IdenticalSubExpressionOptions();
        self::assertNull($options->getSeverity(0));
    }

    // -- Helpers ----------------------------------------------------------

    private function createContext(MetricBag $metricBag): AnalysisContext
    {
        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/file.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/file.php'), 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        return new AnalysisContext($repository);
    }
}
