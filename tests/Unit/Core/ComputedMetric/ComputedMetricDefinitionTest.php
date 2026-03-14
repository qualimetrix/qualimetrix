<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Core\ComputedMetric;

use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinition;
use AiMessDetector\Core\Symbol\SymbolType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComputedMetricDefinition::class)]
final class ComputedMetricDefinitionTest extends TestCase
{
    public function testValidHealthName(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.complexity',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test metric',
            levels: [SymbolType::Class_],
        );

        self::assertSame('health.complexity', $definition->name);
    }

    public function testValidComputedName(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'computed.myMetric',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test metric',
            levels: [SymbolType::Class_],
        );

        self::assertSame('computed.myMetric', $definition->name);
    }

    public function testValidMultiSegmentName(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.complexity.sub1',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test metric',
            levels: [SymbolType::Class_],
        );

        self::assertSame('health.complexity.sub1', $definition->name);
    }

    public function testInvalidNameNoPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with "health." or "computed."');

        new ComputedMetricDefinition(
            name: 'custom.metric',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test',
            levels: [SymbolType::Class_],
        );
    }

    public function testInvalidNameContainsDoubleUnderscore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain "__"');

        new ComputedMetricDefinition(
            name: 'health.my__metric',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test',
            levels: [SymbolType::Class_],
        );
    }

    public function testInvalidNameSegmentStartsWithDigit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must match [a-zA-Z][a-zA-Z0-9_]*');

        new ComputedMetricDefinition(
            name: 'health.1invalid',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test',
            levels: [SymbolType::Class_],
        );
    }

    public function testInvalidNameSegmentWithSpecialChars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must match [a-zA-Z][a-zA-Z0-9_]*');

        new ComputedMetricDefinition(
            name: 'health.inv-alid',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test',
            levels: [SymbolType::Class_],
        );
    }

    public function testGetFormulaForLevelClass(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: [
                'class' => 'class_formula',
                'namespace' => 'namespace_formula',
            ],
            description: 'Test',
            levels: [SymbolType::Class_, SymbolType::Namespace_],
        );

        self::assertSame('class_formula', $definition->getFormulaForLevel(SymbolType::Class_));
    }

    public function testGetFormulaForLevelNamespace(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: [
                'class' => 'class_formula',
                'namespace' => 'namespace_formula',
            ],
            description: 'Test',
            levels: [SymbolType::Class_, SymbolType::Namespace_],
        );

        self::assertSame('namespace_formula', $definition->getFormulaForLevel(SymbolType::Namespace_));
    }

    public function testGetFormulaForLevelProjectExplicit(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: [
                'class' => 'class_formula',
                'namespace' => 'namespace_formula',
                'project' => 'project_formula',
            ],
            description: 'Test',
            levels: [SymbolType::Class_, SymbolType::Namespace_, SymbolType::Project],
        );

        self::assertSame('project_formula', $definition->getFormulaForLevel(SymbolType::Project));
    }

    public function testGetFormulaForLevelProjectInheritsFromNamespace(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: [
                'class' => 'class_formula',
                'namespace' => 'namespace_formula',
            ],
            description: 'Test',
            levels: [SymbolType::Class_, SymbolType::Namespace_, SymbolType::Project],
        );

        self::assertSame('namespace_formula', $definition->getFormulaForLevel(SymbolType::Project));
    }

    public function testGetFormulaForLevelReturnsNullForMethod(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'class_formula'],
            description: 'Test',
            levels: [SymbolType::Class_],
        );

        self::assertNull($definition->getFormulaForLevel(SymbolType::Method));
    }

    public function testGetFormulaForLevelReturnsNullForMissingClass(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['namespace' => 'namespace_formula'],
            description: 'Test',
            levels: [SymbolType::Namespace_],
        );

        self::assertNull($definition->getFormulaForLevel(SymbolType::Class_));
    }

    public function testHasLevel(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'formula'],
            description: 'Test',
            levels: [SymbolType::Class_, SymbolType::Namespace_],
        );

        self::assertTrue($definition->hasLevel(SymbolType::Class_));
        self::assertTrue($definition->hasLevel(SymbolType::Namespace_));
        self::assertFalse($definition->hasLevel(SymbolType::Project));
        self::assertFalse($definition->hasLevel(SymbolType::Method));
    }

    public function testThresholdFields(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'formula'],
            description: 'Test description',
            levels: [SymbolType::Class_],
            inverted: true,
            warningThreshold: 50.0,
            errorThreshold: 25.0,
        );

        self::assertTrue($definition->inverted);
        self::assertSame(50.0, $definition->warningThreshold);
        self::assertSame(25.0, $definition->errorThreshold);
        self::assertSame('Test description', $definition->description);
    }

    public function testDefaultThresholdValues(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'formula'],
            description: 'Test',
            levels: [SymbolType::Class_],
        );

        self::assertFalse($definition->inverted);
        self::assertNull($definition->warningThreshold);
        self::assertNull($definition->errorThreshold);
    }
}
