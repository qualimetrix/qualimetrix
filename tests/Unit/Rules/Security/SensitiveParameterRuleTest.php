<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Security;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Rules\Security\SensitiveParameterOptions;
use AiMessDetector\Rules\Security\SensitiveParameterRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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

        self::assertSame(['security.sensitiveParameter.count'], $rule->requires());
    }

    public function testDisabledReturnsNoViolations(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions(enabled: false));

        $context = $this->createContext(
            MetricBag::fromArray(['security.sensitiveParameter.count' => 2]),
        );

        self::assertCount(0, $rule->analyze($context));
    }

    public function testNoFindingsReturnsNoViolations(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions());

        $context = $this->createContext(
            MetricBag::fromArray(['security.sensitiveParameter.count' => 0]),
        );

        self::assertCount(0, $rule->analyze($context));
    }

    public function testSingleFindingCreatesViolation(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions());

        $context = $this->createContext(
            MetricBag::fromArray([
                'security.sensitiveParameter.count' => 1,
                'security.sensitiveParameter.line.0' => 12,
            ]),
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
            MetricBag::fromArray([
                'security.sensitiveParameter.count' => 3,
                'security.sensitiveParameter.line.0' => 5,
                'security.sensitiveParameter.line.1' => 10,
                'security.sensitiveParameter.line.2' => 22,
            ]),
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

        $options = $this->createMock(\AiMessDetector\Core\Rule\RuleOptionsInterface::class);
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

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::File)
            ->willReturn([$fileInfo]);
        $repository->method('get')
            ->with($filePath)
            ->willReturn($metrics);

        return new AnalysisContext(metrics: $repository);
    }
}
