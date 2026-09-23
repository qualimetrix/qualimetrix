<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigDataNormalizer;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricContributionReader;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * `name: ~` under `computed_metrics` is read the way `name: {}` is, from the
 * YAML file through to the owner: an entry written without a body still names
 * a metric, and a name the owner would refuse with `{}` is refused with `~`.
 */
#[CoversClass(ComputedMetricsConfigResolver::class)]
final class UnwrittenEntryBodyTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/qmx-computed-' . bin2hex(random_bytes(8)) . '.yaml';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /** @return iterable<string, array{string}> */
    public static function provideRefusedNames(): iterable
    {
        yield 'a name outside both prefixes' => ['my-metric'];
        yield 'a user metric with no formula' => ['computed.mine'];
        yield 'an unknown health dimension' => ['health.nope'];
    }

    #[Test]
    #[DataProvider('provideRefusedNames')]
    public function itRefusesANullBodyExactlyAsItRefusesAnEmptyOne(string $name): void
    {
        $empty = $this->refusal(\sprintf("computed_metrics:\n  %s: {}\n", $name));
        $null = $this->refusal(\sprintf("computed_metrics:\n  %s: ~\n", $name));

        self::assertNotNull($empty, 'The empty body must be refused for the comparison to mean anything');
        self::assertSame($empty, $null);
    }

    #[Test]
    public function itReadsANullBodyUnderAKnownHealthDimensionAsItsDefaults(): void
    {
        self::assertNull($this->refusal("computed_metrics:\n  health.complexity: ~\n"));
        self::assertNull($this->refusal("computed_metrics:\n  health.complexity: {}\n"));
    }

    private function refusal(string $yaml): ?string
    {
        file_put_contents($this->path, $yaml);
        $values = ConfigDataNormalizer::normalize((new YamlConfigLoader())->load($this->path));
        $document = new ConfigurationDocument([['source' => 'qmx.yaml', 'values' => $values]], AbsolutePath::fromString('/project'));

        try {
            (new ComputedMetricAnalysis(
                new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder()),
                new ComputedMetricContributionReader(),
            ))->resolve($document);
        } catch (ConfigurationRefusal $refusal) {
            return $refusal->summary();
        }

        return null;
    }
}
