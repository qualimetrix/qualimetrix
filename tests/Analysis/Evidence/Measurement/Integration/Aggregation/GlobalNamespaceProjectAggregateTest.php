<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Integration\Aggregation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * A project written entirely in the global namespace must be scored, not
 * silently skipped.
 *
 * The global namespace is the empty string, which the namespace tree used to
 * drop while building parent chains. Because the project aggregate of
 * namespace-collected metrics reads the tree's leaves, such a project published
 * no structural aggregate at all and therefore took no structural penalty — the
 * whole scored better than its only part.
 *
 * Fixture directory: tests/Analysis/Evidence/Measurement/Fixtures/GlobalNamespaceOnly/
 */
#[Group('integration')]
final class GlobalNamespaceProjectAggregateTest extends TestCase
{
    private static MetricRepositoryInterface $repository;

    public static function setUpBeforeClass(): void
    {
        $containerFactory = new ContainerFactory();
        $container = $containerFactory->create();
        $fixtureRoot = AbsolutePath::fromString(\dirname(__DIR__, 2) . '/Fixtures/GlobalNamespaceOnly');

        /** @var ArchitecturePolicyConfiguratorInterface $architecturePolicy */
        $architecturePolicy = $container->get(ArchitecturePolicyConfiguratorInterface::class);
        $document = new ConfigurationDocument([], $fixtureRoot);
        $architecturePolicy->replace($architecturePolicy->resolve($document));

        // The built-in health definitions are resolved the way a real run
        // resolves them; without this the pipeline evaluates no computed metric.
        /** @var ComputedMetricConfiguratorInterface $computedMetrics */
        $computedMetrics = $container->get(ComputedMetricConfiguratorInterface::class);
        $computedMetrics->replace($computedMetrics->resolve($document));

        /** @var AnalysisPipelineInterface $pipeline */
        $pipeline = $container->get(AnalysisPipelineInterface::class);

        $result = $pipeline->analyze(new RunConfiguration(
            [$fixtureRoot],
            [],
            AbsolutePath::fromString((string) getcwd()),
            GeneratedFilePolicy::Include,
            coversProjectScope: true,
            authoredPathExcludes: [],
        ));

        self::$repository = $result->metrics;
    }

    #[Test]
    public function itPublishesAStructuralProjectAggregateForGlobalNamespaceCode(): void
    {
        $globalDistance = self::$repository->get(SymbolPath::forNamespace(''))->get('coupling.distance');
        self::assertNotNull($globalDistance, 'The global namespace must carry a distance of its own');
        self::assertGreaterThan(0.0, (float) $globalDistance);

        $projectDistance = self::$repository->get(SymbolPath::forProject())->get('coupling.distance.avg');
        self::assertNotNull(
            $projectDistance,
            'A project whose code is entirely global must still publish a structural aggregate',
        );
        self::assertEqualsWithDelta((float) $globalDistance, (float) $projectDistance, 0.0001);
    }

    #[Test]
    public function itDoesNotScoreTheWholeAboveItsOnlyPart(): void
    {
        $globalHealth = self::$repository->get(SymbolPath::forNamespace(''))->get('health.coupling');
        $projectHealth = self::$repository->get(SymbolPath::forProject())->get('health.coupling');

        self::assertNotNull($globalHealth);
        self::assertNotNull($projectHealth);
        self::assertLessThanOrEqual((float) $globalHealth, (float) $projectHealth);
    }
}
