<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\AllowListBuilder;

/**
 * Executable proof for the P0 fail-closed modular-topology contract.
 *
 * Taxonomy directories are deliberately absent from every registry below.
 * Only leaf owners and their exact Contract/Internal surfaces are layers.
 */
#[Group('integration')]
final class FailClosedModularTopologyIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/ModularTopologySample';

    private const string FIXTURE_NAMESPACE = 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Fixtures\\ModularTopologySample';

    #[Test]
    public function itKeepsAContractAndItsInternalSiblingInOneCoarseOwnerLayer(): void
    {
        $architecture = $this->architecture(
            [
                'orders-internal' => self::FIXTURE_NAMESPACE . '\\Boundary\\Orders\\Internal\\**',
                'billing-owner' => self::FIXTURE_NAMESPACE . '\\Boundary\\Billing\\**',
                'inventory-owner' => self::FIXTURE_NAMESPACE . '\\Boundary\\Inventory\\**',
            ],
            [
                'orders-internal' => ['billing-owner'],
                'billing-owner' => [],
                'inventory-owner' => [],
            ],
        );

        $result = $this->analyze(self::FIXTURE_PATH . '/Boundary', $architecture);
        self::assertSame([], $this->violationsFor($result->violations, LayerViolationRule::NAME));
        self::assertSame([], $this->violationsFor($result->violations, LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME));
    }

    #[Test]
    public function itLeavesAClassDirectlyUnderTheAnalysisTaxonomyUncovered(): void
    {
        $architecture = $this->architecture(
            ['known-evidence' => self::FIXTURE_NAMESPACE . '\\Analysis\\Evidence\\Known\\**'],
            ['known-evidence' => []],
        );

        $result = $this->analyze(self::FIXTURE_PATH . '/Analysis/DirectTaxonomyType.php', $architecture);
        $diagnostic = $this->singleCoverageDiagnostic($result->violations);

        self::assertSame(Severity::Error, $diagnostic->severity);
        self::assertStringContainsString('1 class(es) outside all declared layers', $diagnostic->message);
        self::assertStringContainsString('DirectTaxonomyType', $diagnostic->recommendation ?? '');
    }

    #[Test]
    public function itDoesNotAutoEnrollAnUnlistedChildModule(): void
    {
        $architecture = $this->architecture(
            ['known-evidence' => self::FIXTURE_NAMESPACE . '\\Analysis\\Evidence\\Known\\**'],
            ['known-evidence' => []],
        );

        $result = $this->analyze(self::FIXTURE_PATH . '/Analysis/Evidence', $architecture);
        $diagnostic = $this->singleCoverageDiagnostic($result->violations);

        self::assertStringContainsString('1 class(es) outside all declared layers', $diagnostic->message);
        self::assertStringContainsString('UnlistedEvidence', $diagnostic->recommendation ?? '');
        self::assertStringNotContainsString('KnownEvidence', $diagnostic->recommendation ?? '');
    }

    #[Test]
    public function itFailsCoverageForAnUncoveredDependencyEndpoint(): void
    {
        $architecture = $this->architecture(
            ['owned' => self::FIXTURE_NAMESPACE . '\\Coverage\\Owned\\**'],
            ['owned' => []],
        );

        $result = $this->analyze(self::FIXTURE_PATH . '/Coverage/Owned', $architecture);
        $diagnostic = $this->singleCoverageDiagnostic($result->violations);

        self::assertStringContainsString('1 edge(s) with unmatched target layer', $diagnostic->message);
        self::assertStringContainsString('UncoveredEndpoint', $diagnostic->recommendation ?? '');
    }

    #[Test]
    public function itFailsCoverageForAnIsolatedUncoveredClassWithAnEmptyGraph(): void
    {
        $architecture = $this->architecture(
            ['owned' => self::FIXTURE_NAMESPACE . '\\Coverage\\Owned\\**'],
            ['owned' => []],
        );

        $result = $this->analyze(self::FIXTURE_PATH . '/Coverage/Isolated/IsolatedUncovered.php', $architecture);
        $diagnostic = $this->singleCoverageDiagnostic($result->violations);

        self::assertStringContainsString('0 edge(s) with unmatched source layer', $diagnostic->message);
        self::assertStringContainsString('0 edge(s) with unmatched target layer', $diagnostic->message);
        self::assertStringContainsString('1 class(es) outside all declared layers', $diagnostic->message);
        self::assertStringContainsString('IsolatedUncovered', $diagnostic->recommendation ?? '');
    }

    #[Test]
    public function itDetectsAnActualClassCycleInsideOneDeclaredModule(): void
    {
        $architecture = $this->architecture(
            ['cycle-module' => self::FIXTURE_NAMESPACE . '\\Cycle\\**'],
            ['cycle-module' => []],
        );

        $result = $this->analyze(self::FIXTURE_PATH . '/Cycle', $architecture);
        $cycles = $this->violationsFor($result->violations, CircularDependencyRule::NAME);

        self::assertCount(1, $cycles);
        self::assertSame(Severity::Error, $cycles[0]->severity);
        self::assertStringContainsString('CycleA', $cycles[0]->message);
        self::assertStringContainsString('CycleB', $cycles[0]->message);
        self::assertSame([], $this->violationsFor($result->violations, LayerViolationRule::NAME));
        self::assertSame([], $this->violationsFor($result->violations, LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME));
    }

    /**
     * @param array<string, string> $patternsByLayer
     * @param array<string, list<string>> $allow
     */
    private function architecture(array $patternsByLayer, array $allow): ArchitectureConfiguration
    {
        $layers = [];
        foreach ($patternsByLayer as $name => $pattern) {
            $layers[] = new LayerDefinition($name, new MembershipSpec([$pattern]));
        }

        return new ArchitectureConfiguration(
            new LayerRegistry($layers),
            AllowListBuilder::policyFromExactMap($allow),
            CoverageMode::Error,
        );
    }

    private function analyze(string $path, ArchitectureConfiguration $architecture): \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult
    {
        $container = (new ContainerFactory())->create();
        $processor = $container->get(ArchitecturePolicyConfiguratorInterface::class);
        self::assertInstanceOf(ArchitecturePolicy::class, $processor);
        $processor->bind($architecture);

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);

        $root = AbsolutePath::fromString($path);
        return $pipeline->analyze(new \Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration([$root], [], $root, \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Include));
    }

    /** @param list<Violation> $violations */
    private function singleCoverageDiagnostic(array $violations): Violation
    {
        $diagnostics = $this->violationsFor($violations, LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME);
        self::assertCount(1, $diagnostics);

        return $diagnostics[0];
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function violationsFor(array $violations, string $ruleName): array
    {
        return array_values(array_filter(
            $violations,
            static fn(Violation $violation): bool => $violation->ruleName === $ruleName,
        ));
    }
}
