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
use AiMessDetector\Rules\Security\HardcodedCredentialsOptions;
use AiMessDetector\Rules\Security\HardcodedCredentialsRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HardcodedCredentialsRule::class)]
#[CoversClass(HardcodedCredentialsOptions::class)]
final class HardcodedCredentialsRuleTest extends TestCase
{
    public function testNameAndCategory(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        self::assertSame('security.hardcoded-credentials', $rule->getName());
        self::assertSame(RuleCategory::Security, $rule->getCategory());
        self::assertSame('Detects hardcoded credentials in code', $rule->getDescription());
    }

    public function testRequires(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        self::assertSame(['security.hardcodedCredentials'], $rule->requires());
    }

    public function testDisabledReturnsNoViolations(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions(enabled: false));

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['line' => 1, 'pattern' => 'variable'])
                ->withEntry('security.hardcodedCredentials', ['line' => 2, 'pattern' => 'variable']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    public function testNoFindingsReturnsNoViolations(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(new MetricBag());

        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    public function testSingleFindingCreatesOneViolation(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['line' => 15, 'pattern' => 'variable']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(15, $violations[0]->location->line);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('security.hardcoded-credentials', $violations[0]->ruleName);
        self::assertStringContainsString('variable assignment', $violations[0]->message);
    }

    public function testMultipleFindingsCreateMultipleViolations(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['line' => 10, 'pattern' => 'variable'])
                ->withEntry('security.hardcodedCredentials', ['line' => 25, 'pattern' => 'array_key'])
                ->withEntry('security.hardcodedCredentials', ['line' => 42, 'pattern' => 'define']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(3, $violations);
        self::assertSame(10, $violations[0]->location->line);
        self::assertSame(25, $violations[1]->location->line);
        self::assertSame(42, $violations[2]->location->line);
    }

    public function testEnumCasePatternProducesCorrectMessage(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['line' => 10, 'pattern' => 'enum_case']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('enum case', $violations[0]->message);
        self::assertSame('security.hardcoded-credentials', $violations[0]->violationCode);
    }

    public function testOptionsFromArray(): void
    {
        $options = HardcodedCredentialsOptions::fromArray(['enabled' => false]);
        self::assertFalse($options->isEnabled());

        $options = HardcodedCredentialsOptions::fromArray([]);
        self::assertTrue($options->isEnabled());
    }

    public function testGetSeverity(): void
    {
        $options = new HardcodedCredentialsOptions();

        self::assertSame(Severity::Error, $options->getSeverity(1));
        self::assertNull($options->getSeverity(0));
    }

    public function testConstructorRejectsWrongOptionsType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $options = $this->createMock(\AiMessDetector\Core\Rule\RuleOptionsInterface::class);
        new HardcodedCredentialsRule($options);
    }

    private function createContext(MetricBag $metrics): AnalysisContext
    {
        $filePath = SymbolPath::forFile('src/Config/Database.php');
        $fileInfo = new SymbolInfo(
            symbolPath: $filePath,
            file: 'src/Config/Database.php',
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
