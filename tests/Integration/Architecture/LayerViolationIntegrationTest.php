<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Architecture\ArchitectureConfiguration;
use Qualimetrix\Core\Architecture\ArchitectureConfigurationHolder;
use Qualimetrix\Core\Architecture\CoverageMode;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\LayerPolicy;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Rules\Architecture\LayerViolationRule;

/**
 * End-to-end test: runs the real {@see AnalysisPipelineInterface} against a
 * synthetic four-layer fixture project, with the layer policy injected
 * directly into {@see ArchitectureConfigurationHolder}.
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
    private const string FIXTURE_PATH = __DIR__ . '/../../Fixtures/ArchitectureSample';

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
                'Fixtures\\ArchitectureSample\\Controller\\UserController',
                $violation->symbolPath->toString(),
            );
            self::assertStringContainsString(
                'Fixtures\\ArchitectureSample\\Repository\\UserRepository',
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
            new LayerDefinition('controller', ['Fixtures\\ArchitectureSample\\Controller']),
        ]);
        $policy = new LayerPolicy(['controller' => []]);
        $architecture = new ArchitectureConfiguration($registry, $policy, CoverageMode::Warn);

        $pipeline = $this->createPipelineWithArchitecture($architecture);
        $result = $pipeline->analyze(self::FIXTURE_PATH);

        $diagnostics = $this->filterByRule($result->violations, LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME);
        self::assertCount(1, $diagnostics, 'Exactly one coverage diagnostic expected in warn mode.');

        $diagnostic = $diagnostics[0];
        self::assertSame(Severity::Info, $diagnostic->severity);
        self::assertStringContainsString('Architecture coverage:', $diagnostic->message);
    }

    #[Test]
    public function ignoreCoverageModeSuppressesDiagnosticEvenWithUnmatchedEnds(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('controller', ['Fixtures\\ArchitectureSample\\Controller']),
        ]);
        $policy = new LayerPolicy(['controller' => []]);
        $architecture = new ArchitectureConfiguration($registry, $policy, CoverageMode::Ignore);

        $pipeline = $this->createPipelineWithArchitecture($architecture);
        $result = $pipeline->analyze(self::FIXTURE_PATH);

        $diagnostics = $this->filterByRule($result->violations, LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME);
        self::assertSame([], $diagnostics);
    }

    private function createPipelineWithArchitecture(?ArchitectureConfiguration $architecture): AnalysisPipelineInterface
    {
        $container = (new ContainerFactory())->create();

        $holder = $container->get(ArchitectureConfigurationHolder::class);
        self::assertInstanceOf(ArchitectureConfigurationHolder::class, $holder);

        if ($architecture !== null) {
            $holder->set($architecture);
        }

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);

        return $pipeline;
    }

    private function buildPolicy(CoverageMode $coverage): ArchitectureConfiguration
    {
        $registry = new LayerRegistry([
            new LayerDefinition('controller', ['Fixtures\\ArchitectureSample\\Controller']),
            new LayerDefinition('service', ['Fixtures\\ArchitectureSample\\Service']),
            new LayerDefinition('repository', ['Fixtures\\ArchitectureSample\\Repository']),
            new LayerDefinition('domain', ['Fixtures\\ArchitectureSample\\Domain']),
        ]);

        $policy = new LayerPolicy([
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
