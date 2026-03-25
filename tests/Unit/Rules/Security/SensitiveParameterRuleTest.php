<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Security\SensitiveParameterOptions;
use Qualimetrix\Rules\Security\SensitiveParameterRule;

#[CoversClass(SensitiveParameterRule::class)]
#[CoversClass(SensitiveParameterOptions::class)]
final class SensitiveParameterRuleTest extends TestCase
{
    public function testNameAndCategory(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions());

        self::assertSame('security.sensitive-parameter', $rule->getName());
        self::assertSame(RuleCategory::Security, $rule->getCategory());
        self::assertStringContainsString('SensitiveParameter', $rule->getDescription());
    }

    public function testRequires(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions());

        self::assertSame(['security.sensitiveParameter'], $rule->requires());
    }

    public function testDisabledReturnsNoViolations(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions(enabled: false));

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sensitiveParameter', ['line' => 1])
                ->withEntry('security.sensitiveParameter', ['line' => 2]),
        );

        self::assertCount(0, $rule->analyze($context));
    }

    public function testNoFindingsReturnsNoViolations(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions());

        $context = $this->createContext(new MetricBag());

        self::assertCount(0, $rule->analyze($context));
    }

    public function testSingleFindingCreatesViolation(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sensitiveParameter', ['line' => 12]),
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(12, $violations[0]->location->line);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('security.sensitive-parameter', $violations[0]->ruleName);
        self::assertStringContainsString('SensitiveParameter', $violations[0]->message);
    }

    public function testMultipleFindingsCreateMultipleViolations(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sensitiveParameter', ['line' => 5])
                ->withEntry('security.sensitiveParameter', ['line' => 10])
                ->withEntry('security.sensitiveParameter', ['line' => 22]),
        );

        $violations = $rule->analyze($context);

        self::assertCount(3, $violations);
        self::assertSame(5, $violations[0]->location->line);
        self::assertSame(10, $violations[1]->location->line);
        self::assertSame(22, $violations[2]->location->line);
    }

    public function testOptionsFromArray(): void
    {
        $options = SensitiveParameterOptions::fromArray(['enabled' => false]);
        self::assertFalse($options->isEnabled());

        $options = SensitiveParameterOptions::fromArray([]);
        self::assertTrue($options->isEnabled());
    }

    public function testGetSeverity(): void
    {
        $options = new SensitiveParameterOptions();

        self::assertSame(Severity::Warning, $options->getSeverity(1));
        self::assertNull($options->getSeverity(0));
    }

    public function testConstructorRejectsWrongOptionsType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $options = $this->createStub(\Qualimetrix\Core\Rule\RuleOptionsInterface::class);
        new SensitiveParameterRule($options);
    }

    private function createContext(MetricBag $metrics): AnalysisContext
    {
        $filePath = SymbolPath::forFile('src/Auth/AuthService.php');
        $fileInfo = new SymbolInfo(
            symbolPath: $filePath,
            file: 'src/Auth/AuthService.php',
            line: null,
        );

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$fileInfo]);
        $repository->method('get')
            ->willReturn($metrics);

        return new AnalysisContext(metrics: $repository);
    }
}
