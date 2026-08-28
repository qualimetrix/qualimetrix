<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Reporting\Health\HealthHintProjector;

#[CoversClass(HealthHintProjector::class)]
final class HealthHintProjectorTest extends TestCase
{
    private HealthHintProjector $projector;

    protected function setUp(): void
    {
        $this->projector = new HealthHintProjector(new HealthMetricCatalog());
    }

    #[Test]
    public function itExportForHtmlReturnsExpectedTopLevelKeys(): void
    {
        $result = $this->projector->project();
        self::assertArrayHasKey('metricHints', $result);
        self::assertArrayHasKey('healthDecomposition', $result);
    }

    #[Test]
    public function itExportForHtmlMetricHintsContainsAllRangedMetrics(): void
    {
        $hints = $this->projector->project()['metricHints'];
        $expectedKeys = ['complexity.ccn', 'complexity.cognitive', 'complexity.npath', 'cohesion.lcom', 'cohesion.tcc', 'cohesion.lcc', 'complexity.wmc', 'coupling.cbo', 'coupling.instability', 'coupling.abstractness', 'coupling.distance', 'coupling.class-rank', 'design.dit', 'design.noc', 'coupling.rfc', 'size.method-count', 'size.property-count', 'size.class-count.sum', 'maintainability.mi', 'design.type-coverage.pct', 'design.type-coverage.param', 'design.type-coverage.return', 'design.type-coverage.property'];
        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $hints, "Missing metric hint for: {$key}");
            self::assertArrayHasKey('label', $hints[$key]);
            self::assertArrayHasKey('ranges', $hints[$key]);
            self::assertArrayHasKey('formatTemplate', $hints[$key]);
        }
        self::assertArrayNotHasKey('size.loc', $hints);
        self::assertArrayNotHasKey('size.lloc', $hints);
        self::assertArrayNotHasKey('size.cloc', $hints);
    }

    #[Test]
    public function itExportForHtmlLabelsAreDescriptive(): void
    {
        $hints = $this->projector->project()['metricHints'];
        self::assertSame('Cyclomatic Complexity', $hints['complexity.ccn']['label']);
        self::assertSame('Cognitive Complexity', $hints['complexity.cognitive']['label']);
        self::assertSame('Tight Class Cohesion', $hints['cohesion.tcc']['label']);
        self::assertSame('Maintainability Index', $hints['maintainability.mi']['label']);
    }

    #[Test]
    public function itExportForHtmlEveryRangedMetricHasLabel(): void
    {
        foreach ($this->projector->project()['metricHints'] as $key => $hint) {
            self::assertNotEmpty($hint['label'], "Metric '{$key}' has empty label");
            self::assertNotSame($key, $hint['label'], "Metric '{$key}' label should not be the raw key");
        }
    }

    #[Test]
    public function itExportForHtmlRangesEndWithAbove(): void
    {
        foreach ($this->projector->project()['metricHints'] as $key => $hint) {
            $last = end($hint['ranges']);
            self::assertTrue($last['above'] ?? false, "{$key} should end with above:true");
        }
    }

    #[Test]
    public function itExportForHtmlFormatTemplateOnlyOnLcom(): void
    {
        $hints = $this->projector->project()['metricHints'];
        self::assertSame('{value} disconnected group{plural}', $hints['cohesion.lcom']['formatTemplate']);
        foreach ($hints as $key => $hint) {
            if ($key !== 'cohesion.lcom') {
                self::assertNull($hint['formatTemplate'], "{$key} should have null formatTemplate");
            }
        }
    }

    #[Test]
    public function itExportForHtmlHealthDecompositionHasAllDimensions(): void
    {
        $decomposition = $this->projector->project()['healthDecomposition'];
        foreach (['health.complexity', 'health.cohesion', 'health.coupling', 'health.typing', 'health.maintainability', 'health.overall'] as $dimension) {
            self::assertArrayHasKey($dimension, $decomposition, "Missing health dimension: {$dimension}");
            self::assertArrayHasKey('inputs', $decomposition[$dimension]);
        }
    }

    #[Test]
    public function itExportForHtmlHealthInputsHaveRequiredFields(): void
    {
        foreach ($this->projector->project()['healthDecomposition'] as $dimension => $data) {
            foreach ($data['inputs'] as $i => $input) {
                foreach (['key', 'altKey', 'label', 'ideal', 'direction'] as $field) {
                    self::assertArrayHasKey($field, $input, "{$dimension}[{$i}] missing {$field}");
                }
            }
        }
    }
}
