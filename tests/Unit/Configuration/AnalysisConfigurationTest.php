<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigSchema;
use Qualimetrix\Core\Violation\Severity;

#[CoversClass(AnalysisConfiguration::class)]
final class AnalysisConfigurationTest extends TestCase
{
    #[Test]
    public function itHasDefaultValues(): void
    {
        $config = new AnalysisConfiguration();

        self::assertSame('.qmx-cache', $config->cacheDir);
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
        $config = AnalysisConfiguration::fromArray([]);

        self::assertSame('.qmx-cache', $config->cacheDir);
        self::assertTrue($config->cacheEnabled);
        self::assertSame('summary', $config->format);
    }

    #[Test]
    public function itCreatesFromArrayWithValues(): void
    {
        $config = AnalysisConfiguration::fromArray([
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

        self::assertSame('/tmp/cache', $config->cacheDir);
        self::assertFalse($config->cacheEnabled);
        self::assertSame('json', $config->format);
        self::assertSame('psr4', $config->namespaceStrategy);
        self::assertSame('composer.json', $config->composerJsonPath);
        self::assertSame(['App\\Domain', 'App\\Infrastructure'], $config->aggregationPrefixes);
        self::assertSame(2, $config->aggregationAutoDepth);
        self::assertSame(['complexity.cyclomatic'], $config->disabledRules);
        self::assertSame(['size'], $config->onlyRules);
        self::assertSame(['src/Generated/*', 'src/Legacy/*'], $config->excludePaths);
    }

    #[Test]
    public function itMergesConfigurations(): void
    {
        $base = new AnalysisConfiguration(
            cacheDir: '/original/cache',
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
        self::assertSame('/new/cache', $merged->cacheDir);
        self::assertSame('json', $merged->format);

        // Preserved values
        self::assertTrue($merged->cacheEnabled);
        self::assertSame('chain', $merged->namespaceStrategy);
    }

    #[Test]
    public function itMergeAccumulatesDisabledRules(): void
    {
        $base = new AnalysisConfiguration(
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
        $base = new AnalysisConfiguration(
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
        $base = new AnalysisConfiguration(
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
        $config = AnalysisConfiguration::fromArray([
            'exclude_namespaces' => ['App\\Generated', 'App\\Legacy'],
        ]);

        self::assertSame(['App\\Generated', 'App\\Legacy'], $config->excludeNamespaces);
    }

    #[Test]
    public function itMergeAccumulatesExcludeNamespaces(): void
    {
        $base = new AnalysisConfiguration(
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
        $base = new AnalysisConfiguration(
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
        $base = new AnalysisConfiguration(
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
        $base = new AnalysisConfiguration(
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
        $base = new AnalysisConfiguration(
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
        $base = new AnalysisConfiguration(
            aggregationPrefixes: ['App\\Domain'],
        );

        $merged = $base->merge([
            'format' => 'json',
        ]);

        self::assertSame(['App\\Domain'], $merged->aggregationPrefixes);
    }

    #[Test]
    public function itIsRuleEnabledWithNoRestrictions(): void
    {
        $config = new AnalysisConfiguration();

        self::assertTrue($config->isRuleEnabled('any-rule'));
    }

    #[Test]
    public function itIsRuleEnabledWithDisabledRules(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['disabled-rule'],
        );

        self::assertFalse($config->isRuleEnabled('disabled-rule'));
        self::assertTrue($config->isRuleEnabled('other-rule'));
    }

    #[Test]
    public function itIsRuleEnabledWithOnlyRules(): void
    {
        $config = new AnalysisConfiguration(
            onlyRules: ['allowed-rule'],
        );

        self::assertTrue($config->isRuleEnabled('allowed-rule'));
        self::assertFalse($config->isRuleEnabled('other-rule'));
    }

    #[Test]
    public function itIsRuleEnabledDisabledTakesPrecedence(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['my-rule'],
            onlyRules: ['my-rule'],
        );

        // Even if in onlyRules, disabledRules takes precedence
        self::assertFalse($config->isRuleEnabled('my-rule'));
    }

    #[Test]
    public function itFromArrayRejectsInvalidCacheDir(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('cache.dir');

        AnalysisConfiguration::fromArray([
            'cache' => ['dir' => 123],
        ]);
    }

    #[Test]
    public function itFromArrayRejectsNonBoolCacheEnabled(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('expected boolean');

        AnalysisConfiguration::fromArray([
            'cache' => ['enabled' => 'yes'],
        ]);
    }

    #[Test]
    public function itFromArrayRejectsNonStringFormat(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('format');

        AnalysisConfiguration::fromArray([
            'format' => 123,
        ]);
    }

    #[Test]
    public function itFromArrayRejectsInvalidNamespaceStrategy(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Allowed values: chain, psr4, tokenizer');

        AnalysisConfiguration::fromArray([
            'namespace' => ['strategy' => 'invalid'],
        ]);
    }

    #[Test]
    public function itFromArrayRejectsNegativeWorkers(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must be non-negative');

        AnalysisConfiguration::fromArray([
            'parallel' => ['workers' => -5],
        ]);
    }

    #[Test]
    public function itFromArrayRejectsZeroAutoDepth(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must be positive');

        AnalysisConfiguration::fromArray([
            'aggregation' => ['auto_depth' => 0],
        ]);
    }

    #[Test]
    public function itFromArrayRejectsNonArrayPrefixes(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('expected array');

        AnalysisConfiguration::fromArray([
            'aggregation' => ['prefixes' => 'string'],
        ]);
    }

    #[Test]
    public function itFromArrayAcceptsAbsentKeysWithDefaults(): void
    {
        $config = AnalysisConfiguration::fromArray([]);

        self::assertSame('.qmx-cache', $config->cacheDir);
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
        $config = AnalysisConfiguration::fromArray([
            'parallel' => ['workers' => null],
        ]);

        self::assertNull($config->workers);
    }

    #[Test]
    public function itFromArrayTreatsExplicitNullAsDefault(): void
    {
        // YAML `format: ~` or `format:` (no value) parses as null
        $config = AnalysisConfiguration::fromArray([
            'format' => null,
            'cache' => ['enabled' => null, 'dir' => null],
            ConfigSchema::DISABLED_RULES => null,
        ]);

        self::assertSame('summary', $config->format);
        self::assertTrue($config->cacheEnabled);
        self::assertSame('.qmx-cache', $config->cacheDir);
        self::assertSame([], $config->disabledRules);
    }

    #[Test]
    public function itFromArrayRejectsNonStringListElements(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('expected string, got int');

        AnalysisConfiguration::fromArray([
            ConfigSchema::EXCLUDE_PATHS => ['src/*', 123],
        ]);
    }

    // --- Prefix matching tests ---

    #[Test]
    public function itIsRuleEnabledPrefixMatchDisablesGroup(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['complexity'],
        );

        // Prefix 'complexity' disables all rules starting with 'complexity.'
        self::assertFalse($config->isRuleEnabled('complexity'));
        self::assertFalse($config->isRuleEnabled('complexity.cyclomatic'));
        self::assertFalse($config->isRuleEnabled('complexity.cognitive'));
        self::assertFalse($config->isRuleEnabled('complexity.npath'));

        // Other groups unaffected
        self::assertTrue($config->isRuleEnabled('size.method-count'));
    }

    #[Test]
    public function itIsRuleEnabledPrefixMatchDisablesSpecificRule(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['complexity.cyclomatic'],
        );

        // Only 'complexity.cyclomatic' and its sub-codes disabled
        self::assertFalse($config->isRuleEnabled('complexity.cyclomatic'));
        self::assertTrue($config->isRuleEnabled('complexity.cognitive'));
        self::assertTrue($config->isRuleEnabled('complexity'));
    }

    #[Test]
    public function itIsRuleEnabledOnlyRulesPrefixMatch(): void
    {
        $config = new AnalysisConfiguration(
            onlyRules: ['complexity'],
        );

        // Prefix match: 'complexity' enables all rules starting with 'complexity.'
        self::assertTrue($config->isRuleEnabled('complexity'));
        self::assertTrue($config->isRuleEnabled('complexity.cyclomatic'));
        self::assertTrue($config->isRuleEnabled('complexity.cognitive'));

        // Other groups disabled
        self::assertFalse($config->isRuleEnabled('size.method-count'));
    }

    #[Test]
    public function itIsRuleEnabledOnlyRulesSpecificRule(): void
    {
        $config = new AnalysisConfiguration(
            onlyRules: ['complexity.cyclomatic'],
        );

        self::assertTrue($config->isRuleEnabled('complexity.cyclomatic'));
        self::assertFalse($config->isRuleEnabled('complexity.cognitive'));
        // Parent group is enabled (rule 'complexity' needs to run so violations can be filtered)
        self::assertTrue($config->isRuleEnabled('complexity'));
    }

    #[Test]
    public function itIsRuleEnabledOnlyRulesMultiplePatterns(): void
    {
        $config = new AnalysisConfiguration(
            onlyRules: ['complexity', 'size.method-count'],
        );

        self::assertTrue($config->isRuleEnabled('complexity.cyclomatic'));
        self::assertTrue($config->isRuleEnabled('complexity.cognitive'));
        self::assertTrue($config->isRuleEnabled('size.method-count'));
        self::assertFalse($config->isRuleEnabled('size.class-count'));
        self::assertFalse($config->isRuleEnabled('design.lcom'));
    }

    // --- isViolationCodeEnabled tests ---

    #[Test]
    public function itIsViolationCodeEnabledNoRestrictions(): void
    {
        $config = new AnalysisConfiguration();

        self::assertTrue($config->isViolationCodeEnabled('complexity.cyclomatic.method'));
    }

    #[Test]
    public function itIsViolationCodeEnabledDisabledGroup(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['complexity'],
        );

        self::assertFalse($config->isViolationCodeEnabled('complexity.cyclomatic.method'));
        self::assertFalse($config->isViolationCodeEnabled('complexity.cyclomatic.class'));
        self::assertTrue($config->isViolationCodeEnabled('size.method-count'));
    }

    #[Test]
    public function itIsViolationCodeEnabledDisabledSpecificCode(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['complexity.cyclomatic.class'],
        );

        self::assertFalse($config->isViolationCodeEnabled('complexity.cyclomatic.class'));
        self::assertTrue($config->isViolationCodeEnabled('complexity.cyclomatic.method'));
    }

    #[Test]
    public function itIsViolationCodeEnabledOnlyRules(): void
    {
        $config = new AnalysisConfiguration(
            onlyRules: ['complexity.cyclomatic'],
        );

        self::assertTrue($config->isViolationCodeEnabled('complexity.cyclomatic.method'));
        self::assertTrue($config->isViolationCodeEnabled('complexity.cyclomatic.class'));
        self::assertFalse($config->isViolationCodeEnabled('complexity.cognitive.method'));
    }

    #[Test]
    public function itIsViolationCodeEnabledDisabledTakesPrecedence(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['complexity.cyclomatic.class'],
            onlyRules: ['complexity'],
        );

        self::assertTrue($config->isViolationCodeEnabled('complexity.cyclomatic.method'));
        self::assertFalse($config->isViolationCodeEnabled('complexity.cyclomatic.class'));
    }

    // --- failOn tests ---

    #[Test]
    public function itFromArrayParsesFailOnWarning(): void
    {
        $config = AnalysisConfiguration::fromArray(['fail_on' => 'warning']);

        self::assertSame(Severity::Warning, $config->failOn);
    }

    #[Test]
    public function itFromArrayParsesFailOnError(): void
    {
        $config = AnalysisConfiguration::fromArray(['fail_on' => 'error']);

        self::assertSame(Severity::Error, $config->failOn);
    }

    #[Test]
    public function itFromArrayParsesFailOnInfo(): void
    {
        $config = AnalysisConfiguration::fromArray(['fail_on' => 'info']);

        self::assertSame(Severity::Info, $config->failOn);
    }

    #[Test]
    public function itFromArrayFailOnNullByDefault(): void
    {
        $config = AnalysisConfiguration::fromArray([]);

        self::assertNull($config->failOn);
    }

    #[Test]
    public function itFromArrayFailOnInvalidStringThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid value "invalid" for "fail_on"');

        AnalysisConfiguration::fromArray(['fail_on' => 'invalid']);
    }

    #[Test]
    public function itFromArrayFailOnSeverityEnum(): void
    {
        $config = AnalysisConfiguration::fromArray(['fail_on' => Severity::Error]);

        self::assertSame(Severity::Error, $config->failOn);
    }

    #[Test]
    public function itMergeFailOnOverridesWhenPresent(): void
    {
        $base = new AnalysisConfiguration(failOn: Severity::Warning);

        $merged = $base->merge(['fail_on' => 'error']);

        self::assertSame(Severity::Error, $merged->failOn);
    }

    #[Test]
    public function itMergeFailOnPreservesWhenNotInOverrides(): void
    {
        $base = new AnalysisConfiguration(failOn: Severity::Error);

        $merged = $base->merge(['format' => 'json']);

        self::assertSame(Severity::Error, $merged->failOn);
    }

    #[Test]
    public function itMergeFailOnPreservesNullWhenNotInOverrides(): void
    {
        $base = new AnalysisConfiguration();

        $merged = $base->merge(['format' => 'json']);

        self::assertNull($merged->failOn);
    }

    #[Test]
    public function itFromArrayParsesFailOnNone(): void
    {
        $config = AnalysisConfiguration::fromArray(['fail_on' => 'none']);

        self::assertFalse($config->failOn);
    }

    #[Test]
    public function itMergeFailOnNoneOverridesWhenPresent(): void
    {
        $base = new AnalysisConfiguration(failOn: Severity::Warning);

        $merged = $base->merge(['fail_on' => 'none']);

        self::assertFalse($merged->failOn);
    }

    #[Test]
    public function itMergeFailOnNonePreservedWhenNotOverridden(): void
    {
        $base = new AnalysisConfiguration(failOn: false);

        $merged = $base->merge(['format' => 'json']);

        self::assertFalse($merged->failOn);
    }

    // Framework namespaces tests

    #[Test]
    public function itHasDefaultFrameworkNamespacesEmpty(): void
    {
        $config = new AnalysisConfiguration();

        self::assertSame([], $config->frameworkNamespaces);
    }

    #[Test]
    public function itFromArrayParsesFrameworkNamespaces(): void
    {
        $config = AnalysisConfiguration::fromArray([
            'coupling.framework_namespaces' => ['Symfony', 'PhpParser', 'Psr'],
        ]);

        self::assertSame(['Symfony', 'PhpParser', 'Psr'], $config->frameworkNamespaces);
    }

    #[Test]
    public function itFromArrayParsesNestedFrameworkNamespaces(): void
    {
        $config = AnalysisConfiguration::fromArray([
            'coupling' => [
                'framework_namespaces' => ['Symfony', 'Psr'],
            ],
        ]);

        self::assertSame(['Symfony', 'Psr'], $config->frameworkNamespaces);
    }

    #[Test]
    public function itMergeFrameworkNamespacesOverrides(): void
    {
        $base = new AnalysisConfiguration(
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
        $base = new AnalysisConfiguration(
            frameworkNamespaces: ['Symfony', 'Psr'],
        );

        $merged = $base->merge(['format' => 'json']);

        self::assertSame(['Symfony', 'Psr'], $merged->frameworkNamespaces);
    }

    // --- memoryLimit tests ---

    #[Test]
    public function itHasDefaultMemoryLimitAsNull(): void
    {
        $config = new AnalysisConfiguration();

        self::assertNull($config->memoryLimit);
    }

    #[Test]
    public function itFromArrayParsesMemoryLimitString(): void
    {
        $config = AnalysisConfiguration::fromArray(['memory_limit' => '1G']);

        self::assertSame('1G', $config->memoryLimit);
    }

    #[Test]
    public function itFromArrayParsesMemoryLimitWithMegabytes(): void
    {
        $config = AnalysisConfiguration::fromArray(['memory_limit' => '512M']);

        self::assertSame('512M', $config->memoryLimit);
    }

    #[Test]
    public function itFromArrayParsesMemoryLimitUnlimited(): void
    {
        $config = AnalysisConfiguration::fromArray(['memory_limit' => '-1']);

        self::assertSame('-1', $config->memoryLimit);
    }

    #[Test]
    public function itFromArrayParsesMemoryLimitInteger(): void
    {
        // YAML without quotes: memory_limit: 134217728
        $config = AnalysisConfiguration::fromArray(['memory_limit' => 134217728]);

        self::assertSame('134217728', $config->memoryLimit);
    }

    #[Test]
    public function itFromArrayParsesMemoryLimitLowercaseSuffix(): void
    {
        $config = AnalysisConfiguration::fromArray(['memory_limit' => '512m']);

        self::assertSame('512m', $config->memoryLimit);
    }

    #[Test]
    public function itFromArrayMemoryLimitNullByDefault(): void
    {
        $config = AnalysisConfiguration::fromArray([]);

        self::assertNull($config->memoryLimit);
    }

    #[Test]
    public function itFromArrayMemoryLimitInvalidStringThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid value "banana" for "memory_limit"');

        AnalysisConfiguration::fromArray(['memory_limit' => 'banana']);
    }

    #[Test]
    public function itFromArrayMemoryLimitInvalidNegativeThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid value "-2" for "memory_limit"');

        AnalysisConfiguration::fromArray(['memory_limit' => '-2']);
    }

    #[Test]
    public function itFromArrayMemoryLimitZeroThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid value "0" for "memory_limit"');

        AnalysisConfiguration::fromArray(['memory_limit' => '0']);
    }

    #[Test]
    public function itMergeMemoryLimitOverridesWhenPresent(): void
    {
        $base = new AnalysisConfiguration(memoryLimit: '512M');

        $merged = $base->merge(['memory_limit' => '1G']);

        self::assertSame('1G', $merged->memoryLimit);
    }

    #[Test]
    public function itMergeMemoryLimitPreservesWhenNotInOverrides(): void
    {
        $base = new AnalysisConfiguration(memoryLimit: '1G');

        $merged = $base->merge(['format' => 'json']);

        self::assertSame('1G', $merged->memoryLimit);
    }

    #[Test]
    public function itMergeMemoryLimitPreservesNullWhenNotInOverrides(): void
    {
        $base = new AnalysisConfiguration();

        $merged = $base->merge(['format' => 'json']);

        self::assertNull($merged->memoryLimit);
    }
}
