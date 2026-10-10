<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricOverrideReader;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricsSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ExcludeHealthSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Core\Symbol\SymbolLevel;

#[CoversClass(ComputedMetricDefinition::class)]
final class ComputedMetricDefinitionTest extends TestCase
{
    #[Test]
    public function itValidHealthName(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.complexity',
            formulas: ['class' => 'm["complexity.ccn.avg"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        );

        self::assertSame('health.complexity', $definition->name);
    }

    #[Test]
    public function itValidComputedName(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'computed.my-metric',
            formulas: ['class' => 'm["complexity.ccn.avg"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        );

        self::assertSame('computed.my-metric', $definition->name);
    }

    #[Test]
    public function itValidMultiSegmentName(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.complexity.sub1',
            formulas: ['class' => 'm["complexity.ccn.avg"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        );

        self::assertSame('health.complexity.sub1', $definition->name);
    }

    #[Test]
    public function itInvalidNameNoPrefix(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must be "health.<name>" or "computed.<name>"');

        new ComputedMetricDefinition(
            name: 'custom.metric',
            formulas: ['class' => 'm["complexity.ccn.avg"]'],
            description: 'Test',
            levels: [SymbolLevel::Class_],
        );
    }

    #[Test]
    public function itInvalidNameContainsDoubleUnderscore(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('lower-case kebab');

        new ComputedMetricDefinition(
            name: 'health.my__metric',
            formulas: ['class' => 'm["complexity.ccn.avg"]'],
            description: 'Test',
            levels: [SymbolLevel::Class_],
        );
    }

    #[Test]
    public function itInvalidNameSegmentStartsWithDigit(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('lower-case kebab');

        new ComputedMetricDefinition(
            name: 'health.1invalid',
            formulas: ['class' => 'm["complexity.ccn.avg"]'],
            description: 'Test',
            levels: [SymbolLevel::Class_],
        );
    }

    #[Test]
    public function itInvalidNameSegmentWithSpecialChars(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('lower-case kebab');

        new ComputedMetricDefinition(
            name: 'health.inv@lid',
            formulas: ['class' => 'm["complexity.ccn.avg"]'],
            description: 'Test',
            levels: [SymbolLevel::Class_],
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
            levels: [SymbolLevel::Class_, SymbolLevel::Namespace_],
        );

        self::assertSame('class_formula', $definition->getFormulaForLevel(SymbolLevel::Class_));
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
            levels: [SymbolLevel::Class_, SymbolLevel::Namespace_],
        );

        self::assertSame('namespace_formula', $definition->getFormulaForLevel(SymbolLevel::Namespace_));
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
            levels: [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project],
        );

        self::assertSame('project_formula', $definition->getFormulaForLevel(SymbolLevel::Project));
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
            levels: [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project],
        );

        self::assertSame('namespace_formula', $definition->getFormulaForLevel(SymbolLevel::Project));
    }

    #[Test]
    public function itGetFormulaForLevelReturnsNullForMethod(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'class_formula'],
            description: 'Test',
            levels: [SymbolLevel::Class_],
        );

        self::assertNull($definition->getFormulaForLevel(SymbolLevel::Callable));
    }

    #[Test]
    public function itGetFormulaForLevelReturnsNullForMissingClass(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['namespace' => 'namespace_formula'],
            description: 'Test',
            levels: [SymbolLevel::Namespace_],
        );

        self::assertNull($definition->getFormulaForLevel(SymbolLevel::Class_));
    }

    #[Test]
    public function itHasLevel(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'formula'],
            description: 'Test',
            levels: [SymbolLevel::Class_, SymbolLevel::Namespace_],
        );

        self::assertTrue($definition->hasLevel(SymbolLevel::Class_));
        self::assertTrue($definition->hasLevel(SymbolLevel::Namespace_));
        self::assertFalse($definition->hasLevel(SymbolLevel::Project));
        self::assertFalse($definition->hasLevel(SymbolLevel::Callable));
    }

    #[Test]
    public function itThresholdFields(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'formula'],
            description: 'Test description',
            levels: [SymbolLevel::Class_],
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
            levels: [SymbolLevel::Class_],
        );

        self::assertFalse($definition->inverted);
        self::assertNull($definition->warningThreshold);
        self::assertNull($definition->errorThreshold);
    }
    #[Test]
    public function itSelectsBuiltinPoliciesAlongsideTheEffectiveFormula(): void
    {
        $base = ComputedMetricDefaults::getDefaults()['health.cohesion'];
        self::assertSame($base->getApplicabilityForLevel(SymbolLevel::Namespace_), $base->getApplicabilityForLevel(SymbolLevel::Project));
        foreach ([['warning' => 1], ['description' => 'changed'], ['levels' => ['project']]] as $metadata) {
            $merged = self::override($base, $metadata);
            self::assertTrue($merged->isBuiltinFormulaForLevel(SymbolLevel::Project));
            self::assertFalse($merged->getApplicabilityForLevel(SymbolLevel::Project)->appliesTo([]));
        }
        $copied = self::override($base, ['formulas' => ['namespace' => $base->formulas['namespace']]]);
        self::assertFalse($copied->isBuiltinFormulaForLevel(SymbolLevel::Namespace_));
        self::assertFalse($copied->isBuiltinFormulaForLevel(SymbolLevel::Project));
        self::assertTrue($copied->getApplicabilityForLevel(SymbolLevel::Project)->appliesTo([]));
        self::assertTrue($copied->isBuiltinFormulaForLevel(SymbolLevel::Class_));
        $explicit = self::override(ComputedMetricDefaults::getDefaults()['health.complexity'], ['formulas' => ['namespace' => '80']]);
        self::assertFalse($explicit->isBuiltinFormulaForLevel(SymbolLevel::Namespace_));
        self::assertTrue($explicit->isBuiltinFormulaForLevel(SymbolLevel::Project));
        $all = self::override($base, ['formula' => '80', 'formulas' => ['class' => '90']]);
        self::assertSame('90', $all->getFormulaForLevel(SymbolLevel::Class_));
        self::assertSame('80', $all->getFormulaForLevel(SymbolLevel::Project));
        foreach ([SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project] as $level) {
            self::assertFalse($all->isBuiltinFormulaForLevel($level));
            self::assertTrue($all->getApplicabilityForLevel($level)->appliesTo([]));
        }
    }

    #[Test]
    public function itKeepsBareBuiltinAndSyntheticDefinitionsDistinct(): void
    {
        $resolver = new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression()));
        $document = DocumentComposer::compose(new DocumentSchema([new ComputedMetricsSection(), new ExcludeHealthSection()]), [
            new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'), AuthoredNode::fromPlain(['computed_metrics' => ['health.cohesion' => null]])),
        ]);
        foreach ($resolver->resolve($document) as $definition) {
            if ($definition->name === 'health.cohesion') {
                self::assertTrue($definition->isBuiltinFormulaForLevel(SymbolLevel::Project));
                self::assertFalse($definition->getApplicabilityForLevel(SymbolLevel::Project)->appliesTo([]));
            }
        }
        $synthetic = new ComputedMetricDefinition('health.cohesion', ['namespace' => '80'], '', [SymbolLevel::Project]);
        self::assertFalse($synthetic->isBuiltinFormulaForLevel(SymbolLevel::Project));
        self::assertTrue($synthetic->getApplicabilityForLevel(SymbolLevel::Project)->appliesTo([]));
    }

    /** @param array<string, mixed> $values */
    private static function override(ComputedMetricDefinition $base, array $values): ComputedMetricDefinition
    {
        $document = DocumentComposer::compose(new DocumentSchema([new ComputedMetricsSection()]), [
            new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'), AuthoredNode::fromPlain(['computed_metrics' => [$base->name => $values]])),
        ]);
        $section = $document->get('computed_metrics');
        \assert($section instanceof ResolvedMapInterface);
        $entry = $section->get($base->name);
        \assert($entry instanceof ResolvedMapInterface);

        return ComputedMetricOverrideReader::merge($base, $entry);
    }

}
