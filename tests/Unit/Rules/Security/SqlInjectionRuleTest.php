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
use AiMessDetector\Rules\Security\SecurityPatternOptions;
use AiMessDetector\Rules\Security\SqlInjectionRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlInjectionRule::class)]
#[CoversClass(SecurityPatternOptions::class)]
final class SqlInjectionRuleTest extends TestCase
{
    public function testNameAndCategory(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        self::assertSame('security.sql-injection', $rule->getName());
        self::assertSame(RuleCategory::Security, $rule->getCategory());
        self::assertSame('Detects potential SQL injection vulnerabilities', $rule->getDescription());
    }

    public function testRequires(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        self::assertSame(['security.sql_injection'], $rule->requires());
    }

    public function testDisabledReturnsNoViolations(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions(enabled: false));

        $context = $this->createContext(
            (new MetricBag())->withEntry('security.sql_injection', ['line' => 1, 'superglobal' => '']),
        );

        self::assertCount(0, $rule->analyze($context));
    }

    public function testNoFindingsReturnsNoViolations(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(new MetricBag());

        self::assertCount(0, $rule->analyze($context));
    }

    public function testSingleFindingCreatesViolation(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sql_injection', ['line' => 15, 'superglobal' => '']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(15, $violations[0]->location->line);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('security.sql-injection', $violations[0]->ruleName);
        self::assertStringContainsString('SQL injection', $violations[0]->message);
    }

    public function testMultipleFindingsCreateMultipleViolations(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sql_injection', ['line' => 10, 'superglobal' => ''])
                ->withEntry('security.sql_injection', ['line' => 25, 'superglobal' => ''])
                ->withEntry('security.sql_injection', ['line' => 42, 'superglobal' => '']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(3, $violations);
        self::assertSame(10, $violations[0]->location->line);
        self::assertSame(25, $violations[1]->location->line);
        self::assertSame(42, $violations[2]->location->line);
    }

    public function testOptionsFromArray(): void
    {
        $options = SecurityPatternOptions::fromArray(['enabled' => false]);
        self::assertFalse($options->isEnabled());

        $options = SecurityPatternOptions::fromArray([]);
        self::assertTrue($options->isEnabled());
    }

    public function testGetSeverity(): void
    {
        $options = new SecurityPatternOptions();

        self::assertSame(Severity::Error, $options->getSeverity(1));
        self::assertNull($options->getSeverity(0));
    }

    public function testConstructorRejectsWrongOptionsType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $options = $this->createMock(\AiMessDetector\Core\Rule\RuleOptionsInterface::class);
        new SqlInjectionRule($options);
    }

    private function createContext(MetricBag $metrics): AnalysisContext
    {
        $filePath = SymbolPath::forFile('src/Controller/UserController.php');
        $fileInfo = new SymbolInfo(
            symbolPath: $filePath,
            file: 'src/Controller/UserController.php',
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
