<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Symfony\Component\Yaml\Yaml;

/**
 * A metric written `~` or `{}` in YAML writes nothing under its name: the
 * metric below it — a built-in dimension or a preset's metric — stands
 * unchanged, and a name nothing below defines is a metric without a formula.
 */
#[CoversClass(ComputedMetricsConfigResolver::class)]
final class UnwrittenEntryBodyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function provideEmptyBodies(): iterable
    {
        yield 'null' => ['~'];
        yield 'empty map' => ['{}'];
    }

    #[Test]
    #[DataProvider('provideEmptyBodies')]
    public function itLeavesABuiltInDimensionAtItsDefaults(string $body): void
    {
        $analysis = $this->analysis(\sprintf("computed_metrics:\n  health.complexity: %s\n", $body));

        self::assertSame(50.0, $analysis->find('health.complexity')?->warningThreshold);
    }

    #[Test]
    #[DataProvider('provideEmptyBodies')]
    public function itLeavesAPresetMetricStanding(string $body): void
    {
        $analysis = $this->analysis(
            \sprintf("computed_metrics:\n  computed.mine: %s\n", $body),
            "computed_metrics:\n  computed.mine:\n    formula: '4'\n    warning: 3\n",
        );

        self::assertSame(3.0, $analysis->find('computed.mine')?->warningThreshold);
    }

    #[Test]
    #[DataProvider('provideEmptyBodies')]
    public function itRefusesANewMetricNamedWithAnEmptyBodyForItsMissingFormulaNamingTheFile(string $body): void
    {
        try {
            $this->analysis(\sprintf("computed_metrics:\n  computed.mine: %s\n", $body));
            self::fail('Expected a refusal.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('Computed metric "computed.mine" has no formula for level "namespace"', $refusal->summary());
            self::assertSame('qmx.yaml', $refusal->origin()->locator());
            self::assertSame(['computed_metrics', 'computed.mine', 'formulas', 'namespace'], $refusal->position()?->segments);
        }
    }

    #[Test]
    #[DataProvider('provideEmptyBodies')]
    public function itRefusesAnInvalidNameWrittenWithAnEmptyBody(string $body): void
    {
        try {
            $this->analysis(\sprintf("computed_metrics:\n  health.typng: %s\n  my-metric: %s\n", $body, $body));
            self::fail('Expected a refusal.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('"health.typng" is not a known "health.*" dimension', $refusal->summary());
            self::assertSame(['computed_metrics', 'health.typng'], $refusal->position()?->segments);
        }
    }

    private function analysis(string $fileYaml, ?string $presetYaml = null): ComputedMetricAnalysis
    {
        $layers = [];
        if ($presetYaml !== null) {
            $layers[] = new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::Preset, 'p'), AuthoredNode::fromPlain(Yaml::parse($presetYaml)));
        }
        $layers[] = new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'), AuthoredNode::fromPlain(Yaml::parse($fileYaml)));

        $analysis = new ComputedMetricAnalysis(
            new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder()),
        );
        $analysis->replace($analysis->resolve(new ConfigurationDocument(
            [],
            AbsolutePath::fromString('/project'),
            DocumentComposer::compose(new DocumentSchema([new ComputedMetricsSection(), new ExcludeHealthSection()]), $layers),
        )));

        return $analysis;
    }
}
