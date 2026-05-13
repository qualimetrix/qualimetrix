<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Architecture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Qualimetrix\Configuration\Architecture\ArchitectureConfigurationFactory;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Core\Architecture\ArchitectureConfiguration;
use Qualimetrix\Core\Architecture\CoverageMode;
use Qualimetrix\Core\Symbol\SymbolPath;
use Stringable;

#[CoversClass(ArchitectureConfigurationFactory::class)]
#[CoversClass(ArchitectureConfiguration::class)]
final class ArchitectureConfigurationFactoryTest extends TestCase
{
    private ArchitectureConfigurationFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ArchitectureConfigurationFactory();
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function emptyInputProducesEmptyConfiguration(): void
    {
        $config = $this->factory->fromArray([]);

        self::assertTrue($config->isEmpty());
        self::assertSame(CoverageMode::Ignore, $config->coverage());
        self::assertSame([], $config->registry()->layerNames());
        self::assertSame([], $config->policy()->knownLayers());
    }

    #[Test]
    public function singleLayerWithStringPatternRegistersOnePattern(): void
    {
        $config = $this->factory->fromArray([
            'layers' => [
                'controller' => 'App\\Controller',
            ],
        ]);

        self::assertFalse($config->isEmpty());
        self::assertSame(['controller'], $config->registry()->layerNames());

        $definitions = $config->registry()->definitions();
        self::assertCount(1, $definitions);
        self::assertSame('controller', $definitions[0]->name());
        self::assertSame(['App\\Controller'], $definitions[0]->patterns());
    }

    #[Test]
    public function singleLayerWithListPatternRegistersAllPatterns(): void
    {
        $config = $this->factory->fromArray([
            'layers' => [
                'service' => ['App\\Service', 'App\\Domain\\Service'],
            ],
        ]);

        $definitions = $config->registry()->definitions();
        self::assertCount(1, $definitions);
        self::assertSame(['App\\Service', 'App\\Domain\\Service'], $definitions[0]->patterns());
    }

    #[Test]
    public function twoLayersAndAllowProducePolicy(): void
    {
        $config = $this->factory->fromArray([
            'layers' => [
                'controller' => 'App\\Controller',
                'service' => 'App\\Service',
            ],
            'allow' => [
                'controller' => ['service'],
            ],
        ]);

        $policy = $config->policy();
        self::assertTrue($policy->isAllowed('controller', 'service'));
        self::assertFalse($policy->isAllowed('service', 'controller'));
        // Same-layer dependencies always allowed.
        self::assertTrue($policy->isAllowed('controller', 'controller'));
        self::assertSame(['controller', 'service'], $policy->knownLayers());

        // Registry resolves classes correctly.
        $registry = $config->registry();
        self::assertSame('controller', $registry->resolveLayer(SymbolPath::forClass('App\\Controller', 'UserController')));
        self::assertSame('service', $registry->resolveLayer(SymbolPath::forClass('App\\Service', 'UserService')));
    }

    #[Test]
    public function coverageDefaultsToIgnore(): void
    {
        $config = $this->factory->fromArray([
            'layers' => ['core' => 'App\\Core'],
        ]);

        self::assertSame(CoverageMode::Ignore, $config->coverage());
    }

    #[Test]
    public function coverageWarnIsParsed(): void
    {
        $config = $this->factory->fromArray([
            'layers' => ['core' => 'App\\Core'],
            'coverage' => 'warn',
        ]);

        self::assertSame(CoverageMode::Warn, $config->coverage());
    }

    #[Test]
    public function coverageIsCaseInsensitive(): void
    {
        $config = $this->factory->fromArray([
            'layers' => ['core' => 'App\\Core'],
            'coverage' => 'ERROR',
        ]);

        self::assertSame(CoverageMode::Error, $config->coverage());
    }

    #[Test]
    public function selfReferenceInAllowIsSilentlyDeduplicated(): void
    {
        $logger = new InMemoryLogger();
        $config = $this->factory->fromArray([
            'layers' => [
                'controller' => 'App\\Controller',
                'service' => 'App\\Service',
            ],
            'allow' => [
                'controller' => ['controller', 'service'],
            ],
        ], $logger);

        $policy = $config->policy();
        // Same-layer is always allowed regardless of presence in the map.
        self::assertTrue($policy->isAllowed('controller', 'controller'));
        // 'controller' should not appear in the explicit target list.
        self::assertSame(['service'], $policy->allowedTargets('controller'));

        // No warnings emitted for self-reference.
        self::assertSame([], $logger->records);
    }

    #[Test]
    public function duplicateAllowTargetsAreDeduplicated(): void
    {
        $config = $this->factory->fromArray([
            'layers' => [
                'a' => 'App\\A',
                'b' => 'App\\B',
            ],
            'allow' => [
                'a' => ['b', 'b'],
            ],
        ]);

        self::assertSame(['b'], $config->policy()->allowedTargets('a'));
    }

    // -------------------------------------------------------------------------
    // Long-form allow entries
    // -------------------------------------------------------------------------

    #[Test]
    public function longFormAllowEntryWithoutTypesIsAcceptedSilently(): void
    {
        $logger = new InMemoryLogger();
        $config = $this->factory->fromArray([
            'layers' => [
                'controller' => 'App\\Controller',
                'service' => 'App\\Service',
            ],
            'allow' => [
                'controller' => [
                    ['target' => 'service'],
                ],
            ],
        ], $logger);

        self::assertSame(['service'], $config->policy()->allowedTargets('controller'));
        self::assertSame([], $logger->records);
    }

    #[Test]
    public function longFormAllowEntryWithTypesEmitsDeprecationWarning(): void
    {
        $logger = new InMemoryLogger();
        $config = $this->factory->fromArray([
            'layers' => [
                'controller' => 'App\\Controller',
                'service' => 'App\\Service',
            ],
            'allow' => [
                'controller' => [
                    ['target' => 'service', 'types' => ['method_call']],
                ],
            ],
        ], $logger);

        self::assertSame(['service'], $config->policy()->allowedTargets('controller'));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString("'types' filter declared but not yet enforced", $logger->records[0]['message']);
        self::assertStringContainsString('architecture.allow.controller', $logger->records[0]['message']);
    }

    // -------------------------------------------------------------------------
    // Mutual-allow detection
    // -------------------------------------------------------------------------

    #[Test]
    public function mutualAllowEmitsSingleWarningWithBothLayers(): void
    {
        $logger = new InMemoryLogger();
        $this->factory->fromArray([
            'layers' => [
                'a' => 'App\\A',
                'b' => 'App\\B',
            ],
            'allow' => [
                'a' => ['b'],
                'b' => ['a'],
            ],
        ], $logger);

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('mutual-allow', $logger->records[0]['message']);
        self::assertStringContainsString('a', $logger->records[0]['message']);
        self::assertStringContainsString('b', $logger->records[0]['message']);
    }

    #[Test]
    public function noMutualAllowProducesNoWarning(): void
    {
        $logger = new InMemoryLogger();
        $this->factory->fromArray([
            'layers' => [
                'a' => 'App\\A',
                'b' => 'App\\B',
            ],
            'allow' => [
                'a' => ['b'],
            ],
        ], $logger);

        self::assertSame([], $logger->records);
    }

    // -------------------------------------------------------------------------
    // Layer validation
    // -------------------------------------------------------------------------

    #[Test]
    public function layersAsSequentialListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.layers');

        $this->factory->fromArray([
            'layers' => ['App\\Controller', 'App\\Service'],
        ]);
    }

    #[Test]
    public function layersAsScalarIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.layers');

        $this->factory->fromArray([
            'layers' => 'App\\Controller',
        ]);
    }

    #[Test]
    public function emptyLayerPatternStringIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.layers.controller');

        $this->factory->fromArray([
            'layers' => [
                'controller' => '',
            ],
        ]);
    }

    #[Test]
    public function layerPatternOfWrongTypeIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.layers.controller');

        $this->factory->fromArray([
            'layers' => [
                'controller' => 42,
            ],
        ]);
    }

    #[Test]
    public function emptyPatternInsideListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.layers.controller');

        $this->factory->fromArray([
            'layers' => [
                'controller' => ['App\\Controller', ''],
            ],
        ]);
    }

    #[Test]
    public function nonStringPatternInsideListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.layers.controller');

        $this->factory->fromArray([
            'layers' => [
                'controller' => ['App\\Controller', 42],
            ],
        ]);
    }

    #[Test]
    public function emptyPatternListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.layers.controller');

        $this->factory->fromArray([
            'layers' => [
                'controller' => [],
            ],
        ]);
    }

    #[Test]
    public function invalidLayerNameIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.layers.UpperCaseName');

        $this->factory->fromArray([
            'layers' => [
                'UpperCaseName' => 'App\\Foo',
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Allow validation
    // -------------------------------------------------------------------------

    #[Test]
    public function allowAsSequentialListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow');

        $this->factory->fromArray([
            'layers' => ['a' => 'App\\A'],
            'allow' => ['a', 'b'],
        ]);
    }

    #[Test]
    public function allowAsScalarIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow');

        $this->factory->fromArray([
            'layers' => ['a' => 'App\\A'],
            'allow' => 'wrong',
        ]);
    }

    #[Test]
    public function allowKeyReferencingUnknownLayerIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller: unknown layer');

        $this->factory->fromArray([
            'layers' => ['service' => 'App\\Service'],
            'allow' => [
                'controller' => ['service'],
            ],
        ]);
    }

    #[Test]
    public function allowTargetReferencingUnknownLayerIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("architecture.allow.controller[0]: unknown layer 'servise'");

        $this->factory->fromArray([
            'layers' => [
                'controller' => 'App\\Controller',
                'service' => 'App\\Service',
            ],
            'allow' => [
                'controller' => ['servise'],
            ],
        ]);
    }

    #[Test]
    public function allowTargetsAsScalarIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller');

        $this->factory->fromArray([
            'layers' => [
                'controller' => 'App\\Controller',
                'service' => 'App\\Service',
            ],
            'allow' => [
                'controller' => 'service',
            ],
        ]);
    }

    #[Test]
    public function emptyTargetStringIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller[0]');

        $this->factory->fromArray([
            'layers' => [
                'controller' => 'App\\Controller',
            ],
            'allow' => [
                'controller' => [''],
            ],
        ]);
    }

    #[Test]
    public function longFormAllowEntryWithoutTargetKeyIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller[0]');

        $this->factory->fromArray([
            'layers' => [
                'controller' => 'App\\Controller',
                'service' => 'App\\Service',
            ],
            'allow' => [
                'controller' => [
                    ['types' => ['method_call']],
                ],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Coverage validation
    // -------------------------------------------------------------------------

    #[Test]
    public function unknownCoverageValueIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.coverage');

        $this->factory->fromArray([
            'layers' => ['core' => 'App\\Core'],
            'coverage' => 'verbose',
        ]);
    }

    #[Test]
    public function coverageOfWrongTypeIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.coverage');

        $this->factory->fromArray([
            'layers' => ['core' => 'App\\Core'],
            'coverage' => 42,
        ]);
    }

    // -------------------------------------------------------------------------
    // ConfigLoadException carries the architecture configPath
    // -------------------------------------------------------------------------

    #[Test]
    public function thrownExceptionCarriesArchitectureConfigPath(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => 'not-a-map',
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
        }
    }

    // -------------------------------------------------------------------------
    // Top-level structure validation (Issue 1)
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
                'layres' => ['controller' => 'App\\Controller'],
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
                'layers' => ['a' => 'App\\A'],
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
                'layers' => ['a' => 'App\\A'],
                'foo' => 1,
                'bar' => 2,
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('foo', $e->getMessage());
            self::assertStringContainsString('bar', $e->getMessage());
            // Plural form used when more than one unknown key
            self::assertStringContainsString('unknown keys', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Pre-validation of pattern collisions (Issue 5)
    // -------------------------------------------------------------------------

    #[Test]
    public function duplicateLiteralPatternAcrossLayersIsRejected(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => [
                    'a' => 'App\\Shared',
                    'b' => 'App\\Shared',
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
            self::assertStringContainsString('duplicate pattern', $e->getMessage());
            self::assertStringContainsString('App\\Shared', $e->getMessage());
            self::assertStringContainsString('"a"', $e->getMessage());
            self::assertStringContainsString('"b"', $e->getMessage());
        }
    }

    #[Test]
    public function duplicateWildcardPatternAcrossLayersIsRejected(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => [
                    'a' => 'App\\**',
                    'b' => 'App\\**',
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('duplicate pattern', $e->getMessage());
            self::assertStringContainsString('App\\**', $e->getMessage());
        }
    }

    #[Test]
    public function samePrefixAndSpecificityProducesWarning(): void
    {
        $logger = new InMemoryLogger();
        $this->factory->fromArray([
            'layers' => [
                'a' => 'App\\**\\Foo',
                'b' => 'App\\**\\Bar',
            ],
        ], $logger);

        $warnings = array_values(array_filter(
            $logger->records,
            static fn(array $record): bool => $record['level'] === 'warning'
                && str_contains($record['message'], 'pattern collision'),
        ));

        self::assertCount(1, $warnings);
        self::assertStringContainsString('App\\**\\Foo', $warnings[0]['message']);
        self::assertStringContainsString('App\\**\\Bar', $warnings[0]['message']);
    }

    #[Test]
    public function disjointPrefixesProduceNoCollisionWarning(): void
    {
        $logger = new InMemoryLogger();
        $this->factory->fromArray([
            'layers' => [
                'controller' => 'App\\Controller\\**',
                'service' => 'App\\Service\\**',
            ],
        ], $logger);

        $collisionWarnings = array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'pattern collision'),
        );
        self::assertSame([], array_values($collisionWarnings));
    }

    #[Test]
    public function purePrefixPatternsThatAreNotEqualProduceNoCollisionWarning(): void
    {
        $logger = new InMemoryLogger();
        $this->factory->fromArray([
            'layers' => [
                'a' => 'App\\Controller',
                'b' => 'App\\Service',
            ],
        ], $logger);

        $collisionWarnings = array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'pattern collision'),
        );
        self::assertSame([], array_values($collisionWarnings));
    }
}

/**
 * Minimal in-memory PSR-3 logger for verifying warning emission.
 */
final class InMemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
