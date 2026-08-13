<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Violation\Severity;

#[CoversClass(TransitionalRuntimeConfiguration::class)]
final class AnalysisConfigurationTest extends TestCase
{
    #[Test]
    public function itHasDefaultValues(): void
    {
        $config = new TransitionalRuntimeConfiguration();

        self::assertSame((string) getcwd() . '/.qmx-cache', $config->cacheDir->value());
        self::assertTrue($config->cacheEnabled);
        self::assertSame('summary', $config->format);
        self::assertSame('chain', $config->namespaceStrategy);
        self::assertNull($config->composerJsonPath);
        self::assertSame([], $config->aggregationPrefixes);
        self::assertNull($config->aggregationAutoDepth);
        self::assertSame([], $config->disabledRules);
        self::assertSame([], $config->onlyRules);
        self::assertSame([], $config->excludePaths);
        self::assertSame([], $config->excludeNamespaces);
        self::assertNull($config->failOn);
    }

    #[Test]
    public function itCreatesFromArrayWithDefaults(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray([]);

        self::assertSame((string) getcwd() . '/.qmx-cache', $config->cacheDir->value());
        self::assertTrue($config->cacheEnabled);
        self::assertSame('summary', $config->format);
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

        self::assertSame('/tmp/cache', $config->cacheDir->value());
        self::assertFalse($config->cacheEnabled);
        self::assertSame('json', $config->format);
        self::assertSame('psr4', $config->namespaceStrategy);
        self::assertNotNull($config->composerJsonPath);
        self::assertSame((string) getcwd() . '/composer.json', $config->composerJsonPath->value());
        self::assertSame(['App\\Domain', 'App\\Infrastructure'], $config->aggregationPrefixes);
        self::assertSame(2, $config->aggregationAutoDepth);
        self::assertSame(['complexity.cyclomatic'], $config->disabledRules);
        self::assertSame(['size'], $config->onlyRules);
        self::assertSame(['src/Generated/*', 'src/Legacy/*'], $config->excludePaths);
    }

    #[Test]
    public function itMergesConfigurations(): void
    {
        $base = new TransitionalRuntimeConfiguration(
            cacheDir: AbsolutePath::fromString('/original/cache'),
            cacheEnabled: true,
            format: 'text',
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
        self::assertSame('json', $merged->format);

        // Preserved values
        self::assertTrue($merged->cacheEnabled);
        self::assertSame('chain', $merged->namespaceStrategy);
    }

    #[Test]
    public function itMergeAccumulatesDisabledRules(): void
    {
        $base = new TransitionalRuntimeConfiguration(
            disabledRules: ['rule-a'],
        );

        $merged = $base->merge([
            'disabled_rules' => ['rule-b', 'rule-c'],
        ]);

        self::assertContains('rule-a', $merged->disabledRules);
        self::assertContains('rule-b', $merged->disabledRules);
        self::assertContains('rule-c', $merged->disabledRules);
    }

    #[Test]
    public function itMergeAccumulatesExcludePaths(): void
    {
        $base = new TransitionalRuntimeConfiguration(
            excludePaths: ['src/Generated/*'],
        );

        $merged = $base->merge([
            'exclude_paths' => ['src/Legacy/*', 'src/Vendor/*'],
        ]);

        self::assertSame(['src/Generated/*', 'src/Legacy/*', 'src/Vendor/*'], $merged->excludePaths);
    }

    #[Test]
    public function itMergeExcludePathsDeduplicates(): void
    {
        $base = new TransitionalRuntimeConfiguration(
            excludePaths: ['src/Generated/*'],
        );

        $merged = $base->merge([
            'exclude_paths' => ['src/Generated/*', 'src/Legacy/*'],
        ]);

        self::assertSame(['src/Generated/*', 'src/Legacy/*'], $merged->excludePaths);
    }

    #[Test]
    public function itFromArrayParsesExcludeNamespaces(): void
    {
        $config = TransitionalRuntimeConfiguration::fromArray([
            'exclude_namespaces' => ['App\\Generated', 'App\\Legacy'],
        ]);

        self::assertSame(['App\\Generated', 'App\\Legacy'], $config->excludeNamespaces);
    }

    #[Test]
    public function itMergeAccumulatesExcludeNamespaces(): void
    {
        $base = new TransitionalRuntimeConfiguration(
            excludeNamespaces: ['App\\Generated'],
        );

        $merged = $base->merge([
            'exclude_namespaces' => ['App\\Legacy', 'App\\Vendor'],
        ]);

        self::assertSame(['App\\Generated', 'App\\Legacy', 'App\\Vendor'], $merged->excludeNamespaces);
    }

    #[Test]
    public function itMergeExcludeNamespacesDeduplicates(): void
    {
        $base = new TransitionalRuntimeConfiguration(
            excludeNamespaces: ['App\\Generated'],
        );

        $merged = $base->merge([
            'exclude_namespaces' => ['App\\Generated', 'App\\Legacy'],
        ]);

        self::assertSame(['App\\Generated', 'App\\Legacy'], $merged->excludeNamespaces);
    }

    #[Test]
    public function itMergeEmptyOnlyRulesResetsToEmpty(): void
    {
        $base = new TransitionalRuntimeConfiguration(
            onlyRules: ['complexity', 'size'],
        );

        $merged = $base->merge([
            'only_rules' => [],
        ]);

        self::assertSame([], $merged->onlyRules);
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
        $base = new TransitionalRuntimeConfiguration(
            onlyRules: ['complexity'],
        );

        $merged = $base->merge([
            'format' => 'json',
        ]);

        self::assertSame(['complexity'], $merged->onlyRules);
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

        TransitionalRuntimeConfiguration::fromArray([
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

        self::assertSame((string) getcwd() . '/.qmx-cache', $config->cacheDir->value());
        self::assertTrue($config->cacheEnabled);
        self::assertSame('summary', $config->format);
        self::assertSame('chain', $config->namespaceStrategy);
        self::assertNull($config->composerJsonPath);
        self::assertSame([], $config->aggregationPrefixes);
        self::assertNull($config->aggregationAutoDepth);
        self::assertSame([], $config->disabledRules);
        self::assertSame([], $config->onlyRules);
        self::assertSame([], $config->excludePaths);
        self::assertSame([], $config->excludeNamespaces);
        self::assertNull($config->workers);
        self::assertNull($config->failOn);
        self::assertSame([], $config->excludeHealth);
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

        self::assertSame('summary', $config->format);
        self::assertTrue($config->cacheEnabled);
        self::assertSame((string) getcwd() . '/.qmx-cache', $config->cacheDir->value());
        self::assertSame([], $config->disabledRules);
    }

    #[Test]
    public function itFromArrayRejectsNonStringListElements(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('expected string, got int');

        TransitionalRuntimeConfiguration::fromArray([
            ConfigSchema::EXCLUDE_PATHS => ['src/*', 123],
        ]);
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
}
