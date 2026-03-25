<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\CodeSmell;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\CodeSmell\CodeSmellOptions;
use Qualimetrix\Rules\CodeSmell\DebugCodeRule;

#[CoversClass(DebugCodeRule::class)]
final class DebugCodeRuleTest extends TestCase
{
    #[Test]
    public function nameAndDescriptionAreCorrect(): void
    {
        $rule = new DebugCodeRule(new CodeSmellOptions());

        self::assertSame('code-smell.debug-code', $rule->getName());
        self::assertSame('Detects debug code (var_dump, print_r, dd, etc)', $rule->getDescription());
        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    #[Test]
    public function requiresReturnsExpectedMetrics(): void
    {
        $rule = new DebugCodeRule(new CodeSmellOptions());

        self::assertSame(['codeSmell.debug_code'], $rule->requires());
    }

    #[Test]
    public function optionsClassIsCorrect(): void
    {
        self::assertSame(CodeSmellOptions::class, DebugCodeRule::getOptionsClass());
    }

    #[Test]
    public function disabledRuleReturnsNoViolations(): void
    {
        $rule = new DebugCodeRule(new CodeSmellOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function noSmellsProducesNoViolations(): void
    {
        $rule = new DebugCodeRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile('src/Clean.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/Clean.php', null);

        $metricBag = new MetricBag();

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function smellDetectedProducesViolation(): void
    {
        $rule = new DebugCodeRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile('src/Smelly.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/Smelly.php', null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.debug_code', ['line' => 5])
            ->withEntry('codeSmell.debug_code', ['line' => 12])
            ->withEntry('codeSmell.debug_code', ['line' => 30]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(3, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(5, $violations[0]->location->line);
        self::assertSame(12, $violations[1]->location->line);
        self::assertSame(30, $violations[2]->location->line);
        self::assertSame('Debug function call detected - remove before production', $violations[0]->message);
        self::assertSame('code-smell.debug-code', $violations[0]->ruleName);
        self::assertSame(1.0, $violations[0]->metricValue);
    }
}
