<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Size;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Rules\Size\PropertyCountOptions;
use AiMessDetector\Rules\Size\PropertyCountRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PropertyCountRule::class)]
#[CoversClass(PropertyCountOptions::class)]
final class PropertyCountRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions());
        self::assertSame('size.property-count', $rule->getName());
    }

    public function testGetCategory(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions());
        self::assertSame(RuleCategory::Size, $rule->getCategory());
    }

    public function testNoViolationBelowThreshold(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
        ));

        $context = $this->createContext(propertyCount: 8);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    public function testWarningAboveWarningThreshold(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
        ));

        $context = $this->createContext(propertyCount: 12);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('Property count is 12, exceeds threshold of 10. Consider splitting the class or using composition', $violations[0]->message);
    }

    public function testErrorAboveErrorThreshold(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
        ));

        $context = $this->createContext(propertyCount: 18);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertStringContainsString('Property count is 18, exceeds threshold of 15. Consider splitting the class or using composition', $violations[0]->message);
    }

    public function testCustomThresholds(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 5,
            error: 8,
        ));

        // Below warning threshold
        $context = $this->createContext(propertyCount: 4);
        self::assertCount(0, $rule->analyze($context));

        // Above warning threshold
        $context = $this->createContext(propertyCount: 6);
        $violations = $rule->analyze($context);
        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);

        // Above error threshold
        $context = $this->createContext(propertyCount: 10);
        $violations = $rule->analyze($context);
        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    public function testViolationHasCorrectSymbolPath(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
        ));

        $context = $this->createContext(
            propertyCount: 12,
            namespace: 'App\\Domain',
            class: 'User',
        );

        $violations = $rule->analyze($context);
        self::assertCount(1, $violations);

        $symbolPath = $violations[0]->symbolPath;
        self::assertSame('App\\Domain', $symbolPath->namespace);
        self::assertSame('User', $symbolPath->type);
    }

    public function testNoViolationWhenPropertyCountIsNull(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
        ));

        $bag = new MetricBag();
        // No propertyCount metric

        $symbolPath = SymbolPath::forClass('App', 'Test');
        $symbolInfo = new SymbolInfo(
            symbolPath: $symbolPath,
            file: __FILE__,
            line: 1,
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$symbolInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($bag);

        $context = new AnalysisContext($repository);

        $violations = $rule->analyze($context);
        self::assertCount(0, $violations);
    }

    public function testDefaultThresholds(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions());

        // Below default warning (15)
        $context = $this->createContext(propertyCount: 14);
        self::assertCount(0, $rule->analyze($context));

        // At default warning (15) — triggers warning with >= comparison
        $context = $this->createContext(propertyCount: 15);
        $violations = $rule->analyze($context);
        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);

        // Above default warning (15)
        $context = $this->createContext(propertyCount: 16);
        $violations = $rule->analyze($context);
        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);

        // At default error (20) — triggers error with >= comparison
        $context = $this->createContext(propertyCount: 20);
        $violations = $rule->analyze($context);
        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);

        // Above default error (20)
        $context = $this->createContext(propertyCount: 21);
        $violations = $rule->analyze($context);
        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    private function createContext(
        int $propertyCount,
        string $namespace = 'App',
        string $class = 'TestClass',
    ): AnalysisContext {
        $bag = (new MetricBag())
            ->with('propertyCount', $propertyCount);

        $symbolPath = SymbolPath::forClass($namespace, $class);
        $symbolInfo = new SymbolInfo(
            symbolPath: $symbolPath,
            file: __FILE__,
            line: 1,
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$symbolInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($bag);

        return new AnalysisContext($repository);
    }
}
