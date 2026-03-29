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
use Qualimetrix\Rules\Security\SecurityPatternOptions;
use Qualimetrix\Rules\Security\SqlInjectionRule;

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

    public function testSuperglobalIncludedInViolationMessage(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sql_injection', ['line' => 15, 'superglobal' => '_GET']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('($_GET)', $violations[0]->message);
        self::assertStringContainsString('SQL injection', $violations[0]->message);
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

        $options = $this->createStub(\Qualimetrix\Core\Rule\RuleOptionsInterface::class);
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

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$fileInfo]);
        $repository->method('get')
            ->willReturn($metrics);

        return new AnalysisContext(metrics: $repository);
    }
}
