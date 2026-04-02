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
use Qualimetrix\Rules\CodeSmell\ErrorSuppressionOptions;
use Qualimetrix\Rules\CodeSmell\ErrorSuppressionRule;

#[CoversClass(ErrorSuppressionRule::class)]
final class ErrorSuppressionRuleTest extends TestCase
{
    #[Test]
    public function nameAndDescriptionAreCorrect(): void
    {
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions());

        self::assertSame('code-smell.error-suppression', $rule->getName());
        self::assertSame('Detects usage of error suppression operator (@)', $rule->getDescription());
        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    #[Test]
    public function requiresReturnsExpectedMetrics(): void
    {
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions());

        self::assertSame(['codeSmell.error_suppression'], $rule->requires());
    }

    #[Test]
    public function optionsClassIsCorrect(): void
    {
        self::assertSame(ErrorSuppressionOptions::class, ErrorSuppressionRule::getOptionsClass());
    }

    #[Test]
    public function disabledRuleReturnsNoViolations(): void
    {
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function noSmellsProducesNoViolations(): void
    {
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions());

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
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions());

        $symbolPath = SymbolPath::forFile('src/Smelly.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/Smelly.php', null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.error_suppression', ['line' => 8])
            ->withEntry('codeSmell.error_suppression', ['line' => 22]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(8, $violations[0]->location->line);
        self::assertSame(22, $violations[1]->location->line);
        self::assertSame('code-smell.error-suppression', $violations[0]->ruleName);
        self::assertSame(1.0, $violations[0]->metricValue);
    }

    #[Test]
    public function messageIncludesFunctionName(): void
    {
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions());

        $symbolPath = SymbolPath::forFile('src/File.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/File.php', null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.error_suppression', ['line' => 10, 'extra' => 'fopen']);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame('Error suppression (@) on fopen() - handle errors explicitly', $violations[0]->message);
    }

    #[Test]
    public function allowedFunctionIsFiltered(): void
    {
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions(
            allowedFunctions: ['fopen', 'unlink'],
        ));

        $symbolPath = SymbolPath::forFile('src/File.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/File.php', null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.error_suppression', ['line' => 10, 'extra' => 'fopen'])
            ->withEntry('codeSmell.error_suppression', ['line' => 20, 'extra' => 'exec'])
            ->withEntry('codeSmell.error_suppression', ['line' => 30]); // no function name

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        // fopen is allowed, so only exec and the no-function entry should produce violations
        self::assertCount(2, $violations);
        self::assertSame(20, $violations[0]->location->line);
        self::assertSame(30, $violations[1]->location->line);
    }

    #[Test]
    public function methodCallNotFilteredByAllowedFunctions(): void
    {
        // @$obj->method() has no function name (extra is null) — always reported
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions(
            allowedFunctions: ['fopen'],
        ));

        $symbolPath = SymbolPath::forFile('src/File.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/File.php', null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.error_suppression', ['line' => 5]); // no extra = method call or other

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
    }

}
