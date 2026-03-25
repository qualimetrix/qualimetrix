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
use Qualimetrix\Rules\CodeSmell\BooleanArgumentRule;
use Qualimetrix\Rules\CodeSmell\CodeSmellOptions;

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

        self::assertSame(['codeSmell.boolean_argument'], $rule->requires());
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
        $rule = new BooleanArgumentRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile('src/Smelly.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/Smelly.php', null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['line' => 10])
            ->withEntry('codeSmell.boolean_argument', ['line' => 25]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(10, $violations[0]->location->line);
        self::assertSame(25, $violations[1]->location->line);
        self::assertSame('Boolean argument detected - consider splitting methods or using enums', $violations[0]->message);
        self::assertSame('code-smell.boolean-argument', $violations[0]->ruleName);
        self::assertSame('code-smell.boolean-argument', $violations[0]->violationCode);
        self::assertSame(1.0, $violations[0]->metricValue);
    }

    #[Test]
    public function smellWithParamNameIncludesItInMessage(): void
    {
        $rule = new BooleanArgumentRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile('src/Smelly.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/Smelly.php', null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['line' => 10, 'extra' => 'overwrite'])
            ->withEntry('codeSmell.boolean_argument', ['line' => 25, 'extra' => '$silent']);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame('Boolean argument $overwrite detected - consider splitting methods or using enums', $violations[0]->message);
        // Leading $ in stored extra is stripped
        self::assertSame('Boolean argument $silent detected - consider splitting methods or using enums', $violations[1]->message);
    }

    #[Test]
    public function smellWithoutParamNameFallsBackToGenericMessage(): void
    {
        $rule = new BooleanArgumentRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile('src/Smelly.php');
        $fileInfo = new SymbolInfo($symbolPath, 'src/Smelly.php', null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['line' => 10]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame('Boolean argument detected - consider splitting methods or using enums', $violations[0]->message);
    }
}
