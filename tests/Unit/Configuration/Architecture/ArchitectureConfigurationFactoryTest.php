<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Architecture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Architecture\ArchitectureConfigurationFactory;
use Qualimetrix\Configuration\Architecture\ArchitectureFactoryResult;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Core\Architecture\ArchitectureConfiguration;
use Qualimetrix\Core\Architecture\CoverageMode;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Orchestration-level coverage for the factory. Per-concern validator details
 * live in {@see \Qualimetrix\Tests\Unit\Configuration\Architecture\Validation}.
 *
 * Cases retained here verify that:
 * - The factory composes the four validators in the expected order.
 * - Top-level structural validation (`architecture:` key shape) is enforced.
 * - The result object carries both the {@see ArchitectureConfiguration} and
 *   the deferred-warning list.
 */
#[CoversClass(ArchitectureConfigurationFactory::class)]
#[CoversClass(ArchitectureConfiguration::class)]
#[CoversClass(ArchitectureFactoryResult::class)]
#[CoversClass(DeferredWarning::class)]
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
    public function emptyInputProducesEmptyConfiguration(): void
    {
        $result = $this->factory->fromArray([]);

        self::assertTrue($result->configuration->isEmpty());
        self::assertSame(CoverageMode::Ignore, $result->configuration->coverage());
        self::assertSame([], $result->configuration->registry()->layerNames());
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function fromArrayReturnsArchitectureFactoryResultWithConfigurationAndEmptyWarnings(): void
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
    public function fullConfigurationIsAssembledFromAllValidators(): void
    {
        // Exercises layers + allow + coverage + mutual-allow in one shot.
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ['name' => 'service', 'patterns' => ['App\\Service']],
            ],
            'allow' => [
                'controller' => ['service'],
                'service' => ['controller'], // mutual ↔
            ],
            'coverage' => 'warn',
        ]);

        $config = $result->configuration;
        self::assertSame(['controller', 'service'], $config->registry()->layerNames());
        self::assertSame(CoverageMode::Warn, $config->coverage());
        self::assertTrue($config->policy()->isAllowed('controller', 'service'));
        self::assertTrue($config->policy()->isAllowed('service', 'controller'));

        // Registry resolves classes correctly.
        $registry = $config->registry();
        self::assertSame('controller', $registry->resolveLayer(SymbolPath::forClass('App\\Controller', 'UserController')));
        self::assertSame('service', $registry->resolveLayer(SymbolPath::forClass('App\\Service', 'UserService')));

        // Mutual-allow warning surfaced.
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('mutual-allow', $result->warnings[0]->message);
    }

    #[Test]
    public function wildcardSelfAllowWarningSurfacedByFactory(): void
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
    public function allowIsCrossValidatedAgainstLayerNamesProducedByLayersValidator(): void
    {
        // Demonstrates the orchestration handoff: the registry's layerNames()
        // is what AllowValidator consults.
        $this->expectException(ConfigLoadException::class);
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
    public function sequentialTopLevelStructureIsRejected(): void
    {
        try {
            $this->factory->fromArray(['foo', 'bar']);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
            self::assertStringContainsString('sequential list is not allowed', $e->getMessage());
        }
    }

    #[Test]
    public function unknownTopLevelKeyTypoIsRejectedWithKeyMentioned(): void
    {
        try {
            $this->factory->fromArray([
                'layres' => [],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
            self::assertStringContainsString('layres', $e->getMessage());
            self::assertStringContainsString('Allowed keys', $e->getMessage());
            self::assertStringContainsString('layers', $e->getMessage());
        }
    }

    #[Test]
    public function unknownTopLevelKeyImportsIsRejected(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => [['name' => 'a', 'patterns' => ['App\\A']]],
                'imports' => ['some.yaml'],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
            self::assertStringContainsString('imports', $e->getMessage());
        }
    }

    #[Test]
    public function multipleUnknownTopLevelKeysAreListed(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => [['name' => 'a', 'patterns' => ['App\\A']]],
                'foo' => 1,
                'bar' => 2,
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('foo', $e->getMessage());
            self::assertStringContainsString('bar', $e->getMessage());
            self::assertStringContainsString('unknown keys', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // configPath is "architecture" for all errors (factory + validators)
    // -------------------------------------------------------------------------

    #[Test]
    public function thrownExceptionFromLayersValidatorCarriesArchitectureConfigPath(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => 'not-a-list',
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
        }
    }

    #[Test]
    public function thrownExceptionFromAllowValidatorCarriesArchitectureConfigPath(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => [['name' => 'controller', 'patterns' => ['App\\Controller']]],
                'allow' => 'wrong',
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
        }
    }

    #[Test]
    public function thrownExceptionFromCoverageValidatorCarriesArchitectureConfigPath(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => [['name' => 'core', 'patterns' => ['App\\Core']]],
                'coverage' => 'verbose',
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
        }
    }

    // -------------------------------------------------------------------------
    // Phase 2 Step C: glob / captured selectors flow end-to-end through the factory
    // -------------------------------------------------------------------------

    #[Test]
    public function globAllowTargetReachesPolicyAndMatchesConcreteLayers(): void
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
    public function globAllowSourceReachesPolicyAndMatchesMultipleConcreteLayers(): void
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
    public function globAllowSelectorThatMatchesNoConcreteRegistryLayerIsAccepted(): void
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
    public function unbalancedBraceInAllowSelectorIsRejectedAtConfigLoad(): void
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
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
            self::assertStringContainsString('architecture.allow.controller[0]', $e->getMessage());
            self::assertStringContainsString("unbalanced '{'", $e->getMessage());
        }
    }

    #[Test]
    public function capturedSelectorWithSubstitutionParsesEndToEnd(): void
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
}
