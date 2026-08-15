<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricContributionReader;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\HealthFormulaExclusionInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Core\Path\AbsolutePath;

final class ComputedMetricContributionReaderTest extends TestCase
{
    #[Test]
    public function itReplacesTheWholeComputedMapAtTheLatestContributingStage(): void
    {
        $analysis = $this->analysis();
        $this->configure($analysis, $this->document([
            ['computedMetrics' => ['computed.first' => ['formula' => '1']]],
            ['computedMetrics' => ['computed.second' => ['formula' => '2']]],
        ]));

        self::assertNull($analysis->find('computed.first'));
        self::assertNotNull($analysis->find('computed.second'));
    }

    #[Test]
    public function itKeepsThePreviousComputedMapWhenALaterStageOmitsTheKey(): void
    {
        $analysis = $this->analysis();
        $this->configure($analysis, $this->document([
            ['computedMetrics' => ['computed.first' => ['formula' => '1']]],
            ['paths' => ['src']],
        ]));

        self::assertNotNull($analysis->find('computed.first'));
    }

    #[Test]
    public function itTreatsAnExplicitEmptyComputedMapAsReplacement(): void
    {
        $analysis = $this->analysis();
        $this->configure($analysis, $this->document([
            ['computedMetrics' => ['computed.first' => ['formula' => '1']]],
            ['computedMetrics' => []],
        ]));

        self::assertNull($analysis->find('computed.first'));
        self::assertCount(6, $analysis->all());
    }

    #[Test]
    public function itUnionsHealthExclusionsInStableSourceOrder(): void
    {
        $recorder = new RecordingHealthFormulaExcluder();
        $analysis = $this->analysis($recorder);
        $this->configure($analysis, $this->document([
            ['excludeHealth' => ['typing', 'complexity']],
            ['excludeHealth' => ['typing', 'cohesion']],
        ]));

        self::assertSame(['health.typing', 'health.complexity', 'health.cohesion'], $recorder->excluded);
    }

    #[Test]
    public function itTreatsAnEmptyHealthExclusionListAsNoAdditionalEntries(): void
    {
        $recorder = new RecordingHealthFormulaExcluder();
        $analysis = $this->analysis($recorder);
        $this->configure($analysis, $this->document([
            ['excludeHealth' => ['typing']],
            ['excludeHealth' => []],
        ]));

        self::assertSame(['health.typing'], $recorder->excluded);
    }

    #[Test]
    public function itPreservesPriorRunDefinitionsWhenALaterContributionIsInvalid(): void
    {
        $analysis = $this->analysis();
        $this->configure($analysis, $this->document([]));

        try {
            $analysis->resolve($this->document([['excludeHealth' => ['unknown']]]));
            self::fail('Expected invalid configuration.');
        } catch (InvalidArgumentException) {
            self::assertNotNull($analysis->find('health.overall'));
        }
    }

    private function analysis(?HealthFormulaExclusionInterface $excluder = null): ComputedMetricAnalysis
    {
        return new ComputedMetricAnalysis(
            new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), $excluder ?? new HealthFormulaExcluder()),
            new ComputedMetricContributionReader(),
        );
    }

    private function configure(ComputedMetricAnalysis $analysis, ConfigurationDocument $document): void
    {
        $analysis->replace($analysis->resolve($document));
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

final class RecordingHealthFormulaExcluder implements HealthFormulaExclusionInterface
{
    /** @var list<string> */
    public array $excluded = [];

    public function applyExcludeHealth(array $definitions, array $excludedDimensions): array
    {
        $this->excluded = $excludedDimensions;

        return $definitions;
    }
}
