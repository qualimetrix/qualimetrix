<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationContext;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationStageInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\AbsolutePath;
use Symfony\Component\Console\Input\InputInterface;

#[CoversClass(TransitionalRuntimeConfiguration::class)]
final class AnalysisConfigurationTest extends TestCase
{
    #[Test]
    public function itHasDefaultValues(): void
    {
        $config = new TransitionalRuntimeConfiguration();
        $resolved = $this->resolveFeatureValues([]);

        self::assertSame((string) getcwd() . '/.qmx-cache', $config->cacheDir->value());
        self::assertTrue($config->cacheEnabled);
        self::assertSame('summary', $resolved->outputFormat->value);
        self::assertSame('chain', $config->namespaceStrategy);
        self::assertNull($config->composerJsonPath);
        self::assertSame([], $config->aggregationPrefixes);
        self::assertNull($config->aggregationAutoDepth);
        self::assertSame([], $resolved->ruleSelection->disabled);
        self::assertSame([], $resolved->ruleSelection->only);
        self::assertSame([], $resolved->findingExclusions->excludePaths);
        self::assertSame([], $resolved->findingExclusions->excludeNamespaces);
        self::assertNull($config->failOn);
    }

    #[Test]
    public function itCreatesFromArrayWithDefaults(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray([]);
        $resolved = $this->resolveFeatureValues([]);

        self::assertSame((string) getcwd() . '/.qmx-cache', $config->cacheDir->value());
        self::assertTrue($config->cacheEnabled);
        self::assertSame('summary', $resolved->outputFormat->value);
    }

    #[Test]
    public function itCreatesFromArrayWithValues(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray([
            'cache' => [
                'dir' => '/tmp/cache',
                'enabled' => false,
            ],
            'format' => 'json',
            'namespace' => [
                'strategy' => 'psr4',
                'composer_json' => 'composer.json',
            ],
            'aggregation' => [
                'prefixes' => ['App\\Domain', 'App\\Infrastructure'],
                'auto_depth' => 2,
            ],
            'disabled_rules' => ['complexity.cyclomatic'],
            'only_rules' => ['size'],
            'exclude_paths' => ['src/Generated/*', 'src/Legacy/*'],
        ]);
        $resolved = $this->resolveFeatureValues([
            ConfigSchema::FORMAT => 'json',
            ConfigSchema::DISABLED_RULES => ['complexity.cyclomatic'],
            ConfigSchema::ONLY_RULES => ['size'],
            ConfigSchema::EXCLUDE_PATHS => ['src/Generated/*', 'src/Legacy/*'],
        ]);

        self::assertSame('/tmp/cache', $config->cacheDir->value());
        self::assertFalse($config->cacheEnabled);
        self::assertSame('json', $resolved->outputFormat->value);
        self::assertSame('psr4', $config->namespaceStrategy);
        self::assertNotNull($config->composerJsonPath);
        self::assertSame((string) getcwd() . '/composer.json', $config->composerJsonPath->value());
        self::assertSame(['App\\Domain', 'App\\Infrastructure'], $config->aggregationPrefixes);
        self::assertSame(2, $config->aggregationAutoDepth);
        self::assertSame(['complexity.cyclomatic'], $resolved->ruleSelection->disabled);
        self::assertSame(['size'], $resolved->ruleSelection->only);
        self::assertSame(['src/Generated/*', 'src/Legacy/*'], $resolved->findingExclusions->excludePaths);
    }

    #[Test]
    public function itMergesConfigurations(): void
    {
        $base = new TransitionalRuntimeConfiguration(
            cacheDir: AbsolutePath::fromString('/original/cache'),
            cacheEnabled: true,
            namespaceStrategy: 'chain',
        );

        $merged = $base->merge([
            'cache' => [
                'dir' => '/new/cache',
            ],
            'format' => 'json',
        ]);

        // Merged values
        self::assertSame('/new/cache', $merged->cacheDir->value());
        self::assertSame('json', $this->resolveFeatureValues(['format' => 'json'])->outputFormat->value);

        $exclusions = $this->resolveFeatureValues(
            ['exclude_paths' => ['configured', 'shared'], 'exclude_namespaces' => ['App\\Configured', 'App\\Shared']],
        )->findingExclusions->withAdditional(
            ['shared', 'cli'],
            ['App\\Shared', 'App\\Cli'],
        );
        self::assertSame(['configured', 'shared', 'cli'], $exclusions->excludePaths);
        self::assertSame(['App\\Configured', 'App\\Shared', 'App\\Cli'], $exclusions->excludeNamespaces);

        // Preserved values
        self::assertTrue($merged->cacheEnabled);
        self::assertSame('chain', $merged->namespaceStrategy);
    }

    #[Test]
    public function itMergeAccumulatesDisabledRules(): void
    {
        $resolved = $this->resolveFeatureValues(
            ['disabled_rules' => ['rule-a']],
            ['disabled_rules' => ['rule-b', 'rule-c']],
        );
        self::assertSame(['rule-a', 'rule-b', 'rule-c'], $resolved->ruleSelection->disabled);
    }

    #[Test]
    public function itMergeAccumulatesExcludePaths(): void
    {
        $resolved = $this->resolveFeatureValues(
            ['exclude_paths' => ['src/Generated/*']],
            ['exclude_paths' => ['src/Legacy/*', 'src/Vendor/*']],
        );
        self::assertSame(['src/Generated/*', 'src/Legacy/*', 'src/Vendor/*'], $resolved->findingExclusions->excludePaths);
    }

    #[Test]
    public function itMergeExcludePathsDeduplicates(): void
    {
        $resolved = $this->resolveFeatureValues(
            ['exclude_paths' => ['src/Generated/*']],
            ['exclude_paths' => ['src/Generated/*', 'src/Legacy/*']],
        );
        self::assertSame(['src/Generated/*', 'src/Legacy/*'], $resolved->findingExclusions->excludePaths);
    }

    #[Test]
    public function itFromArrayParsesExcludeNamespaces(): void
    {
        $resolved = $this->resolveFeatureValues([
            'exclude_namespaces' => ['App\\Generated', 'App\\Legacy'],
        ]);
        self::assertSame(['App\\Generated', 'App\\Legacy'], $resolved->findingExclusions->excludeNamespaces);
    }

    #[Test]
    public function itMergeAccumulatesExcludeNamespaces(): void
    {
        $resolved = $this->resolveFeatureValues(
            ['exclude_namespaces' => ['App\\Generated']],
            ['exclude_namespaces' => ['App\\Legacy', 'App\\Vendor']],
        );
        self::assertSame(['App\\Generated', 'App\\Legacy', 'App\\Vendor'], $resolved->findingExclusions->excludeNamespaces);
    }

    #[Test]
    public function itMergeExcludeNamespacesDeduplicates(): void
    {
        $resolved = $this->resolveFeatureValues(
            ['exclude_namespaces' => ['App\\Generated']],
            ['exclude_namespaces' => ['App\\Generated', 'App\\Legacy']],
        );
        self::assertSame(['App\\Generated', 'App\\Legacy'], $resolved->findingExclusions->excludeNamespaces);
    }

    #[Test]
    public function itMergeEmptyOnlyRulesResetsToEmpty(): void
    {
        $resolved = $this->resolveFeatureValues(['only_rules' => ['complexity', 'size']], ['only_rules' => []]);
        self::assertSame([], $resolved->ruleSelection->only);
    }

    #[Test]
    public function itMergeEmptyAggregationPrefixesResetsToEmpty(): void
    {
        $base = new TransitionalRuntimeConfiguration(
            aggregationPrefixes: ['App\\Domain', 'App\\Infrastructure'],
        );

        $merged = $base->merge([
            'aggregation' => [
                'prefixes' => [],
            ],
        ]);

        self::assertSame([], $merged->aggregationPrefixes);
    }

    #[Test]
    public function itMergeWithoutOnlyRulesPreservesExisting(): void
    {
        $resolved = $this->resolveFeatureValues(['only_rules' => ['complexity']], ['format' => 'json']);
        self::assertSame(['complexity'], $resolved->ruleSelection->only);
    }

    #[Test]
    public function itMergeWithoutAggregationPrefixesPreservesExisting(): void
    {
        $base = new TransitionalRuntimeConfiguration(
            aggregationPrefixes: ['App\\Domain'],
        );

        $merged = $base->merge([
            'format' => 'json',
        ]);

        self::assertSame(['App\\Domain'], $merged->aggregationPrefixes);
    }

    #[Test]
    public function itFromArrayRejectsInvalidCacheDir(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('cache.dir');

        TransitionalRuntimeConfiguration::fromArray([
            'cache' => ['dir' => 123],
        ]);
    }

    #[Test]
    public function itFromArrayRejectsNonBoolCacheEnabled(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('expected boolean');

        TransitionalRuntimeConfiguration::fromArray([
            'cache' => ['enabled' => 'yes'],
        ]);
    }

    #[Test]
    public function itFromArrayRejectsNonStringFormat(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('format');

        $this->resolveFeatureValues([
            'format' => 123,
        ]);
    }

    #[Test]
    public function itFromArrayRejectsInvalidNamespaceStrategy(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Allowed values: chain, psr4, tokenizer');

        TransitionalRuntimeConfiguration::fromArray([
            'namespace' => ['strategy' => 'invalid'],
        ]);
    }

    #[Test]
    public function itFromArrayRejectsNegativeWorkers(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must be non-negative');

        TransitionalRuntimeConfiguration::fromArray([
            'parallel' => ['workers' => -5],
        ]);
    }

    #[Test]
    public function itFromArrayRejectsZeroAutoDepth(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must be positive');

        TransitionalRuntimeConfiguration::fromArray([
            'aggregation' => ['auto_depth' => 0],
        ]);
    }

    #[Test]
    public function itFromArrayRejectsNonArrayPrefixes(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('expected array');

        TransitionalRuntimeConfiguration::fromArray([
            'aggregation' => ['prefixes' => 'string'],
        ]);
    }

    #[Test]
    public function itFromArrayAcceptsAbsentKeysWithDefaults(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray([]);
        $resolved = $this->resolveFeatureValues([]);

        self::assertSame((string) getcwd() . '/.qmx-cache', $config->cacheDir->value());
        self::assertTrue($config->cacheEnabled);
        self::assertSame('summary', $resolved->outputFormat->value);
        self::assertSame('chain', $config->namespaceStrategy);
        self::assertNull($config->composerJsonPath);
        self::assertSame([], $config->aggregationPrefixes);
        self::assertNull($config->aggregationAutoDepth);
        self::assertSame([], $resolved->ruleSelection->disabled);
        self::assertSame([], $resolved->ruleSelection->only);
        self::assertSame([], $resolved->findingExclusions->excludePaths);
        self::assertSame([], $resolved->findingExclusions->excludeNamespaces);
        self::assertNull($config->workers);
        self::assertNull($config->failOn);
        self::assertFalse($config->includeGenerated);
        self::assertSame([], $config->frameworkNamespaces);
        self::assertNull($config->memoryLimit);
    }

    #[Test]
    public function itFromArrayAcceptsNullWorkers(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray([
            'parallel' => ['workers' => null],
        ]);

        self::assertNull($config->workers);
    }

    #[Test]
    public function itFromArrayTreatsExplicitNullAsDefault(): void
    {
        // YAML `format: ~` or `format:` (no value) parses as null
        $config = TransitionalRuntimeConfiguration::fromArray([
            'format' => null,
            'cache' => ['enabled' => null, 'dir' => null],
            ConfigSchema::DISABLED_RULES => null,
        ]);
        $resolved = $this->resolveFeatureValues(['format' => null, ConfigSchema::DISABLED_RULES => null]);

        self::assertSame('summary', $resolved->outputFormat->value);
        self::assertTrue($config->cacheEnabled);
        self::assertSame((string) getcwd() . '/.qmx-cache', $config->cacheDir->value());
        self::assertSame([], $resolved->ruleSelection->disabled);
    }

    #[Test]
    public function itFromArrayRejectsNonStringListElements(): void
    {
        $resolved = $this->resolveFeatureValues([
            ConfigSchema::EXCLUDE_PATHS => ['src/*', 123],
        ]);

        self::assertSame(['src/*'], $resolved->findingExclusions->excludePaths);
    }

    // --- failOn tests ---

    #[Test]
    public function itFromArrayParsesFailOnWarning(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray(['fail_on' => 'warning']);

        self::assertSame(Severity::Warning, $config->failOn);
    }

    #[Test]
    public function itFromArrayParsesFailOnError(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray(['fail_on' => 'error']);

        self::assertSame(Severity::Error, $config->failOn);
    }

    #[Test]
    public function itFromArrayParsesFailOnInfo(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray(['fail_on' => 'info']);

        self::assertSame(Severity::Info, $config->failOn);
    }

    #[Test]
    public function itFromArrayFailOnNullByDefault(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray([]);

        self::assertNull($config->failOn);
    }

    #[Test]
    public function itFromArrayFailOnInvalidStringThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid value "invalid" for "fail_on"');

        TransitionalRuntimeConfiguration::fromArray(['fail_on' => 'invalid']);
    }

    #[Test]
    public function itFromArrayFailOnSeverityEnum(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray(['fail_on' => Severity::Error]);

        self::assertSame(Severity::Error, $config->failOn);
    }

    #[Test]
    public function itMergeFailOnOverridesWhenPresent(): void
    {
        $base = new TransitionalRuntimeConfiguration(failOn: Severity::Warning);

        $merged = $base->merge(['fail_on' => 'error']);

        self::assertSame(Severity::Error, $merged->failOn);
    }

    #[Test]
    public function itMergeFailOnPreservesWhenNotInOverrides(): void
    {
        $base = new TransitionalRuntimeConfiguration(failOn: Severity::Error);

        $merged = $base->merge(['format' => 'json']);

        self::assertSame(Severity::Error, $merged->failOn);
    }

    #[Test]
    public function itMergeFailOnPreservesNullWhenNotInOverrides(): void
    {
        $base = new TransitionalRuntimeConfiguration();

        $merged = $base->merge(['format' => 'json']);

        self::assertNull($merged->failOn);
    }

    #[Test]
    public function itFromArrayParsesFailOnNone(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray(['fail_on' => 'none']);

        self::assertFalse($config->failOn);
    }

    #[Test]
    public function itMergeFailOnNoneOverridesWhenPresent(): void
    {
        $base = new TransitionalRuntimeConfiguration(failOn: Severity::Warning);

        $merged = $base->merge(['fail_on' => 'none']);

        self::assertFalse($merged->failOn);
    }

    #[Test]
    public function itMergeFailOnNonePreservedWhenNotOverridden(): void
    {
        $base = new TransitionalRuntimeConfiguration(failOn: false);

        $merged = $base->merge(['format' => 'json']);

        self::assertFalse($merged->failOn);
    }

    // Framework namespaces tests

    #[Test]
    public function itHasDefaultFrameworkNamespacesEmpty(): void
    {
        $config = new TransitionalRuntimeConfiguration();

        self::assertSame([], $config->frameworkNamespaces);
    }

    #[Test]
    public function itFromArrayParsesFrameworkNamespaces(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray([
            'coupling.framework_namespaces' => ['Symfony', 'PhpParser', 'Psr'],
        ]);

        self::assertSame(['Symfony', 'PhpParser', 'Psr'], $config->frameworkNamespaces);
    }

    #[Test]
    public function itFromArrayParsesNestedFrameworkNamespaces(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray([
            'coupling' => [
                'framework_namespaces' => ['Symfony', 'Psr'],
            ],
        ]);

        self::assertSame(['Symfony', 'Psr'], $config->frameworkNamespaces);
    }

    #[Test]
    public function itMergeFrameworkNamespacesOverrides(): void
    {
        $base = new TransitionalRuntimeConfiguration(
            frameworkNamespaces: ['Symfony'],
        );

        $merged = $base->merge([
            'coupling.framework_namespaces' => ['PhpParser', 'Psr'],
        ]);

        self::assertSame(['PhpParser', 'Psr'], $merged->frameworkNamespaces);
    }

    #[Test]
    public function itMergeFrameworkNamespacesPreservesWhenNotInOverrides(): void
    {
        $base = new TransitionalRuntimeConfiguration(
            frameworkNamespaces: ['Symfony', 'Psr'],
        );

        $merged = $base->merge(['format' => 'json']);

        self::assertSame(['Symfony', 'Psr'], $merged->frameworkNamespaces);
    }

    // --- memoryLimit tests ---

    #[Test]
    public function itHasDefaultMemoryLimitAsNull(): void
    {
        $config = new TransitionalRuntimeConfiguration();

        self::assertNull($config->memoryLimit);
    }

    #[Test]
    public function itFromArrayParsesMemoryLimitString(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray(['memory_limit' => '1G']);

        self::assertSame('1G', $config->memoryLimit);
    }

    #[Test]
    public function itFromArrayParsesMemoryLimitWithMegabytes(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray(['memory_limit' => '512M']);

        self::assertSame('512M', $config->memoryLimit);
    }

    #[Test]
    public function itFromArrayParsesMemoryLimitUnlimited(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray(['memory_limit' => '-1']);

        self::assertSame('-1', $config->memoryLimit);
    }

    #[Test]
    public function itFromArrayParsesMemoryLimitInteger(): void
    {
        // YAML without quotes: memory_limit: 134217728
        $config = TransitionalRuntimeConfiguration::fromArray(['memory_limit' => 134217728]);

        self::assertSame('134217728', $config->memoryLimit);
    }

    #[Test]
    public function itFromArrayParsesMemoryLimitLowercaseSuffix(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray(['memory_limit' => '512m']);

        self::assertSame('512m', $config->memoryLimit);
    }

    #[Test]
    public function itFromArrayMemoryLimitNullByDefault(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray([]);

        self::assertNull($config->memoryLimit);
    }

    #[Test]
    public function itFromArrayMemoryLimitInvalidStringThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid value "banana" for "memory_limit"');

        TransitionalRuntimeConfiguration::fromArray(['memory_limit' => 'banana']);
    }

    #[Test]
    public function itFromArrayMemoryLimitInvalidNegativeThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid value "-2" for "memory_limit"');

        TransitionalRuntimeConfiguration::fromArray(['memory_limit' => '-2']);
    }

    #[Test]
    public function itFromArrayMemoryLimitZeroThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid value "0" for "memory_limit"');

        TransitionalRuntimeConfiguration::fromArray(['memory_limit' => '0']);
    }

    #[Test]
    public function itMergeMemoryLimitOverridesWhenPresent(): void
    {
        $base = new TransitionalRuntimeConfiguration(memoryLimit: '512M');

        $merged = $base->merge(['memory_limit' => '1G']);

        self::assertSame('1G', $merged->memoryLimit);
    }

    #[Test]
    public function itMergeMemoryLimitPreservesWhenNotInOverrides(): void
    {
        $base = new TransitionalRuntimeConfiguration(memoryLimit: '1G');

        $merged = $base->merge(['format' => 'json']);

        self::assertSame('1G', $merged->memoryLimit);
    }

    #[Test]
    public function itMergeMemoryLimitPreservesNullWhenNotInOverrides(): void
    {
        $base = new TransitionalRuntimeConfiguration();

        $merged = $base->merge(['format' => 'json']);

        self::assertNull($merged->memoryLimit);
    }

    /** @param array<string, mixed> ...$layers */
    private function resolveFeatureValues(array ...$layers): \Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration
    {
        $pipeline = new ConfigurationPipeline();
        foreach (array_values($layers === [] ? [[]] : $layers) as $priority => $values) {
            $pipeline->addStage(new class ($priority, $values) implements ConfigurationStageInterface {
                /** @param array<string, mixed> $values */
                public function __construct(private int $priority, private array $values) {}
                public function priority(): int
                {
                    return $this->priority;
                }
                public function name(): string
                {
                    return 'test';
                }
                public function apply(ConfigurationContext $context): ConfigurationLayer
                {
                    return new ConfigurationLayer('test', $this->values);
                }
            });
        }

        return $pipeline->resolve(new ConfigurationContext(self::createStub(InputInterface::class), (string) getcwd()));
    }
}
