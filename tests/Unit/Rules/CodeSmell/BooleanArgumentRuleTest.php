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
use AiMessDetector\Rules\CodeSmell\BooleanArgumentRule;
use AiMessDetector\Rules\CodeSmell\CodeSmellOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BooleanArgumentRule::class)]
final class BooleanArgumentRuleTest extends TestCase
{
    #[Test]
    public function nameAndDescriptionAreCorrect(): void
    {
        $rule = new BooleanArgumentRule(new CodeSmellOptions());

        self::assertSame('code-smell.boolean-argument', $rule->getName());
        self::assertSame('Detects boolean arguments in method/function signatures', $rule->getDescription());
        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    #[Test]
    public function requiresReturnsExpectedMetrics(): void
    {
        $rule = new BooleanArgumentRule(new CodeSmellOptions());

        self::assertSame(['codeSmell.boolean_argument.count'], $rule->requires());
    }

    #[Test]
    public function optionsClassIsCorrect(): void
    {
        self::assertSame(CodeSmellOptions::class, BooleanArgumentRule::getOptionsClass());
    }

    #[Test]
    public function disabledRuleReturnsNoViolations(): void
    {
        $rule = new BooleanArgumentRule(new CodeSmellOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function noSmellsProducesNoViolations(): void
    {
        $rule = new BooleanArgumentRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile('src/Clean.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/Clean.php', null);

        $metricBag = (new MetricBag())->with('codeSmell.boolean_argument.count', 0);

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
        $rule = new BooleanArgumentRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile('src/Smelly.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/Smelly.php', null);

        $metricBag = (new MetricBag())
            ->with('codeSmell.boolean_argument.count', 2)
            ->with('codeSmell.boolean_argument.line.0', 10)
            ->with('codeSmell.boolean_argument.line.1', 25);

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
        self::assertSame(25, $violations[1]->location->line);
        self::assertSame('Found 2 boolean argument(s) - consider splitting methods or using enums', $violations[0]->message);
        self::assertSame('code-smell.boolean-argument', $violations[0]->ruleName);
        self::assertSame('code-smell.boolean-argument', $violations[0]->violationCode);
        self::assertSame(2, $violations[0]->metricValue);
    }
}
