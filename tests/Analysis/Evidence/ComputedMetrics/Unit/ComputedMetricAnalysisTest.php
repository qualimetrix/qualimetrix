<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricContributionReader;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Core\Path\AbsolutePath;
use ReflectionClass;

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
        self::assertSame(
            $resolved,
            (new ReflectionClass($analysis))->getProperty('definitions')->getValue($analysis),
        );
    }

    #[Test]
    public function itPreservesInstalledDefinitionsWhenResolutionFails(): void
    {
        $analysis = $this->analysis();
        $analysis->replace($analysis->resolve($this->document([])));

        try {
            $analysis->resolve($this->document([['excludeHealth' => ['unknown']]]));
            self::fail('Expected invalid configuration.');
        } catch (InvalidArgumentException) {
            self::assertNotNull($analysis->find('health.overall'));
        }
    }

    #[Test]
    public function itSetDefinitionsReplacePrevious(): void
    {
        $analysis = $this->analysis();
        $analysis->replace($analysis->resolve($this->document([["computedMetrics" => ['computed.first' => ['formula' => '1']]]])));
        self::assertNotNull($analysis->find('computed.first'));

        $analysis->replace($analysis->resolve($this->document([["computedMetrics" => ['computed.second' => ['formula' => '2']]]])));
        self::assertNull($analysis->find('computed.first'));
        self::assertNotNull($analysis->find('computed.second'));
    }

    private function analysis(): ComputedMetricAnalysis
    {
        return new ComputedMetricAnalysis(
            new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder()),
            new ComputedMetricContributionReader(),
        );
    }

    /** @param list<array<string, mixed>> $contributions */
    private function document(array $contributions): ConfigurationDocument
    {
        return new ConfigurationDocument(array_map(
            static fn(array $values): array => ['source' => 'test', 'values' => $values],
            $contributions,
        ), AbsolutePath::fromString('/project'));
    }
}
