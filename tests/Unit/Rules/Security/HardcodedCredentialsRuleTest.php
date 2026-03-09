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

        self::assertSame(['security.hardcodedCredentials.count'], $rule->requires());
    }

    public function testDisabledReturnsNoViolations(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions(enabled: false));

        $context = $this->createContext(
            MetricBag::fromArray(['security.hardcodedCredentials.count' => 2]),
        );

        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    public function testNoFindingsReturnsNoViolations(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(
            MetricBag::fromArray(['security.hardcodedCredentials.count' => 0]),
        );

        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    public function testSingleFindingCreatesOneViolation(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(
            MetricBag::fromArray([
                'security.hardcodedCredentials.count' => 1,
                'security.hardcodedCredentials.line.0' => 15,
                'security.hardcodedCredentials.pattern.0' => 1,
            ]),
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
            MetricBag::fromArray([
                'security.hardcodedCredentials.count' => 3,
                'security.hardcodedCredentials.line.0' => 10,
                'security.hardcodedCredentials.line.1' => 25,
                'security.hardcodedCredentials.line.2' => 42,
            ]),
        );

        $violations = $rule->analyze($context);

        self::assertCount(3, $violations);
        self::assertSame(10, $violations[0]->location->line);
        self::assertSame(25, $violations[1]->location->line);
        self::assertSame(42, $violations[2]->location->line);
    }

    public function testEnumCasePatternCodeProducesCorrectMessage(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(
            MetricBag::fromArray([
                'security.hardcodedCredentials.count' => 1,
                'security.hardcodedCredentials.line.0' => 10,
                'security.hardcodedCredentials.pattern.0' => 7, // enum_case pattern code
            ]),
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
