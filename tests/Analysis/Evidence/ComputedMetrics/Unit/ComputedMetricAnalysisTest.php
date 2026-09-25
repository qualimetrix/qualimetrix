<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricsSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ExcludeHealthSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(ComputedMetricAnalysis::class)]
final class ComputedMetricAnalysisTest extends TestCase
{
    #[Test]
    public function itDefaultReturnsEmptyArray(): void
    {
        self::assertSame([], $this->analysis()->all());
    }

    #[Test]
    public function itSetAndGetDefinitions(): void
    {
        $analysis = $this->analysis();
        $resolved = $analysis->resolve($this->document([]));
        $analysis->replace($resolved);

        self::assertCount(6, $analysis->all());
        self::assertNotNull($analysis->find('health.overall'));
        self::assertSame($resolved->all(), $analysis->all());
    }

    #[Test]
    public function itPreservesInstalledDefinitionsWhenResolutionFails(): void
    {
        $analysis = $this->analysis();
        $analysis->replace($analysis->resolve($this->document([])));

        try {
            $analysis->resolve($this->document(['exclude_health' => ['unknown']]));
            self::fail('Expected invalid configuration.');
        } catch (ConfigurationRefusal) {
            self::assertNotNull($analysis->find('health.overall'));
        }
    }

    #[Test]
    public function itSetDefinitionsReplacePrevious(): void
    {
        $analysis = $this->analysis();
        $analysis->replace($analysis->resolve($this->document(['computed_metrics' => ['computed.first' => ['formula' => '1']]])));
        self::assertNotNull($analysis->find('computed.first'));

        $analysis->replace($analysis->resolve($this->document(['computed_metrics' => ['computed.second' => ['formula' => '2']]])));
        self::assertNull($analysis->find('computed.first'));
        self::assertNotNull($analysis->find('computed.second'));
    }

    private function analysis(): ComputedMetricAnalysis
    {
        return new ComputedMetricAnalysis(
            new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder()),
        );
    }

    /** @param array<string, mixed> $written one configuration file */
    private function document(array $written): ConfigurationDocument
    {
        return new ConfigurationDocument([], AbsolutePath::fromString('/project'), DocumentComposer::compose(
            new DocumentSchema([new ComputedMetricsSection(), new ExcludeHealthSection()]),
            [new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'), AuthoredNode::fromPlain($written))],
        ));
    }
}
