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
use Qualimetrix\Rules\Security\CommandInjectionRule;
use Qualimetrix\Rules\Security\SecurityPatternOptions;

#[CoversClass(CommandInjectionRule::class)]
#[CoversClass(SecurityPatternOptions::class)]
final class CommandInjectionRuleTest extends TestCase
{
    public function testNameAndCategory(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        self::assertSame('security.command-injection', $rule->getName());
        self::assertSame(RuleCategory::Security, $rule->getCategory());
        self::assertSame('Detects potential command injection vulnerabilities', $rule->getDescription());
    }

    public function testRequires(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        self::assertSame(['security.command_injection'], $rule->requires());
    }

    public function testDisabledReturnsNoViolations(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions(enabled: false));

        $context = $this->createContext(
            (new MetricBag())->withEntry('security.command_injection', ['line' => 1, 'superglobal' => '']),
        );

        self::assertCount(0, $rule->analyze($context));
    }

    public function testNoFindingsReturnsNoViolations(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(new MetricBag());

        self::assertCount(0, $rule->analyze($context));
    }

    public function testSingleFindingCreatesViolation(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.command_injection', ['line' => 20, 'superglobal' => '']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(20, $violations[0]->location->line);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('security.command-injection', $violations[0]->ruleName);
        self::assertStringContainsString('command injection', $violations[0]->message);
    }

    public function testMultipleFindingsCreateMultipleViolations(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.command_injection', ['line' => 10, 'superglobal' => ''])
                ->withEntry('security.command_injection', ['line' => 30, 'superglobal' => '']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame(10, $violations[0]->location->line);
        self::assertSame(30, $violations[1]->location->line);
    }

    public function testConstructorRejectsWrongOptionsType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $options = $this->createStub(\Qualimetrix\Core\Rule\RuleOptionsInterface::class);
        new CommandInjectionRule($options);
    }

    private function createContext(MetricBag $metrics): AnalysisContext
    {
        $filePath = SymbolPath::forFile('src/Service/DeployService.php');
        $fileInfo = new SymbolInfo(
            symbolPath: $filePath,
            file: 'src/Service/DeployService.php',
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
