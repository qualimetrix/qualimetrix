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
use AiMessDetector\Rules\Security\XssRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(XssRule::class)]
#[CoversClass(SecurityPatternOptions::class)]
final class XssRuleTest extends TestCase
{
    public function testNameAndCategory(): void
    {
        $rule = new XssRule(new SecurityPatternOptions());

        self::assertSame('security.xss', $rule->getName());
        self::assertSame(RuleCategory::Security, $rule->getCategory());
        self::assertSame('Detects potential XSS vulnerabilities', $rule->getDescription());
    }

    public function testRequires(): void
    {
        $rule = new XssRule(new SecurityPatternOptions());

        self::assertSame(['security.xss'], $rule->requires());
    }

    public function testDisabledReturnsNoViolations(): void
    {
        $rule = new XssRule(new SecurityPatternOptions(enabled: false));

        $context = $this->createContext(
            (new MetricBag())->withEntry('security.xss', ['line' => 1, 'superglobal' => '']),
        );

        self::assertCount(0, $rule->analyze($context));
    }

    public function testNoFindingsReturnsNoViolations(): void
    {
        $rule = new XssRule(new SecurityPatternOptions());

        $context = $this->createContext(new MetricBag());

        self::assertCount(0, $rule->analyze($context));
    }

    public function testSingleFindingCreatesViolation(): void
    {
        $rule = new XssRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.xss', ['line' => 8, 'superglobal' => '']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(8, $violations[0]->location->line);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('security.xss', $violations[0]->ruleName);
        self::assertStringContainsString('XSS', $violations[0]->message);
    }

    public function testMultipleFindingsCreateMultipleViolations(): void
    {
        $rule = new XssRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.xss', ['line' => 5, 'superglobal' => ''])
                ->withEntry('security.xss', ['line' => 12, 'superglobal' => '']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame(5, $violations[0]->location->line);
        self::assertSame(12, $violations[1]->location->line);
    }

    public function testConstructorRejectsWrongOptionsType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $options = $this->createMock(\AiMessDetector\Core\Rule\RuleOptionsInterface::class);
        new XssRule($options);
    }

    private function createContext(MetricBag $metrics): AnalysisContext
    {
        $filePath = SymbolPath::forFile('src/View/Template.php');
        $fileInfo = new SymbolInfo(
            symbolPath: $filePath,
            file: 'src/View/Template.php',
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
