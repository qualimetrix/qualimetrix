<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * Pins that the three graph-backed membership criteria — {@code extends},
 * {@code implements} and {@code attributes} — actually filter candidates while
 * a template layer is being expanded, not just while a concrete layer is being
 * matched at runtime.
 *
 * Observation reads the class relationships through the same
 * {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContextFactory}
 * that runtime matching uses. That factory answers with empty attribute /
 * interface / parent lists until it is bound to the run's dependency graph, so
 * a factory bound after expansion makes every one of these criteria read as
 * "this class has no parents, no interfaces, no attributes": under
 * {@code match: all} the template expands to nothing, and a graph-backed
 * {@code exclude:} clause removes nothing.
 *
 * The fixture is deliberately two-module: {@code Order} satisfies all three
 * criteria and {@code Billing} satisfies none, so a criterion that filters and
 * a criterion that is inert produce different layer rosters rather than
 * different diagnostics.
 */
#[CoversClass(ArchitecturePolicy::class)]
#[Group('integration')]
final class TemplateCriteriaExpansionIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/TemplateCriteriaSample';

    private const string FIXTURE_NAMESPACE = 'Fixtures\\TemplateCriteriaSample';

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideGraphBackedCriteria(): iterable
    {
        yield 'extends' => ['extends', self::FIXTURE_NAMESPACE . '\\Shared\\AggregateRoot'];
        yield 'implements' => ['implements', self::FIXTURE_NAMESPACE . '\\Shared\\HasIdentity'];
        yield 'attributes' => ['attributes', self::FIXTURE_NAMESPACE . '\\Shared\\AsEntity'];
    }

    #[Test]
    #[DataProvider('provideGraphBackedCriteria')]
    public function itFiltersTemplateCandidatesByAGraphBackedCriterion(string $criterion, string $fqn): void
    {
        $config = self::baseConfig();
        $config['layers'][1]['match'] = 'all';
        $config['layers'][1][$criterion] = [$fqn];

        self::assertSame(
            ['domain-Order'],
            $this->expandedDomainLayers($config),
            \sprintf('Only the module whose class satisfies `%s` may produce a concrete layer.', $criterion),
        );
    }

    #[Test]
    #[DataProvider('provideGraphBackedCriteria')]
    public function itObservesNoTupleWhenAGraphBackedCriterionMatchesNothing(string $criterion, string $fqn): void
    {
        $config = self::baseConfig();
        $config['layers'][1]['match'] = 'all';
        // Same criterion kind, a target no fixture class carries. Without this
        // case a criterion that admits everything would pass the test above.
        $config['layers'][1][$criterion] = [$fqn . 'Absent'];

        self::assertSame(
            [],
            $this->expandedDomainLayers($config),
            \sprintf('An unsatisfiable `%s` criterion must leave the template unexpanded.', $criterion),
        );
    }

    #[Test]
    #[DataProvider('provideGraphBackedCriteria')]
    public function itAppliesAGraphBackedExcludeDuringObservation(string $criterion, string $fqn): void
    {
        $config = self::baseConfig();
        $config['layers'][1]['exclude'] = [$criterion => [$fqn]];

        self::assertSame(
            ['domain-Billing'],
            $this->expandedDomainLayers($config),
            \sprintf(
                'A class removed by the template\'s `exclude.%s` must not contribute a binding tuple.',
                $criterion,
            ),
        );
    }

    #[Test]
    public function itExpandsBothModulesWithoutANonPatternCriterion(): void
    {
        // The control for the three cases above: the same fixture, the same
        // template, no criterion — both modules bind. A roster of one in the
        // cases above therefore reports the criterion, not the fixture.
        self::assertSame(
            ['domain-Billing', 'domain-Order'],
            $this->expandedDomainLayers(self::baseConfig()),
        );
    }

    /**
     * @param array<string, mixed> $configArray
     *
     * @return list<string>
     */
    private function expandedDomainLayers(array $configArray): array
    {
        $result = (new ArchitectureConfigurationFactory())->fromArray($configArray);

        $container = (new ContainerFactory())->create();

        $holder = $container->get(ArchitecturePolicyConfiguratorInterface::class);
        self::assertInstanceOf(ArchitecturePolicy::class, $holder);
        $holder->bind($result->configuration);

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);

        $root = AbsolutePath::fromString(self::FIXTURE_PATH);
        $pipeline->analyze(new RunConfiguration(
            [$root],
            [],
            $root,
            GeneratedFilePolicy::Include,
            coversProjectScope: true,
            authoredPathExcludes: [],
        ));

        $prepared = $holder->getPreparedConfiguration();
        self::assertNotNull($prepared, 'The pipeline must have prepared the architecture policy.');

        $names = array_values(array_filter(
            array_map(
                static fn(LayerDefinition $definition): string => $definition->name(),
                $prepared->registry()->definitions(),
            ),
            static fn(string $name): bool => str_starts_with($name, 'domain-'),
        ));
        sort($names);

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseConfig(): array
    {
        return [
            'layers' => [
                [
                    'name' => 'shared',
                    'patterns' => [self::FIXTURE_NAMESPACE . '\\Shared\\**'],
                ],
                [
                    'name' => 'domain-{module}',
                    'patterns' => [self::FIXTURE_NAMESPACE . '\\Module\\{module}\\Domain\\**'],
                ],
            ],
            'allow' => [
                'domain-*' => ['shared'],
                'shared' => [],
            ],
            'coverage-gap' => 'ignore',
        ];
    }
}
