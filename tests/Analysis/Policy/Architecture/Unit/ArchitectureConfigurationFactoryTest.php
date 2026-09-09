<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureFactoryResult;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationWarning;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Orchestration-level coverage for the factory. Per-concern validator details
 * live in {@see \Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Configuration\Validation}.
 *
 * Cases retained here verify that:
 * - The factory composes its validation pipeline in the expected order.
 * - Top-level structural validation (`architecture:` key shape) is enforced.
 * - The result object carries both the {@see ArchitectureConfiguration} and
 *   its non-fatal warning values.
 */
#[CoversClass(ArchitectureConfigurationFactory::class)]
#[CoversClass(ArchitectureConfiguration::class)]
#[CoversClass(ArchitectureFactoryResult::class)]
#[CoversClass(ArchitectureConfigurationWarning::class)]
final class ArchitectureConfigurationFactoryTest extends TestCase
{
    private ArchitectureConfigurationFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ArchitectureConfigurationFactory();
    }

    // -------------------------------------------------------------------------
    // Happy-path orchestration
    // -------------------------------------------------------------------------

    #[Test]
    public function itProducesAnEmptyConfigurationForEmptyInput(): void
    {
        $result = $this->factory->fromArray([]);

        self::assertTrue($result->configuration->isEmpty());
        self::assertSame(CoverageMode::Ignore, $result->configuration->coverage());
        self::assertSame([], $result->configuration->registry()->layerNames());
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function itReturnsAResultWithConfigurationAndNoWarningsForValidInput(): void
    {
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
            ],
        ]);

        self::assertFalse($result->configuration->isEmpty());
        self::assertSame(['controller'], $result->configuration->registry()->layerNames());
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function itAssemblesLayersAllowAndCoverageIntoOneConfiguration(): void
    {
        // Exercises layers + allow + coverage in one shot.
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ['name' => 'service', 'patterns' => ['App\\Service']],
            ],
            'allow' => [
                'controller' => ['service'],
                'service' => [],
            ],
            'coverage-gap' => 'warn',
        ]);

        $config = $result->configuration;
        self::assertSame(['controller', 'service'], $config->registry()->layerNames());
        self::assertSame(CoverageMode::Warn, $config->coverage());
        self::assertTrue($config->policy()->isAllowed('controller', 'service'));
        self::assertFalse($config->policy()->isAllowed('service', 'controller'));

        // Registry resolves classes correctly.
        $registry = $config->registry();
        self::assertSame('controller', $registry->resolveLayer(SymbolPath::forClass('App\\Controller', 'UserController')));
        self::assertSame('service', $registry->resolveLayer(SymbolPath::forClass('App\\Service', 'UserService')));

        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function itReplacesTheLayersListWholesaleWithAnOverlay(): void
    {
        $result = $this->factory->fromContributions([
            ['layers' => [
                ['name' => 'a', 'patterns' => ['App\\A']],
                ['name' => 'b', 'patterns' => ['App\\B']],
            ]],
            ['layers' => [
                ['name' => 'c', 'patterns' => ['App\\C']],
            ]],
        ]);

        self::assertSame(['c'], $result->configuration->registry()->layerNames());
    }

    #[Test]
    public function itKeepsTheLayersListWhenAnOverlayOmitsIt(): void
    {
        $result = $this->factory->fromContributions([
            ['layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
            ]],
            ['coverage-gap' => 'error'],
        ]);

        self::assertSame(['controller'], $result->configuration->registry()->layerNames());
        self::assertSame(CoverageMode::Error, $result->configuration->coverage());
    }

    #[Test]
    public function itMergesAllowMapsFromMultipleContributionsBySource(): void
    {
        $result = $this->factory->fromContributions([
            [
                'layers' => $this->mergeRegressionLayers(),
                'allow' => ['controller' => ['service']],
            ],
            ['allow' => ['service' => ['repository']]],
        ]);

        self::assertTrue($result->configuration->policy()->isAllowed('controller', 'service'));
        self::assertTrue($result->configuration->policy()->isAllowed('service', 'repository'));
    }

    #[Test]
    public function itOverridesTheCoverageScalarWithTheLastContribution(): void
    {
        $result = $this->factory->fromContributions([
            ['coverage-gap' => 'warn'],
            ['coverage-gap' => 'error'],
        ]);

        self::assertSame(CoverageMode::Error, $result->configuration->coverage());
    }

    #[Test]
    public function itReplacesAnAllowListEntryInsteadOfMergingItsTargets(): void
    {
        $result = $this->factory->fromContributions([
            [
                'layers' => $this->mergeRegressionLayers(),
                'allow' => ['controller' => ['service', 'shared']],
            ],
            ['allow' => ['controller' => ['repository']]],
        ]);

        self::assertTrue($result->configuration->policy()->isAllowed('controller', 'repository'));
        self::assertFalse($result->configuration->policy()->isAllowed('controller', 'service'));
        self::assertFalse($result->configuration->policy()->isAllowed('controller', 'shared'));
    }

    #[Test]
    public function itKeepsPresetLayersWhileMergingAllowAndCoverageAcrossContributions(): void
    {
        $result = $this->factory->fromContributions([
            ['layers' => $this->mergeRegressionLayers(), 'coverage-gap' => 'ignore'],
            ['allow' => ['controller' => ['service']], 'coverage-gap' => 'warn'],
            ['allow' => ['service' => ['repository']], 'coverage-gap' => 'error'],
        ]);

        self::assertSame(['controller', 'service', 'repository', 'shared'], $result->configuration->registry()->layerNames());
        self::assertTrue($result->configuration->policy()->isAllowed('controller', 'service'));
        self::assertTrue($result->configuration->policy()->isAllowed('service', 'repository'));
        self::assertSame(CoverageMode::Error, $result->configuration->coverage());
    }

    #[Test]
    public function itRejectsAnExactSelfLoopBeforeAnalysis(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('directed cycle');
        $this->expectExceptionMessage('service -> service');

        $this->factory->fromArray([
            'layers' => [
                ['name' => 'service', 'patterns' => ['App\\Service']],
            ],
            'allow' => [
                'service' => ['service'],
            ],
        ]);
    }

    #[Test]
    public function itRejectsAnExactTwoLayerCycleBeforeAnalysis(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('directed cycle');
        $this->expectExceptionMessage('controller -> service -> controller');

        $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ['name' => 'service', 'patterns' => ['App\\Service']],
            ],
            'allow' => [
                'controller' => ['service'],
                'service' => ['controller'],
            ],
        ]);
    }

    #[Test]
    public function itRejectsAnExactTransitiveCycleBeforeAnalysis(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('directed cycle');
        $this->expectExceptionMessage('application -> domain -> persistence -> application');

        $this->factory->fromArray([
            'layers' => [
                ['name' => 'application', 'patterns' => ['App\\Application']],
                ['name' => 'domain', 'patterns' => ['App\\Domain']],
                ['name' => 'persistence', 'patterns' => ['App\\Persistence']],
            ],
            'allow' => [
                'application' => ['domain'],
                'domain' => ['persistence'],
                'persistence' => ['application'],
            ],
        ]);
    }

    #[Test]
    public function itAcceptsAnExactDirectedAcyclicGraph(): void
    {
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'application', 'patterns' => ['App\\Application']],
                ['name' => 'domain', 'patterns' => ['App\\Domain']],
                ['name' => 'persistence', 'patterns' => ['App\\Persistence']],
            ],
            'allow' => [
                'application' => ['domain', 'persistence'],
                'domain' => ['persistence'],
                'persistence' => [],
            ],
        ]);

        self::assertTrue($result->configuration->policy()->isAllowed('application', 'domain'));
        self::assertTrue($result->configuration->policy()->isAllowed('domain', 'persistence'));
        self::assertFalse($result->configuration->policy()->isAllowed('persistence', 'application'));
    }

    #[Test]
    public function itSurfacesAWildcardSelfAllowWarningFromTheFactory(): void
    {
        // End-to-end check that WildcardSelfAllowDetector is wired into the
        // factory pipeline after AllowValidator and before result assembly.
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'domain-orders', 'patterns' => ['App\\Domain\\Orders\\**']],
            ],
            'allow' => [
                'domain-*' => ['domain-*'],
            ],
        ]);

        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('wildcard-self-allow', $result->warnings[0]->message);
    }

    #[Test]
    public function itRejectsAnAllowEntryNamingALayerTheLayersValidatorDidNotProduce(): void
    {
        // Demonstrates the orchestration handoff: the registry's layerNames()
        // is what AllowValidator consults.
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('architecture.allow.controller: unknown layer');

        $this->factory->fromArray([
            'layers' => [['name' => 'service', 'patterns' => ['App\\Service']]],
            'allow' => [
                'controller' => ['service'],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Top-level structure validation (factory-owned)
    // -------------------------------------------------------------------------

    #[Test]
    public function itRejectsASequentialTopLevelStructure(): void
    {
        try {
            $this->factory->fromArray(['foo', 'bar']);
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $e) {
            self::assertSame(ConfigurationSource::Resolved, $e->origin()->source());
            self::assertStringContainsString('sequential list is not allowed', $e->getMessage());
        }
    }

    #[Test]
    public function itNamesTheUnknownKeyWhenATopLevelKeyIsMisspelled(): void
    {
        try {
            $this->factory->fromArray([
                'layres' => [],
            ]);
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $e) {
            self::assertSame(ConfigurationSource::Resolved, $e->origin()->source());
            self::assertStringContainsString('layres', $e->getMessage());
            self::assertStringContainsString('Allowed keys', $e->getMessage());
            self::assertStringContainsString('layers', $e->getMessage());
        }
    }

    #[Test]
    public function itRejectsTheUnknownTopLevelKeyImports(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => [['name' => 'a', 'patterns' => ['App\\A']]],
                'imports' => ['some.yaml'],
            ]);
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $e) {
            self::assertSame(ConfigurationSource::Resolved, $e->origin()->source());
            self::assertStringContainsString('imports', $e->getMessage());
        }
    }

    #[Test]
    public function itListsEveryUnknownTopLevelKeyInTheException(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => [['name' => 'a', 'patterns' => ['App\\A']]],
                'foo' => 1,
                'bar' => 2,
            ]);
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $e) {
            self::assertStringContainsString('foo', $e->getMessage());
            self::assertStringContainsString('bar', $e->getMessage());
            self::assertStringContainsString('unknown keys', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // origin()->source() is Resolved for all errors (factory + validators)
    // -------------------------------------------------------------------------

    #[Test]
    public function itAddressesTheResolvedDocumentWhenTheLayersValidatorThrows(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => 'not-a-list',
            ]);
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $e) {
            self::assertSame(ConfigurationSource::Resolved, $e->origin()->source());
        }
    }

    #[Test]
    public function itAddressesTheResolvedDocumentWhenTheAllowValidatorThrows(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => [['name' => 'controller', 'patterns' => ['App\\Controller']]],
                'allow' => 'wrong',
            ]);
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $e) {
            self::assertSame(ConfigurationSource::Resolved, $e->origin()->source());
        }
    }

    #[Test]
    public function itAddressesTheResolvedDocumentWhenTheCoverageValidatorThrows(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => [['name' => 'core', 'patterns' => ['App\\Core']]],
                'coverage-gap' => 'verbose',
            ]);
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $e) {
            self::assertSame(ConfigurationSource::Resolved, $e->origin()->source());
        }
    }

    // -------------------------------------------------------------------------
    // Phase 2 Step C: glob / captured selectors flow end-to-end through the factory
    // -------------------------------------------------------------------------

    #[Test]
    public function itMatchesAGlobAllowTargetAgainstConcreteLayers(): void
    {
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ['name' => 'user-repository', 'patterns' => ['App\\User\\Repository']],
                ['name' => 'order-repository', 'patterns' => ['App\\Order\\Repository']],
            ],
            'allow' => [
                'controller' => ['*-repository'],
            ],
        ]);

        $policy = $result->configuration->policy();

        self::assertTrue($policy->isAllowed('controller', 'user-repository'));
        self::assertTrue($policy->isAllowed('controller', 'order-repository'));
        // No glob match → forbidden.
        self::assertFalse($policy->isAllowed('user-repository', 'controller'));
    }

    #[Test]
    public function itMatchesAGlobAllowSourceAgainstMultipleConcreteLayers(): void
    {
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'domain-orders', 'patterns' => ['App\\Domain\\Orders']],
                ['name' => 'domain-inventory', 'patterns' => ['App\\Domain\\Inventory']],
                ['name' => 'shared', 'patterns' => ['App\\Shared']],
            ],
            'allow' => [
                'domain-*' => ['shared'],
            ],
        ]);

        $policy = $result->configuration->policy();

        self::assertTrue($policy->isAllowed('domain-orders', 'shared'));
        self::assertTrue($policy->isAllowed('domain-inventory', 'shared'));
        self::assertFalse($policy->isAllowed('shared', 'domain-orders'));
    }

    #[Test]
    public function itAcceptsAGlobAllowSelectorThatMatchesNoCurrentRegistryLayer(): void
    {
        // Glob / captured selectors are not cross-validated against registry
        // layer names — Step D template-expansion will produce more layers
        // post-config-load, so a glob with zero current registry matches is
        // still legal at config-load time. The policy will accept any concrete
        // target name that satisfies the glob.
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
            ],
            'allow' => [
                'controller' => ['module-*'],
            ],
        ]);

        $policy = $result->configuration->policy();

        // Policy loaded without failure; glob target matches any layer whose
        // name satisfies the wildcard.
        self::assertTrue($policy->isAllowed('controller', 'module-billing'));
        self::assertFalse($policy->isAllowed('controller', 'service'));
    }

    #[Test]
    public function itRejectsAnUnbalancedBraceInAnAllowSelectorAtConfigLoad(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => [
                    ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ],
                'allow' => [
                    'controller' => ['domain-{m'],
                ],
            ]);
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $e) {
            self::assertSame(ConfigurationSource::Resolved, $e->origin()->source());
            self::assertStringContainsString('architecture.allow.controller[0]', $e->getMessage());
            self::assertStringContainsString("unbalanced '{'", $e->getMessage());
        }
    }

    #[Test]
    public function itMatchesACapturedSelectorOnlyWhenTheSubstitutionBindingAgrees(): void
    {
        // Step E binding-aware semantics: captured source binding flows into
        // captured target before matching, so same-{m} edges pass and
        // cross-instance edges are rejected.
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'app-orders', 'patterns' => ['App\\Orders\\App']],
                ['name' => 'domain-orders', 'patterns' => ['App\\Orders\\Domain']],
                ['name' => 'domain-inventory', 'patterns' => ['App\\Inventory\\Domain']],
            ],
            'allow' => [
                'app-{m}' => ['domain-{m}'],
            ],
        ]);

        $policy = $result->configuration->policy();

        // Same-{m} → allowed.
        self::assertTrue($policy->isAllowed('app-orders', 'domain-orders'));
        // Cross-instance → rejected (binding mismatch).
        self::assertFalse($policy->isAllowed('app-orders', 'domain-inventory'));
    }

    /** @return list<array{name: string, patterns: list<string>}> */
    private function mergeRegressionLayers(): array
    {
        return [
            ['name' => 'controller', 'patterns' => ['App\\Controller']],
            ['name' => 'service', 'patterns' => ['App\\Service']],
            ['name' => 'repository', 'patterns' => ['App\\Repository']],
            ['name' => 'shared', 'patterns' => ['App\\Shared']],
        ];
    }
}
