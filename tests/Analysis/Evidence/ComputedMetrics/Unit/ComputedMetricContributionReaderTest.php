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

final class ComputedMetricContributionReaderTest extends TestCase
{
    #[Test]
    public function itReplacesTheWholeComputedMapAtTheLatestContributingStage(): void
    {
        $analysis = $this->analysis();
        $analysis->configure(new ConfigurationDocument([
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
        $analysis->configure(new ConfigurationDocument([
            ['computedMetrics' => ['computed.first' => ['formula' => '1']]],
            ['paths' => ['src']],
        ]));

        self::assertNotNull($analysis->find('computed.first'));
    }

    #[Test]
    public function itTreatsAnExplicitEmptyComputedMapAsReplacement(): void
    {
        $analysis = $this->analysis();
        $analysis->configure(new ConfigurationDocument([
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
        $analysis->configure(new ConfigurationDocument([
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
        $analysis->configure(new ConfigurationDocument([
            ['excludeHealth' => ['typing']],
            ['excludeHealth' => []],
        ]));

        self::assertSame(['health.typing'], $recorder->excluded);
    }

    #[Test]
    public function itClearsPriorRunDefinitionsWhenALaterContributionIsInvalid(): void
    {
        $analysis = $this->analysis();
        $analysis->configure(new ConfigurationDocument([]));

        try {
            $analysis->configure(new ConfigurationDocument([['excludeHealth' => ['unknown']]]));
            self::fail('Expected invalid configuration.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $analysis->all());
        }
    }

    private function analysis(?HealthFormulaExclusionInterface $excluder = null): ComputedMetricAnalysis
    {
        return new ComputedMetricAnalysis(
            new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), $excluder ?? new HealthFormulaExcluder()),
            new ComputedMetricContributionReader(),
        );
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
