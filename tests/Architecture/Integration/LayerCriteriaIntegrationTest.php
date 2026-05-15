<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\ArchitectureConfigurationHolder;
use Qualimetrix\Architecture\Domain\CoverageMode;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\LayerRegistry;
use Qualimetrix\Architecture\Domain\Layer\MatchMode;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Rules\Architecture\LayerViolationRule;
use Qualimetrix\Tests\Architecture\Support\AllowListBuilder;

/**
 * End-to-end integration test for the Phase 2 direction-1 membership criteria
 * ({@code suffix}, {@code attributes}, {@code implements}, {@code extends}).
 *
 * Runs the live analysis pipeline against {@code tests/Architecture/Fixtures/CriteriaSample}
 * — a small project with four "marker" elements (an interface, an abstract
 * class, an attribute) and four client classes, each designed to be caught by
 * exactly one criterion kind.
 *
 * The test does not pin a golden file: the assertion surface is structural
 * (each fixture class lands in the expected layer), so cosmetic message
 * changes elsewhere don't churn this test. The Phase-1-shape patterns-only
 * BC is pinned separately by {@see Phase1ConfigCompatibilityTest}.
 */
#[Group('integration')]
final class LayerCriteriaIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/CriteriaSample';
    private const string FIXTURE_NAMESPACE = 'Fixtures\\CriteriaSample';

    #[Test]
    public function membershipCriteriaClassifyEachFixtureClassUnderTheRightLayer(): void
    {
        // Layers ordered so that the unique criterion-driving class for each
        // kind falls into its dedicated layer. The dependency graph is what
        // makes attribute / implements / extends data available — without
        // the rule's bindGraph() handshake, these layers would silently fail.
        $registry = new LayerRegistry([
            new LayerDefinition(
                'contracts-impls',
                new MembershipSpec(implements: [self::FIXTURE_NAMESPACE . '\\Marker\\RepositoryInterface']),
            ),
            new LayerDefinition(
                'aggregates',
                new MembershipSpec(extends: [self::FIXTURE_NAMESPACE . '\\Marker\\AggregateRoot']),
            ),
            new LayerDefinition(
                'tagged-services',
                new MembershipSpec(attributes: [self::FIXTURE_NAMESPACE . '\\Marker\\ServiceTag']),
            ),
            new LayerDefinition(
                'suffix-repos',
                new MembershipSpec(suffix: ['Repository']),
            ),
            new LayerDefinition(
                'markers',
                new MembershipSpec(patterns: [self::FIXTURE_NAMESPACE . '\\Marker\\**']),
            ),
        ]);

        // Self-allow only — every cross-layer edge becomes a violation, which
        // is what we use as evidence of correct classification.
        $policy = AllowListBuilder::policyFromExactMap([
            'contracts-impls' => ['markers'],
            'aggregates' => ['markers'],
            'tagged-services' => ['markers'],
            'suffix-repos' => ['markers'],
            'markers' => [],
        ]);

        $pipeline = $this->createPipelineWith(
            new ArchitectureConfiguration($registry, $policy, CoverageMode::Warn),
        );

        $result = $pipeline->analyze(self::FIXTURE_PATH);

        $layerOf = $this->buildPerSourceLayerMap($result->violations);

        // Each expected source class shows up at least once as the source
        // of a violation under the expected layer label.
        self::assertSame(
            'contracts-impls',
            $layerOf[self::FIXTURE_NAMESPACE . '\\ContractsImpl\\QueryBackend'] ?? null,
            'QueryBackend implements RepositoryInterface and must land in contracts-impls.',
        );
        self::assertSame(
            'aggregates',
            $layerOf[self::FIXTURE_NAMESPACE . '\\Aggregates\\Order'] ?? null,
            'Order extends AggregateRoot and must land in aggregates.',
        );
        self::assertSame(
            'aggregates',
            $layerOf[self::FIXTURE_NAMESPACE . '\\Aggregates\\Invoice'] ?? null,
            'Invoice extends Order extends AggregateRoot (transitive) and must land in aggregates.',
        );
        self::assertSame(
            'tagged-services',
            $layerOf[self::FIXTURE_NAMESPACE . '\\Tagged\\Notifier'] ?? null,
            'Notifier carries #[ServiceTag] and must land in tagged-services.',
        );
        self::assertSame(
            'suffix-repos',
            $layerOf[self::FIXTURE_NAMESPACE . '\\Suffixed\\OrderRepository'] ?? null,
            'OrderRepository ends in Repository (and implements nothing) — must land in suffix-repos.',
        );
    }

    #[Test]
    public function matchAllRequiresEveryDeclaredCriterion(): void
    {
        // strict-repository = ends in `Repository` AND implements
        // RepositoryInterface. Only QueryBackend implements but its short
        // name is not `Repository`; only OrderRepository has the suffix but
        // implements no interface. With `match: all`, NEITHER class is a
        // member.
        $registry = new LayerRegistry([
            new LayerDefinition(
                'strict-repository',
                new MembershipSpec(
                    suffix: ['Repository'],
                    implements: [self::FIXTURE_NAMESPACE . '\\Marker\\RepositoryInterface'],
                    mode: MatchMode::All,
                ),
            ),
        ]);

        $policy = AllowListBuilder::policyFromExactMap([
            'strict-repository' => [],
        ]);

        $pipeline = $this->createPipelineWith(
            new ArchitectureConfiguration($registry, $policy, CoverageMode::Ignore),
        );

        $result = $pipeline->analyze(self::FIXTURE_PATH);

        $layerSources = $this->collectSourceFqns(
            $this->filterByRule($result->violations, LayerViolationRule::NAME),
        );

        self::assertNotContains(
            self::FIXTURE_NAMESPACE . '\\ContractsImpl\\QueryBackend',
            $layerSources,
            'QueryBackend implements RepositoryInterface but has wrong suffix — must NOT be in strict-repository under match: all.',
        );
        self::assertNotContains(
            self::FIXTURE_NAMESPACE . '\\Suffixed\\OrderRepository',
            $layerSources,
            'OrderRepository has the suffix but does not implement — must NOT be in strict-repository under match: all.',
        );
    }

    #[Test]
    public function violationMessageNamesMatchedCriterionWhenNotPattern(): void
    {
        // Build a registry where every test class is caught by a different
        // non-pattern criterion (suffix, attribute, implements, extends). The
        // violation message for each source class must surface the matched
        // criterion descriptor.
        $registry = new LayerRegistry([
            new LayerDefinition(
                'contracts-impls',
                new MembershipSpec(implements: [self::FIXTURE_NAMESPACE . '\\Marker\\RepositoryInterface']),
            ),
            new LayerDefinition(
                'aggregates',
                new MembershipSpec(extends: [self::FIXTURE_NAMESPACE . '\\Marker\\AggregateRoot']),
            ),
            new LayerDefinition(
                'tagged-services',
                new MembershipSpec(attributes: [self::FIXTURE_NAMESPACE . '\\Marker\\ServiceTag']),
            ),
            new LayerDefinition(
                'suffix-repos',
                new MembershipSpec(suffix: ['Repository']),
            ),
            new LayerDefinition(
                'markers',
                new MembershipSpec(patterns: [self::FIXTURE_NAMESPACE . '\\Marker\\**']),
            ),
        ]);

        $policy = AllowListBuilder::policyFromExactMap([
            'contracts-impls' => [],
            'aggregates' => [],
            'tagged-services' => [],
            'suffix-repos' => [],
            'markers' => [],
        ]);

        $pipeline = $this->createPipelineWith(
            new ArchitectureConfiguration($registry, $policy, CoverageMode::Ignore),
        );

        $result = $pipeline->analyze(self::FIXTURE_PATH);
        $violations = $this->filterByRule($result->violations, LayerViolationRule::NAME);

        $expectedTrailers = [
            self::FIXTURE_NAMESPACE . '\\Suffixed\\OrderRepository' => 'source matched by suffix "Repository"',
            self::FIXTURE_NAMESPACE . '\\Tagged\\Notifier' => 'source matched by attribute "' . self::FIXTURE_NAMESPACE . '\\Marker\\ServiceTag"',
            self::FIXTURE_NAMESPACE . '\\ContractsImpl\\QueryBackend' => 'source matched by implements "' . self::FIXTURE_NAMESPACE . '\\Marker\\RepositoryInterface"',
            self::FIXTURE_NAMESPACE . '\\Aggregates\\Order' => 'source matched by extends "' . self::FIXTURE_NAMESPACE . '\\Marker\\AggregateRoot"',
        ];

        foreach ($expectedTrailers as $sourceFqn => $expectedTrailer) {
            $matching = array_values(array_filter(
                $violations,
                static fn(Violation $v): bool => $v->symbolPath->toString() === $sourceFqn,
            ));

            self::assertNotEmpty(
                $matching,
                $sourceFqn . ' should produce at least one violation to inspect.',
            );

            foreach ($matching as $violation) {
                self::assertStringContainsString(
                    $expectedTrailer,
                    $violation->message,
                    'Violation message for ' . $sourceFqn . ' must name the matched criterion.',
                );
            }
        }
    }

    private function createPipelineWith(ArchitectureConfiguration $architecture): AnalysisPipelineInterface
    {
        $container = (new ContainerFactory())->create();

        $holder = $container->get(ArchitectureConfigurationHolder::class);
        \assert($holder instanceof ArchitectureConfigurationHolder);
        $holder->set($architecture);

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        \assert($pipeline instanceof AnalysisPipelineInterface);

        return $pipeline;
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function filterByRule(array $violations, string $ruleName): array
    {
        return array_values(array_filter(
            $violations,
            static fn(Violation $v): bool => $v->ruleName === $ruleName,
        ));
    }

    /**
     * Per source class FQN, captures the layer name from the violation
     * message. The message format is pinned at
     * {@code 'Layer "$source" must not depend on layer "..."'}.
     *
     * @param list<Violation> $violations
     *
     * @return array<string, string>
     */
    private function buildPerSourceLayerMap(array $violations): array
    {
        $map = [];
        foreach ($this->filterByRule($violations, LayerViolationRule::NAME) as $violation) {
            if (preg_match('/^Layer "([^"]+)" must not depend/', $violation->message, $matches) !== 1) {
                continue;
            }
            $map[$violation->symbolPath->toString()] = $matches[1];
        }

        return $map;
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<string>
     */
    private function collectSourceFqns(array $violations): array
    {
        $seen = [];
        foreach ($violations as $violation) {
            $seen[$violation->symbolPath->toString()] = true;
        }

        return array_keys($seen);
    }
}
