<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Infrastructure\Console\Support\PreparedAnalysis;

/**
 * End-to-end integration test for the Phase 2 direction-1 membership criteria
 * ({@code suffix}, {@code attributes}, {@code implements}, {@code extends}).
 *
 * Runs the live analysis pipeline against {@code tests/Analysis/Policy/Architecture/Fixtures/CriteriaSample}
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
    public function itClassifiesEachFixtureClassUnderTheRightLayer(): void
    {
        // Layers ordered so that the unique criterion-driving class for each
        // kind falls into its dedicated layer. Attribute, implements and
        // extends data comes from the dependency graph, so these layers are
        // answerable only once the registry has been bound to one.
        $result = $this->analyze([
            'layers' => [
                ['name' => 'contracts-impls', 'implements' => [self::FIXTURE_NAMESPACE . '\\Marker\\RepositoryInterface']],
                ['name' => 'aggregates', 'extends' => [self::FIXTURE_NAMESPACE . '\\Marker\\AggregateRoot']],
                ['name' => 'tagged-services', 'attributes' => [self::FIXTURE_NAMESPACE . '\\Marker\\ServiceTag']],
                ['name' => 'suffix-repos', 'suffix' => ['Repository']],
                ['name' => 'markers', 'patterns' => [self::FIXTURE_NAMESPACE . '\\Marker\\**']],
            ],
            'allow' => [
                'contracts-impls' => ['markers'],
                'aggregates' => ['markers'],
                'tagged-services' => ['markers'],
                'suffix-repos' => ['markers'],
                'markers' => [],
            ],
            'coverage-gap' => 'warn',
        ]);

        $layerOf = $this->buildPerSourceLayerMap($result->findings);

        // Each expected source class shows up at least once as the source
        // of a finding under the expected layer label.
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
    public function itRequiresEveryDeclaredCriterionUnderMatchAll(): void
    {
        // strict-repository = ends in `Repository` AND implements
        // RepositoryInterface. Only QueryBackend implements but its short
        // name is not `Repository`; only OrderRepository has the suffix but
        // implements no interface. With `match: all`, NEITHER class is a
        // member.
        $result = $this->analyze([
            'layers' => [
                ['name' => 'strict-repository', 'suffix' => ['Repository'], 'implements' => [self::FIXTURE_NAMESPACE . '\\Marker\\RepositoryInterface'], 'match' => 'all'],
            ],
            'allow' => ['strict-repository' => []],
            'coverage-gap' => 'ignore',
        ]);

        $layerSources = $this->collectSourceFqns(
            $this->filterByRule($result->findings, LayerViolationRule::NAME),
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
    public function itAcceptsAClassSatisfyingEveryDeclaredCriterionUnderMatchAll(): void
    {
        // strict-repository = ends in `Repository` AND implements
        // RepositoryInterface. CustomerRepository satisfies BOTH criteria, so
        // it must be classified as a member of the strict-repository layer
        // under `match: all` — proving the positive code path of the match:all
        // gate (the negative-path test above only proves the gate excludes
        // partial matches; this one proves the gate admits full matches).
        //
        // We declare a complementary `tagged` layer so a forbidden edge
        // actually fires: layer-violations only emerge between two
        // classified layers. Without `tagged`, the Notifier dependency on
        // CustomerRepository would be out-of-layer and produce no signal.
        $result = $this->analyze([
            'layers' => [
                ['name' => 'strict-repository', 'suffix' => ['Repository'], 'implements' => [self::FIXTURE_NAMESPACE . '\\Marker\\RepositoryInterface'], 'match' => 'all'],
                ['name' => 'tagged', 'attributes' => [self::FIXTURE_NAMESPACE . '\\Marker\\ServiceTag']],
            ],
            'allow' => ['strict-repository' => [], 'tagged' => []],
            'coverage-gap' => 'ignore',
        ]);

        $layerSources = $this->collectSourceFqns(
            $this->filterByRule($result->findings, LayerViolationRule::NAME),
        );

        self::assertContains(
            self::FIXTURE_NAMESPACE . '\\StrictRepo\\CustomerRepository',
            $layerSources,
            'CustomerRepository satisfies BOTH suffix and implements — must be in strict-repository under match: all.',
        );

        // The match:any negative cases must still be excluded under match:all.
        self::assertNotContains(
            self::FIXTURE_NAMESPACE . '\\ContractsImpl\\QueryBackend',
            $layerSources,
            'QueryBackend implements RepositoryInterface but has wrong suffix — still excluded.',
        );
        self::assertNotContains(
            self::FIXTURE_NAMESPACE . '\\Suffixed\\OrderRepository',
            $layerSources,
            'OrderRepository has the suffix but does not implement — still excluded.',
        );
    }

    #[Test]
    public function itNamesTheMatchedCriterionInTheFindingMessageWhenItIsNotAPattern(): void
    {
        // Build a registry where every test class is caught by a different
        // non-pattern criterion (suffix, attribute, implements, extends). The
        // finding message for each source class must surface the matched
        // criterion descriptor.
        $result = $this->analyze([
            'layers' => [
                ['name' => 'contracts-impls', 'implements' => [self::FIXTURE_NAMESPACE . '\\Marker\\RepositoryInterface']],
                ['name' => 'aggregates', 'extends' => [self::FIXTURE_NAMESPACE . '\\Marker\\AggregateRoot']],
                ['name' => 'tagged-services', 'attributes' => [self::FIXTURE_NAMESPACE . '\\Marker\\ServiceTag']],
                ['name' => 'suffix-repos', 'suffix' => ['Repository']],
                ['name' => 'markers', 'patterns' => [self::FIXTURE_NAMESPACE . '\\Marker\\**']],
            ],
            'allow' => [
                'contracts-impls' => [],
                'aggregates' => [],
                'tagged-services' => [],
                'suffix-repos' => [],
                'markers' => [],
            ],
            'coverage-gap' => 'ignore',
        ]);
        $findings = $this->filterByRule($result->findings, LayerViolationRule::NAME);

        $expectedTrailers = [
            self::FIXTURE_NAMESPACE . '\\Suffixed\\OrderRepository' => 'source matched by suffix "Repository"',
            self::FIXTURE_NAMESPACE . '\\Tagged\\Notifier' => 'source matched by attribute "' . self::FIXTURE_NAMESPACE . '\\Marker\\ServiceTag"',
            self::FIXTURE_NAMESPACE . '\\ContractsImpl\\QueryBackend' => 'source matched by implements "' . self::FIXTURE_NAMESPACE . '\\Marker\\RepositoryInterface"',
            self::FIXTURE_NAMESPACE . '\\Aggregates\\Order' => 'source matched by extends "' . self::FIXTURE_NAMESPACE . '\\Marker\\AggregateRoot"',
        ];

        foreach ($expectedTrailers as $sourceFqn => $expectedTrailer) {
            $matching = array_values(array_filter(
                $findings,
                static fn(Finding $v): bool => $v->symbolPath->toString() === $sourceFqn,
            ));

            self::assertNotEmpty(
                $matching,
                $sourceFqn . ' should produce at least one violation to inspect.',
            );

            foreach ($matching as $finding) {
                self::assertStringContainsString(
                    $expectedTrailer,
                    $finding->message,
                    'Violation message for ' . $sourceFqn . ' must name the matched criterion.',
                );
            }
        }
    }

    /** @param array<string, mixed> $architecture */
    private function analyze(array $architecture): \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult
    {
        $root = AbsolutePath::fromString(self::FIXTURE_PATH);
        $fixture = PreparedAnalysis::start($root, [$root], ['architecture' => $architecture, 'include_generated' => true]);
        try {
            $pipeline = $fixture->container()->get(AnalysisPipelineInterface::class);
            \assert($pipeline instanceof AnalysisPipelineInterface);
            return $pipeline->analyze($fixture->prepared()->runConfiguration);
        } finally {
            $fixture->close();
        }
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function filterByRule(array $findings, string $ruleName): array
    {
        return array_values(array_filter(
            $findings,
            static fn(Finding $v): bool => $v->ruleName === $ruleName,
        ));
    }

    /**
     * Per source class FQN, captures the layer name from the finding
     * message. The message format is pinned at
     * {@code 'Layer "$source" must not depend on layer "..."'}.
     *
     * @param list<Finding> $findings
     *
     * @return array<string, string>
     */
    private function buildPerSourceLayerMap(array $findings): array
    {
        $map = [];
        foreach ($this->filterByRule($findings, LayerViolationRule::NAME) as $finding) {
            if (preg_match('/^Layer "([^"]+)" must not depend/', $finding->message, $matches) !== 1) {
                continue;
            }
            $map[$finding->symbolPath->toString()] = $matches[1];
        }

        return $map;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private function collectSourceFqns(array $findings): array
    {
        $seen = [];
        foreach ($findings as $finding) {
            $seen[$finding->symbolPath->toString()] = true;
        }

        return array_keys($seen);
    }
}
