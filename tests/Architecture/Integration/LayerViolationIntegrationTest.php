<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\CoverageMode;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\LayerRegistry;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Architecture\Processing\ArchitectureProcessorInterface;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Architecture\Support\AllowListBuilder;
use Qualimetrix\Tests\Architecture\Support\ArchitectureViolationProjector;

/**
 * End-to-end test: runs the real {@see AnalysisPipelineInterface} against a
 * synthetic four-layer fixture project, with the layer policy bound on the
 * shared {@see ArchitectureProcessorInterface} before the pipeline runs.
 *
 * The fixture is laid out so that:
 *   - Controller -> Service (allowed)
 *   - Service -> Repository (allowed)
 *   - Repository -> Domain (allowed)
 *   - Controller -> Repository (FORBIDDEN — produces violations)
 *   - Controller -> Domain (forbidden by allow-list, but expected once via type hint)
 */
#[Group('integration')]
final class LayerViolationIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/Sample';

    #[Test]
    public function emptyArchitecturePolicyProducesZeroLayerViolations(): void
    {
        $pipeline = $this->createPipelineWithArchitecture(null);

        $result = $pipeline->analyze(self::FIXTURE_PATH);

        $layerViolations = $this->filterByRule($result->violations, LayerViolationRule::NAME);
        $coverageDiagnostics = $this->filterByRule($result->violations, LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME);

        self::assertSame([], $layerViolations, 'No layers declared → rule must short-circuit.');
        self::assertSame([], $coverageDiagnostics, 'Empty config → no coverage diagnostic.');
    }

    #[Test]
    public function fullPolicyDetectsControllerToRepositoryViolation(): void
    {
        $pipeline = $this->createPipelineWithArchitecture($this->buildPolicy(CoverageMode::Ignore));

        $result = $pipeline->analyze(self::FIXTURE_PATH);

        $layerViolations = $this->filterByRule($result->violations, LayerViolationRule::NAME);
        self::assertNotEmpty(
            $layerViolations,
            'Controller depends on Repository — at least one layer-violation expected.',
        );

        // Every reported violation must be from the controller layer
        foreach ($layerViolations as $violation) {
            self::assertStringContainsString(
                'Layer "controller" must not depend on layer "repository"',
                $violation->message,
                'Unexpected violation message: ' . $violation->message,
            );
            self::assertSame(Severity::Warning, $violation->severity);
            self::assertNotNull($violation->dependencyTarget);
            self::assertNotNull($violation->dependencyType);
            self::assertStringContainsString(
                'Fixtures\\Sample\\Controller\\UserController',
                $violation->symbolPath->toString(),
            );
            self::assertStringContainsString(
                'Fixtures\\Sample\\Repository\\UserRepository',
                $violation->dependencyTarget->toString(),
            );
        }

        // All known-allowed source→target pairs must NOT appear among reported edges.
        $forbiddenSourceTargetTuples = $this->extractSourceTargetTuples($layerViolations);
        foreach ($forbiddenSourceTargetTuples as $tuple) {
            self::assertStringContainsString('UserController', $tuple[0]);
            self::assertStringContainsString('UserRepository', $tuple[1]);
        }
    }

    #[Test]
    public function controllerOnlyPolicyTriggersCoverageDiagnosticInWarnMode(): void
    {
        // Only declare 'controller'; service/repository/domain become out-of-layer
        $registry = new LayerRegistry([
            new LayerDefinition('controller', new MembershipSpec(['Fixtures\\Sample\\Controller'])),
        ]);
        $policy = AllowListBuilder::policyFromExactMap(['controller' => []]);
        $architecture = new ArchitectureConfiguration($registry, $policy, CoverageMode::Warn);

        $pipeline = $this->createPipelineWithArchitecture($architecture);
        $result = $pipeline->analyze(self::FIXTURE_PATH);

        $diagnostics = $this->filterByRule($result->violations, LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME);
        self::assertCount(1, $diagnostics, 'Exactly one coverage diagnostic expected in warn mode.');

        $diagnostic = $diagnostics[0];
        self::assertSame(Severity::Info, $diagnostic->severity);
        self::assertStringContainsString('Architecture coverage:', $diagnostic->message);
    }

    /**
     * Golden file regression: normalise the architecture-rule violation set
     * down to `{rule, severity, source, target, type}` tuples (no line numbers,
     * no full messages) and compare against a stored JSON snapshot. After an
     * intentional algorithm change, regenerate the snapshot by re-running with
     * `QMX_GOLDEN_UPDATE=1` in the environment — the test writes the file back.
     *
     * Storing the projection (not the raw violation objects) keeps the file
     * stable across cosmetic message tweaks while still pinning the violation
     * set itself.
     */
    #[Test]
    public function goldenFileMatchesFullPolicyOutput(): void
    {
        $pipeline = $this->createPipelineWithArchitecture($this->buildPolicy(CoverageMode::Ignore));
        $result = $pipeline->analyze(self::FIXTURE_PATH);

        $actual = ArchitectureViolationProjector::project($result->violations);
        $goldenPath = self::FIXTURE_PATH . '/expected-violations.json';

        if (getenv('QMX_GOLDEN_UPDATE') === '1') {
            $payload = [
                '_comment' => 'Golden fixture for LayerViolationIntegrationTest::goldenFileMatchesFullPolicyOutput. Normalised projection of architecture violations emitted by the full four-layer policy against the ArchitectureSample fixture. Stored fields (per entry): rule, severity, source, target, type. Sorted by (rule, source, target, type) for stable diffs. Regenerate by setting QMX_GOLDEN_UPDATE=1.',
                'violations' => $actual,
            ];
            file_put_contents($goldenPath, json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
            self::markTestSkipped('Golden file regenerated. Re-run without QMX_GOLDEN_UPDATE to verify.');
        }

        $contents = file_get_contents($goldenPath);
        self::assertNotFalse($contents, 'Golden file must exist: ' . $goldenPath);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('violations', $decoded);
        self::assertIsArray($decoded['violations']);

        self::assertSame(
            $decoded['violations'],
            $actual,
            'Architecture violation set drifted from the golden file. Set QMX_GOLDEN_UPDATE=1 to regenerate after an intentional algorithm change.',
        );
    }

    #[Test]
    public function ignoreCoverageModeSuppressesDiagnosticEvenWithUnmatchedEnds(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('controller', new MembershipSpec(['Fixtures\\Sample\\Controller'])),
        ]);
        $policy = AllowListBuilder::policyFromExactMap(['controller' => []]);
        $architecture = new ArchitectureConfiguration($registry, $policy, CoverageMode::Ignore);

        $pipeline = $this->createPipelineWithArchitecture($architecture);
        $result = $pipeline->analyze(self::FIXTURE_PATH);

        $diagnostics = $this->filterByRule($result->violations, LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME);
        self::assertSame([], $diagnostics);
    }

    private function createPipelineWithArchitecture(?ArchitectureConfiguration $architecture): AnalysisPipelineInterface
    {
        $container = (new ContainerFactory())->create();

        $holder = $container->get(ArchitectureProcessorInterface::class);
        self::assertInstanceOf(ArchitectureProcessorInterface::class, $holder);

        // ADR 0008 §3: bind() is mandatory before prepare(). Empty
        // configuration mirrors the production flow when the user does
        // not declare an `architecture:` YAML section.
        $holder->bind($architecture ?? ArchitectureConfiguration::empty());

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);

        return $pipeline;
    }

    private function buildPolicy(CoverageMode $coverage): ArchitectureConfiguration
    {
        $registry = new LayerRegistry([
            new LayerDefinition('controller', new MembershipSpec(['Fixtures\\Sample\\Controller'])),
            new LayerDefinition('service', new MembershipSpec(['Fixtures\\Sample\\Service'])),
            new LayerDefinition('repository', new MembershipSpec(['Fixtures\\Sample\\Repository'])),
            new LayerDefinition('domain', new MembershipSpec(['Fixtures\\Sample\\Domain'])),
        ]);

        $policy = AllowListBuilder::policyFromExactMap([
            // Controllers may use domain DTOs for I/O typing, but not the data access layer.
            'controller' => ['service', 'domain'],
            'service' => ['repository', 'domain'],
            'repository' => ['domain'],
            'domain' => [],
        ]);

        return new ArchitectureConfiguration($registry, $policy, $coverage);
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
     * @param list<Violation> $violations
     *
     * @return list<array{0: string, 1: string}>
     */
    private function extractSourceTargetTuples(array $violations): array
    {
        $tuples = [];
        foreach ($violations as $violation) {
            $target = $violation->dependencyTarget?->toString() ?? '';
            $tuples[] = [$violation->symbolPath->toString(), $target];
        }

        return $tuples;
    }
}
