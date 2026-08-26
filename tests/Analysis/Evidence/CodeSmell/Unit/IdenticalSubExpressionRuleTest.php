<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\IdenticalSubExpressionOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\IdenticalSubExpressionRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

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
            ->withEntry('identicalSubExpression.identical_operands', ['subjectKind' => 'file', 'line' => 10, 'detail' => '']);

        $context = $this->createContext($metricBag);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(10, $findings[0]->location->line);
        self::assertStringContainsString('operator', $findings[0]->message);
        self::assertSame('code-smell.identical-subexpression', $findings[0]->code);
        self::assertSame(1.0, $findings[0]->metricValue);
    }

    #[Test]
    public function itWithDuplicateCondition(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = (new MetricBag())
            ->withEntry('identicalSubExpression.duplicate_condition', ['subjectKind' => 'file', 'line' => 5, 'detail' => '']);

        $context = $this->createContext($metricBag);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertStringContainsString('if/elseif', $findings[0]->message);
    }

    #[Test]
    public function itWithIdenticalTernary(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = (new MetricBag())
            ->withEntry('identicalSubExpression.identical_ternary', ['subjectKind' => 'file', 'line' => 3, 'detail' => '']);

        $context = $this->createContext($metricBag);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertStringContainsString('ternary', $findings[0]->message);
    }

    #[Test]
    public function itWithDuplicateMatchArm(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = (new MetricBag())
            ->withEntry('identicalSubExpression.duplicate_match_arm', ['subjectKind' => 'file', 'line' => 7, 'detail' => '']);

        $context = $this->createContext($metricBag);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertStringContainsString('match', $findings[0]->message);
    }

    #[Test]
    public function itMultipleFindings(): void
    {
        $rule = new IdenticalSubExpressionRule(new IdenticalSubExpressionOptions());

        $metricBag = (new MetricBag())
            ->withEntry('identicalSubExpression.identical_operands', ['subjectKind' => 'file', 'line' => 5, 'detail' => ''])
            ->withEntry('identicalSubExpression.identical_operands', ['subjectKind' => 'file', 'line' => 8, 'detail' => ''])
            ->withEntry('identicalSubExpression.duplicate_condition', ['subjectKind' => 'file', 'line' => 12, 'detail' => '']);

        $context = $this->createContext($metricBag);
        $findings = $rule->analyze($context);

        self::assertCount(3, $findings);
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
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        return new AnalysisContext($repository);
    }
}
