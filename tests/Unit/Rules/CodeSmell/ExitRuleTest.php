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
use AiMessDetector\Rules\CodeSmell\ExitRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExitRule::class)]
final class ExitRuleTest extends TestCase
{
    #[Test]
    public function nameAndDescriptionAreCorrect(): void
    {
        $rule = new ExitRule(new CodeSmellOptions());

        self::assertSame('code-smell.exit', $rule->getName());
        self::assertSame('Detects usage of exit() and die()', $rule->getDescription());
        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    #[Test]
    public function requiresReturnsExpectedMetrics(): void
    {
        $rule = new ExitRule(new CodeSmellOptions());

        self::assertSame(['codeSmell.exit.count'], $rule->requires());
    }

    #[Test]
    public function optionsClassIsCorrect(): void
    {
        self::assertSame(CodeSmellOptions::class, ExitRule::getOptionsClass());
    }

    #[Test]
    public function disabledRuleReturnsNoViolations(): void
    {
        $rule = new ExitRule(new CodeSmellOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function noSmellsProducesNoViolations(): void
    {
        $rule = new ExitRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile('src/Clean.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/Clean.php', null);

        $metricBag = (new MetricBag())->with('codeSmell.exit.count', 0);

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
        $rule = new ExitRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile('src/Smelly.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/Smelly.php', null);

        $metricBag = (new MetricBag())
            ->with('codeSmell.exit.count', 2)
            ->with('codeSmell.exit.line.0', 10)
            ->with('codeSmell.exit.line.1', 35);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(10, $violations[0]->location->line);
        self::assertSame(35, $violations[1]->location->line);
        self::assertSame('Found 2 exit()/die() call(s) - use exceptions instead', $violations[0]->message);
        self::assertSame('code-smell.exit', $violations[0]->ruleName);
        self::assertSame(2, $violations[0]->metricValue);
    }
}
