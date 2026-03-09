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
use AiMessDetector\Rules\CodeSmell\UnreachableCodeOptions;
use AiMessDetector\Rules\CodeSmell\UnreachableCodeRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnreachableCodeRule::class)]
#[CoversClass(UnreachableCodeOptions::class)]
final class UnreachableCodeRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions());

        self::assertSame('code-smell.unreachable-code', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions());

        self::assertSame('Detects unreachable code after terminal statements', $rule->getDescription());
    }

    public function testGetCategory(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions());

        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions());

        self::assertSame(['unreachableCode'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(UnreachableCodeOptions::class, UnreachableCodeRule::getOptionsClass());
    }

    public function testGetCliAliases(): void
    {
        self::assertSame(
            ['unreachable-code-warning' => 'warning', 'unreachable-code-error' => 'error'],
            UnreachableCodeRule::getCliAliases(),
        );
    }

    public function testConstructorRejectsWrongOptionsType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UnreachableCodeRule(new class implements \AiMessDetector\Core\Rule\RuleOptionsInterface {
            public static function fromArray(array $config): static
            {
                return new static();
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function getSeverity(int|float $value): ?Severity
            {
                return null;
            }
        });
    }

    public function testAnalyzeDisabledReturnsEmpty(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testNoUnreachableCode(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('unreachableCode', 0);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testWithUnreachableCode(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())
            ->with('unreachableCode', 2)
            ->with('unreachableCode.firstLine', 15);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(15, $violations[0]->location->line);
        self::assertSame('Found 2 unreachable statement(s) after terminal statement (return/throw/exit/break/continue). Dead code should be removed', $violations[0]->message);
        self::assertSame(2, $violations[0]->metricValue);
        self::assertSame('code-smell.unreachable-code', $violations[0]->ruleName);
        self::assertSame('code-smell.unreachable-code', $violations[0]->violationCode);
    }

    public function testWithUnreachableCodeFallsBackToMethodLine(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('unreachableCode', 1);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(10, $violations[0]->location->line);
    }

    public function testCustomThresholds(): void
    {
        $options = UnreachableCodeOptions::fromArray([
            'enabled' => true,
            'warning' => 2,
            'error' => 3,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertSame(2, $options->warning);
        self::assertSame(3, $options->error);

        // 1 unreachable statement — no violation with custom thresholds
        self::assertNull($options->getSeverity(1));
        // 2 — warning
        self::assertSame(Severity::Warning, $options->getSeverity(2));
        // 3 — error
        self::assertSame(Severity::Error, $options->getSeverity(3));
    }

    public function testOptionsFromEmptyArrayDisabled(): void
    {
        $options = UnreachableCodeOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }

    public function testOptionsDefaultValues(): void
    {
        $options = new UnreachableCodeOptions();

        self::assertTrue($options->isEnabled());
        self::assertSame(1, $options->warning);
        self::assertSame(2, $options->error);
    }

    public function testDefaultThresholdsWarningSingleUnreachable(): void
    {
        $options = new UnreachableCodeOptions();

        // 1 unreachable: warning (not error, unlike before)
        self::assertSame(Severity::Warning, $options->getSeverity(1));
        // 2+ unreachable: error
        self::assertSame(Severity::Error, $options->getSeverity(2));
    }
}
