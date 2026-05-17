<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\ComputedMetric;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\Symbol\SymbolType;

#[CoversClass(ComputedMetricDefinition::class)]
final class ComputedMetricDefinitionTest extends TestCase
{
    #[Test]
    public function itValidHealthName(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.complexity',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test metric',
            levels: [SymbolType::Class_],
        );

        self::assertSame('health.complexity', $definition->name);
    }

    #[Test]
    public function itValidComputedName(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'computed.myMetric',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test metric',
            levels: [SymbolType::Class_],
        );

        self::assertSame('computed.myMetric', $definition->name);
    }

    #[Test]
    public function itValidMultiSegmentName(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.complexity.sub1',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test metric',
            levels: [SymbolType::Class_],
        );

        self::assertSame('health.complexity.sub1', $definition->name);
    }

    #[Test]
    public function itInvalidNameNoPrefix(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must start with "health." or "computed."');

        new ComputedMetricDefinition(
            name: 'custom.metric',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test',
            levels: [SymbolType::Class_],
        );
    }

    #[Test]
    public function itInvalidNameContainsDoubleUnderscore(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must not contain "__"');

        new ComputedMetricDefinition(
            name: 'health.my__metric',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test',
            levels: [SymbolType::Class_],
        );
    }

    #[Test]
    public function itInvalidNameSegmentStartsWithDigit(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must match [a-zA-Z][a-zA-Z0-9_]*');

        new ComputedMetricDefinition(
            name: 'health.1invalid',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test',
            levels: [SymbolType::Class_],
        );
    }

    #[Test]
    public function itInvalidNameSegmentWithSpecialChars(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must match [a-zA-Z][a-zA-Z0-9_]*');

        new ComputedMetricDefinition(
            name: 'health.inv-alid',
            formulas: ['class' => 'ccn__avg'],
            description: 'Test',
            levels: [SymbolType::Class_],
        );
    }

    #[Test]
    public function itGetFormulaForLevelClass(): void
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

    #[Test]
    public function itGetFormulaForLevelNamespace(): void
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

    #[Test]
    public function itGetFormulaForLevelProjectExplicit(): void
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

    #[Test]
    public function itGetFormulaForLevelProjectInheritsFromNamespace(): void
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

    #[Test]
    public function itGetFormulaForLevelReturnsNullForMethod(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'class_formula'],
            description: 'Test',
            levels: [SymbolType::Class_],
        );

        self::assertNull($definition->getFormulaForLevel(SymbolType::Method));
    }

    #[Test]
    public function itGetFormulaForLevelReturnsNullForMissingClass(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['namespace' => 'namespace_formula'],
            description: 'Test',
            levels: [SymbolType::Namespace_],
        );

        self::assertNull($definition->getFormulaForLevel(SymbolType::Class_));
    }

    #[Test]
    public function itHasLevel(): void
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

    #[Test]
    public function itThresholdFields(): void
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

    #[Test]
    public function itDefaultThresholdValues(): void
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
