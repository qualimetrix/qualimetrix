<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\ErrorSuppressionOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\ErrorSuppressionRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

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
    public function disabledRuleReturnsNoFindings(): void
    {
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function noSmellsProducesNoFindings(): void
    {
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Clean.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Clean.php'), null);

        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function smellDetectedProducesFinding(): void
    {
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.error_suppression', ['subjectKind' => 'file', 'line' => 8])
            ->withEntry('codeSmell.error_suppression', ['subjectKind' => 'file', 'line' => 22]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(8, $findings[0]->location->line);
        self::assertSame(22, $findings[1]->location->line);
        self::assertSame('code-smell.error-suppression', $findings[0]->ruleName);
        self::assertSame(1.0, $findings[0]->metricValue);
    }

    #[Test]
    public function messageIncludesFunctionName(): void
    {
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/File.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/File.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.error_suppression', ['subjectKind' => 'file', 'line' => 10, 'extra' => 'fopen']);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame('Error suppression (@) on fopen() - handle errors explicitly', $findings[0]->message);
    }

    #[Test]
    public function allowedFunctionIsFiltered(): void
    {
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions(
            allowedFunctions: ['fopen', 'unlink'],
        ));

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/File.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/File.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.error_suppression', ['subjectKind' => 'file', 'line' => 10, 'extra' => 'fopen'])
            ->withEntry('codeSmell.error_suppression', ['subjectKind' => 'file', 'line' => 20, 'extra' => 'exec'])
            ->withEntry('codeSmell.error_suppression', ['subjectKind' => 'file', 'line' => 30]); // no function name

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        // fopen is allowed, so only exec and the no-function entry should produce findings
        self::assertCount(2, $findings);
        self::assertSame(20, $findings[0]->location->line);
        self::assertSame(30, $findings[1]->location->line);
    }

    #[Test]
    public function emptyExtraFallsBackToBaseTemplate(): void
    {
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/File.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/File.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.error_suppression', ['subjectKind' => 'file', 'line' => 10, 'extra' => '']);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(
            'Error suppression operator (@) detected - handle errors explicitly',
            $findings[0]->message,
        );
    }

    #[Test]
    public function methodCallNotFilteredByAllowedFunctions(): void
    {
        // @$obj->method() has no function name (extra is null) — always reported
        $rule = new ErrorSuppressionRule(new ErrorSuppressionOptions(
            allowedFunctions: ['fopen'],
        ));

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/File.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/File.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.error_suppression', ['subjectKind' => 'file', 'line' => 5]); // no extra = method call or other

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
    }

}
