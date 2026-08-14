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
        $analysis->configure(new ConfigurationDocument([]));

        self::assertCount(6, $analysis->all());
        self::assertNotNull($analysis->find('health.overall'));
    }

    #[Test]
    public function itReset(): void
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

    #[Test]
    public function itSetDefinitionsReplacePrevious(): void
    {
        $analysis = $this->analysis();
        $analysis->configure(new ConfigurationDocument([["computedMetrics" => ['computed.first' => ['formula' => '1']]]]));
        self::assertNotNull($analysis->find('computed.first'));

        $analysis->configure(new ConfigurationDocument([["computedMetrics" => ['computed.second' => ['formula' => '2']]]]));
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
}
