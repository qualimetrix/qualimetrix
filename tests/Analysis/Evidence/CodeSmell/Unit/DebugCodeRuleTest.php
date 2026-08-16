<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\DebugCodeRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use ReflectionClass;

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
    public function severityIsError(): void
    {
        $reflection = new ReflectionClass(DebugCodeRule::class);

        self::assertSame(Severity::Error, $reflection->getConstant('SEVERITY'));
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

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Clean.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Clean.php'), null);

        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
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

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.debug_code', ['subjectKind' => 'file', 'line' => 5])
            ->withEntry('codeSmell.debug_code', ['subjectKind' => 'file', 'line' => 12])
            ->withEntry('codeSmell.debug_code', ['subjectKind' => 'file', 'line' => 30]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(3, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(5, $violations[0]->location->line);
        self::assertSame(12, $violations[1]->location->line);
        self::assertSame(30, $violations[2]->location->line);
        self::assertSame('Debug function call detected - remove before production', $violations[0]->message);
        self::assertSame('code-smell.debug-code', $violations[0]->ruleName);
        self::assertSame(1.0, $violations[0]->metricValue);
    }
}
