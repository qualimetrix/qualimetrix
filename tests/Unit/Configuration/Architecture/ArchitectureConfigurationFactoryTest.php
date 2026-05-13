<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Architecture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Qualimetrix\Configuration\Architecture\ArchitectureConfigurationFactory;
use Qualimetrix\Configuration\Architecture\ArchitectureFactoryResult;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Core\Architecture\ArchitectureConfiguration;
use Qualimetrix\Core\Architecture\CoverageMode;
use Qualimetrix\Core\Symbol\SymbolPath;
use Stringable;

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
    // Happy path
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

        // Type assertions are implicit from the return type and field types;
        // verify the carried state instead.
        self::assertFalse($result->configuration->isEmpty());
        self::assertSame(['controller'], $result->configuration->registry()->layerNames());
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function singleLayerWithListPatternRegistersAllPatterns(): void
    {
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'service', 'patterns' => ['App\\Service', 'App\\Domain\\Service']],
            ],
        ]);

        $definitions = $result->configuration->registry()->definitions();
        self::assertCount(1, $definitions);
        self::assertSame('service', $definitions[0]->name());
        self::assertSame(['App\\Service', 'App\\Domain\\Service'], $definitions[0]->patterns());
    }

    #[Test]
    public function layersListPreservesDeclarationOrder(): void
    {
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'zebra', 'patterns' => ['App\\Zebra']],
                ['name' => 'alpha', 'patterns' => ['App\\Alpha']],
                ['name' => 'beta', 'patterns' => ['App\\Beta']],
            ],
        ]);

        // NOT sorted — declaration order is preserved through the registry.
        self::assertSame(['zebra', 'alpha', 'beta'], $result->configuration->registry()->layerNames());
    }

    #[Test]
    public function twoLayersAndAllowProducePolicy(): void
    {
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ['name' => 'service', 'patterns' => ['App\\Service']],
            ],
            'allow' => [
                'controller' => ['service'],
            ],
        ]);

        $policy = $result->configuration->policy();
        self::assertTrue($policy->isAllowed('controller', 'service'));
        self::assertFalse($policy->isAllowed('service', 'controller'));
        // Same-layer dependencies always allowed.
        self::assertTrue($policy->isAllowed('controller', 'controller'));

        // Registry resolves classes correctly.
        $registry = $result->configuration->registry();
        self::assertSame('controller', $registry->resolveLayer(SymbolPath::forClass('App\\Controller', 'UserController')));
        self::assertSame('service', $registry->resolveLayer(SymbolPath::forClass('App\\Service', 'UserService')));
    }

    #[Test]
    public function coverageDefaultsToIgnore(): void
    {
        $result = $this->factory->fromArray([
            'layers' => [['name' => 'core', 'patterns' => ['App\\Core']]],
        ]);

        self::assertSame(CoverageMode::Ignore, $result->configuration->coverage());
    }

    #[Test]
    public function coverageWarnIsParsed(): void
    {
        $result = $this->factory->fromArray([
            'layers' => [['name' => 'core', 'patterns' => ['App\\Core']]],
            'coverage' => 'warn',
        ]);

        self::assertSame(CoverageMode::Warn, $result->configuration->coverage());
    }

    #[Test]
    public function coverageIsCaseInsensitive(): void
    {
        $result = $this->factory->fromArray([
            'layers' => [['name' => 'core', 'patterns' => ['App\\Core']]],
            'coverage' => 'ERROR',
        ]);

        self::assertSame(CoverageMode::Error, $result->configuration->coverage());
    }

    #[Test]
    public function selfReferenceInAllowIsSilentlyDeduplicated(): void
    {
        $logger = new InMemoryLogger();
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ['name' => 'service', 'patterns' => ['App\\Service']],
            ],
            'allow' => [
                'controller' => ['controller', 'service'],
            ],
        ], $logger);

        $policy = $result->configuration->policy();
        // Same-layer is always allowed regardless of presence in the map.
        self::assertTrue($policy->isAllowed('controller', 'controller'));
        // 'controller' should not appear in the explicit target list.
        self::assertSame(['service'], $policy->allowedTargets('controller'));

        // No warnings emitted for self-reference.
        self::assertSame([], $logger->records);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function duplicateAllowTargetsAreDeduplicated(): void
    {
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'a', 'patterns' => ['App\\A']],
                ['name' => 'b', 'patterns' => ['App\\B']],
            ],
            'allow' => [
                'a' => ['b', 'b'],
            ],
        ]);

        self::assertSame(['b'], $result->configuration->policy()->allowedTargets('a'));
    }

    // -------------------------------------------------------------------------
    // Long-form allow entries
    // -------------------------------------------------------------------------

    #[Test]
    public function longFormAllowEntryWithoutTypesIsAcceptedSilently(): void
    {
        $logger = new InMemoryLogger();
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ['name' => 'service', 'patterns' => ['App\\Service']],
            ],
            'allow' => [
                'controller' => [
                    ['target' => 'service'],
                ],
            ],
        ], $logger);

        self::assertSame(['service'], $result->configuration->policy()->allowedTargets('controller'));
        self::assertSame([], $logger->records);
    }

    #[Test]
    public function longFormAllowEntryWithTypesEmitsDeprecationWarning(): void
    {
        $logger = new InMemoryLogger();
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ['name' => 'service', 'patterns' => ['App\\Service']],
            ],
            'allow' => [
                'controller' => [
                    ['target' => 'service', 'types' => ['method_call']],
                ],
            ],
        ], $logger);

        self::assertSame(['service'], $result->configuration->policy()->allowedTargets('controller'));
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
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'a', 'patterns' => ['App\\A']],
                ['name' => 'b', 'patterns' => ['App\\B']],
            ],
            'allow' => [
                'a' => ['b'],
                'b' => ['a'],
            ],
        ], $logger);

        // Logger path (Step 0 keeps existing surface).
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('mutual-allow', $logger->records[0]['message']);
        self::assertStringContainsString('a', $logger->records[0]['message']);
        self::assertStringContainsString('b', $logger->records[0]['message']);

        // Deferred-warning path (Step 1 will drain to RuntimeConfigurator).
        self::assertCount(1, $result->warnings);
        self::assertSame('warning', $result->warnings[0]->level);
        self::assertStringContainsString('mutual-allow', $result->warnings[0]->message);
    }

    #[Test]
    public function noMutualAllowProducesNoWarning(): void
    {
        $logger = new InMemoryLogger();
        $result = $this->factory->fromArray([
            'layers' => [
                ['name' => 'a', 'patterns' => ['App\\A']],
                ['name' => 'b', 'patterns' => ['App\\B']],
            ],
            'allow' => [
                'a' => ['b'],
            ],
        ], $logger);

        self::assertSame([], $logger->records);
        self::assertSame([], $result->warnings);
    }

    // -------------------------------------------------------------------------
    // Layer-list validation (ordered list, long form only)
    // -------------------------------------------------------------------------

    #[Test]
    public function legacyMapShapeForLayersIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/ordered list of layer entries/');

        // Legacy map shape ('layer-name' => pattern) is no longer accepted.
        $this->factory->fromArray([
            'layers' => [
                'controller' => 'App\\Controller',
            ],
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
    public function layerEntryWithoutNameIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/missing or empty "name"/');

        $this->factory->fromArray([
            'layers' => [
                ['patterns' => ['App\\Controller']],
            ],
        ]);
    }

    #[Test]
    public function layerEntryWithEmptyNameIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/missing or empty "name"/');

        $this->factory->fromArray([
            'layers' => [
                ['name' => '', 'patterns' => ['App\\Controller']],
            ],
        ]);
    }

    #[Test]
    public function layerEntryWithoutPatternsIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/missing "patterns"/');

        $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller'],
            ],
        ]);
    }

    #[Test]
    public function layerEntryWithEmptyPatternsListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/"patterns" must contain at least one entry/');

        $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => []],
            ],
        ]);
    }

    #[Test]
    public function layerEntryWithPatternsAsScalarIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/"patterns" must be a non-empty list of strings/');

        $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => 'App\\Controller'],
            ],
        ]);
    }

    #[Test]
    public function emptyPatternStringInsideListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/non-empty string/');

        $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller', '']],
            ],
        ]);
    }

    #[Test]
    public function nonStringPatternInsideListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/non-empty string/');

        $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller', 42]],
            ],
        ]);
    }

    #[Test]
    public function invalidLayerNameIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/UpperCaseName/');

        $this->factory->fromArray([
            'layers' => [
                ['name' => 'UpperCaseName', 'patterns' => ['App\\Foo']],
            ],
        ]);
    }

    #[Test]
    public function duplicateLayerNameAcrossListEntriesIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/duplicate layer name "service"/');

        $this->factory->fromArray([
            'layers' => [
                ['name' => 'service', 'patterns' => ['App\\Service']],
                ['name' => 'service', 'patterns' => ['App\\OtherService']],
            ],
        ]);
    }

    #[Test]
    public function unknownKeyOnLayerEntryIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/unknown key/');

        $this->factory->fromArray([
            'layers' => [
                ['name' => 'controller', 'patterns' => ['App\\Controller'], 'unexpected' => 'foo'],
            ],
        ]);
    }

    #[Test]
    public function duplicatePatternAcrossLayersIsRejected(): void
    {
        try {
            $this->factory->fromArray([
                'layers' => [
                    ['name' => 'a', 'patterns' => ['App\\Shared']],
                    ['name' => 'b', 'patterns' => ['App\\Shared']],
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
            self::assertStringContainsString('App\\Shared', $e->getMessage());
            self::assertStringContainsString('"a"', $e->getMessage());
            self::assertStringContainsString('"b"', $e->getMessage());
            self::assertStringContainsString('unreachable', $e->getMessage());
        }
    }

    #[Test]
    public function duplicateWildcardPatternAcrossLayersIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/App\\\\\\*\\*/');

        $this->factory->fromArray([
            'layers' => [
                ['name' => 'a', 'patterns' => ['App\\**']],
                ['name' => 'b', 'patterns' => ['App\\**']],
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
            'layers' => [['name' => 'a', 'patterns' => ['App\\A']]],
            'allow' => ['a', 'b'],
        ]);
    }

    #[Test]
    public function allowAsScalarIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow');

        $this->factory->fromArray([
            'layers' => [['name' => 'a', 'patterns' => ['App\\A']]],
            'allow' => 'wrong',
        ]);
    }

    #[Test]
    public function allowKeyReferencingUnknownLayerIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller: unknown layer');

        $this->factory->fromArray([
            'layers' => [['name' => 'service', 'patterns' => ['App\\Service']]],
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
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ['name' => 'service', 'patterns' => ['App\\Service']],
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
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ['name' => 'service', 'patterns' => ['App\\Service']],
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
            'layers' => [['name' => 'controller', 'patterns' => ['App\\Controller']]],
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
                ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ['name' => 'service', 'patterns' => ['App\\Service']],
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
            'layers' => [['name' => 'core', 'patterns' => ['App\\Core']]],
            'coverage' => 'verbose',
        ]);
    }

    #[Test]
    public function coverageOfWrongTypeIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.coverage');

        $this->factory->fromArray([
            'layers' => [['name' => 'core', 'patterns' => ['App\\Core']]],
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
                'layers' => 'not-a-list',
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
        }
    }

    // -------------------------------------------------------------------------
    // Top-level structure validation
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
