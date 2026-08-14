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
        $expectedKeys = ['ccn', 'cognitive', 'npath', 'lcom', 'tcc', 'lcc', 'wmc', 'cbo', 'instability', 'abstractness', 'distance', 'classRank', 'dit', 'noc', 'rfc', 'methodCount', 'propertyCount', 'classCount.sum', 'mi', 'typeCoverage.pct', 'typeCoverage.param', 'typeCoverage.return', 'typeCoverage.property'];
        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $hints, "Missing metric hint for: {$key}");
            self::assertArrayHasKey('label', $hints[$key]);
            self::assertArrayHasKey('ranges', $hints[$key]);
            self::assertArrayHasKey('formatTemplate', $hints[$key]);
        }
        self::assertArrayNotHasKey('loc', $hints);
        self::assertArrayNotHasKey('lloc', $hints);
        self::assertArrayNotHasKey('cloc', $hints);
    }

    #[Test]
    public function itExportForHtmlLabelsAreDescriptive(): void
    {
        $hints = $this->projector->project()['metricHints'];
        self::assertSame('Cyclomatic Complexity', $hints['ccn']['label']);
        self::assertSame('Cognitive Complexity', $hints['cognitive']['label']);
        self::assertSame('Tight Class Cohesion', $hints['tcc']['label']);
        self::assertSame('Maintainability Index', $hints['mi']['label']);
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
        self::assertSame('{value} disconnected group{plural}', $hints['lcom']['formatTemplate']);
        foreach ($hints as $key => $hint) {
            if ($key !== 'lcom') {
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
