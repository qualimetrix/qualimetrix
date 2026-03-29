<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Size;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Size\PropertyCountOptions;
use Qualimetrix\Rules\Size\PropertyCountRule;

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

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$symbolInfo]);
        $repository->method('get')
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

    public function testReadonlyClassIsExcludedByDefault(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
            excludeReadonly: true,
        ));

        $context = $this->createContext(propertyCount: 12, isReadonly: 1);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations, 'Readonly class should be excluded when excludeReadonly is true');
    }

    public function testReadonlyClassNotExcludedWhenFilterDisabled(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
            excludeReadonly: false,
        ));

        $context = $this->createContext(propertyCount: 12, isReadonly: 1);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations, 'Readonly class should NOT be excluded when excludeReadonly is false');
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    public function testPromotedOnlyClassIsExcludedByDefault(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
            excludePromotedOnly: true,
        ));

        $context = $this->createContext(propertyCount: 12, isPromotedOnly: 1);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations, 'Promoted-only class should be excluded when excludePromotedOnly is true');
    }

    public function testPromotedOnlyClassNotExcludedWhenFilterDisabled(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
            excludePromotedOnly: false,
        ));

        $context = $this->createContext(propertyCount: 12, isPromotedOnly: 1);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations, 'Promoted-only class should NOT be excluded when excludePromotedOnly is false');
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    public function testBothFiltersDisabledProducesViolations(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
            excludeReadonly: false,
            excludePromotedOnly: false,
        ));

        // Readonly + promoted-only class
        $context = $this->createContext(propertyCount: 12, isReadonly: 1, isPromotedOnly: 1);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations, 'Both filters disabled should produce violations');
    }

    private function createContext(
        int $propertyCount,
        string $namespace = 'App',
        string $class = 'TestClass',
        ?int $isReadonly = null,
        ?int $isPromotedOnly = null,
    ): AnalysisContext {
        $bag = (new MetricBag())
            ->with('propertyCount', $propertyCount);

        if ($isReadonly !== null) {
            $bag = $bag->with('isReadonly', $isReadonly);
        }

        if ($isPromotedOnly !== null) {
            $bag = $bag->with('isPromotedPropertiesOnly', $isPromotedOnly);
        }

        $symbolPath = SymbolPath::forClass($namespace, $class);
        $symbolInfo = new SymbolInfo(
            symbolPath: $symbolPath,
            file: __FILE__,
            line: 1,
        );

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$symbolInfo]);
        $repository->method('get')
            ->willReturn($bag);

        return new AnalysisContext($repository);
    }
}
