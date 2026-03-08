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
use AiMessDetector\Rules\CodeSmell\CodeSmellOptions;
use AiMessDetector\Rules\CodeSmell\GotoRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GotoRule::class)]
final class GotoRuleTest extends TestCase
{
    #[Test]
    public function nameAndDescriptionAreCorrect(): void
    {
        $rule = new GotoRule(new CodeSmellOptions());

        self::assertSame('code-smell.goto', $rule->getName());
        self::assertSame('Detects usage of goto statement', $rule->getDescription());
        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    #[Test]
    public function requiresReturnsExpectedMetrics(): void
    {
        $rule = new GotoRule(new CodeSmellOptions());

        self::assertSame(['codeSmell.goto.count'], $rule->requires());
    }

    #[Test]
    public function optionsClassIsCorrect(): void
    {
        self::assertSame(CodeSmellOptions::class, GotoRule::getOptionsClass());
    }

    #[Test]
    public function disabledRuleReturnsNoViolations(): void
    {
        $rule = new GotoRule(new CodeSmellOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function noSmellsProducesNoViolations(): void
    {
        $rule = new GotoRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile('src/Clean.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/Clean.php', null);

        $metricBag = (new MetricBag())->with('codeSmell.goto.count', 0);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function smellDetectedProducesViolation(): void
    {
        $rule = new GotoRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile('src/Smelly.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/Smelly.php', null);

        $metricBag = (new MetricBag())
            ->with('codeSmell.goto.count', 1)
            ->with('codeSmell.goto.line.0', 50);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(50, $violations[0]->location->line);
        self::assertSame('Found 1 goto statement(s) - avoid using goto', $violations[0]->message);
        self::assertSame('code-smell.goto', $violations[0]->ruleName);
        self::assertSame(1, $violations[0]->metricValue);
    }
}
