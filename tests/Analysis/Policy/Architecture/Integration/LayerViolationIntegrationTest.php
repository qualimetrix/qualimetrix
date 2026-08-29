<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\AllowListBuilder;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\ArchitectureViolationProjector;

/**
 * End-to-end test: runs the real {@see AnalysisPipelineInterface} against a
 * synthetic four-layer fixture project, with the layer policy bound on the
 * shared {@see ArchitecturePolicy} before the pipeline runs.
 *
 * The fixture is laid out so that:
 *   - Controller -> Service (allowed)
 *   - Service -> Repository (allowed)
 *   - Repository -> Domain (allowed)
 *   - Controller -> Repository (FORBIDDEN — produces findings)
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

        $root = AbsolutePath::fromString(self::FIXTURE_PATH);
        $result = $pipeline->analyze(new \Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration([$root], [], $root, \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Include));

        $layerViolations = $this->filterByRule($result->findings, LayerViolationRule::NAME);
        $coverageDiagnostics = $this->filterByRule($result->findings, LayerDeclarationValidator::COVERAGE_DIAGNOSTIC_NAME);

        self::assertSame([], $layerViolations, 'No layers declared → rule must short-circuit.');
        self::assertSame([], $coverageDiagnostics, 'Empty config → no coverage diagnostic.');
    }

    #[Test]
    public function fullPolicyDetectsControllerToRepositoryFinding(): void
    {
        $pipeline = $this->createPipelineWithArchitecture($this->buildPolicy(CoverageMode::Ignore));

        $root = AbsolutePath::fromString(self::FIXTURE_PATH);
        $result = $pipeline->analyze(new \Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration([$root], [], $root, \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Include));

        $layerViolations = $this->filterByRule($result->findings, LayerViolationRule::NAME);
        self::assertNotEmpty(
            $layerViolations,
            'Controller depends on Repository — at least one layer-violation expected.',
        );

        // Every reported finding must be from the controller layer
        foreach ($layerViolations as $finding) {
            self::assertStringContainsString(
                'Layer "controller" must not depend on layer "repository"',
                $finding->message,
                'Unexpected violation message: ' . $finding->message,
            );
            self::assertSame(Severity::Warning, $finding->severity);
            self::assertNotNull($finding->dependencyTarget);
            self::assertNotNull($finding->dependencyType);
            self::assertStringContainsString(
                'Fixtures\\Sample\\Controller\\UserController',
                $finding->symbolPath->toString(),
            );
            self::assertStringContainsString(
                'Fixtures\\Sample\\Repository\\UserRepository',
                $finding->dependencyTarget->toString(),
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
        $root = AbsolutePath::fromString(self::FIXTURE_PATH);
        $result = $pipeline->analyze(new \Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration([$root], [], $root, \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Include));

        $diagnostics = $this->filterByRule($result->findings, LayerDeclarationValidator::COVERAGE_DIAGNOSTIC_NAME);
        self::assertCount(1, $diagnostics, 'Exactly one coverage diagnostic expected in warn mode.');

        $diagnostic = $diagnostics[0];
        self::assertSame(Severity::Warning, $diagnostic->severity);
        self::assertStringContainsString('Architecture coverage:', $diagnostic->message);
    }

    /**
     * Golden file regression: normalise the architecture-rule finding set
     * down to `{rule, severity, source, target, type}` tuples (no line numbers,
     * no full messages) and compare against a stored JSON snapshot. After an
     * intentional algorithm change, regenerate the snapshot by re-running with
     * `QMX_GOLDEN_UPDATE=1` in the environment — the test writes the file back.
     *
     * Storing the projection (not the raw finding objects) keeps the file
     * stable across cosmetic message tweaks while still pinning the finding
     * set itself.
     */
    #[Test]
    public function goldenFileMatchesFullPolicyOutput(): void
    {
        $pipeline = $this->createPipelineWithArchitecture($this->buildPolicy(CoverageMode::Ignore));
        $root = AbsolutePath::fromString(self::FIXTURE_PATH);
        $result = $pipeline->analyze(new \Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration([$root], [], $root, \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Include));

        $actual = ArchitectureViolationProjector::project($result->findings);
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
        $root = AbsolutePath::fromString(self::FIXTURE_PATH);
        $result = $pipeline->analyze(new \Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration([$root], [], $root, \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Include));

        $diagnostics = $this->filterByRule($result->findings, LayerDeclarationValidator::COVERAGE_DIAGNOSTIC_NAME);
        self::assertSame([], $diagnostics);
    }

    private function createPipelineWithArchitecture(?ArchitectureConfiguration $architecture): AnalysisPipelineInterface
    {
        $container = (new ContainerFactory())->create();

        $holder = $container->get(ArchitecturePolicyConfiguratorInterface::class);
        self::assertInstanceOf(ArchitecturePolicy::class, $holder);

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
     * @param list<Finding> $findings
     *
     * @return list<array{0: string, 1: string}>
     */
    private function extractSourceTargetTuples(array $findings): array
    {
        $tuples = [];
        foreach ($findings as $finding) {
            $target = $finding->dependencyTarget?->toString() ?? '';
            $tuples[] = [$finding->symbolPath->toString(), $target];
        }

        return $tuples;
    }
}
