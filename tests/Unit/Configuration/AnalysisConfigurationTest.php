<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Configuration;

use AiMessDetector\Configuration\AnalysisConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnalysisConfiguration::class)]
final class AnalysisConfigurationTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new AnalysisConfiguration();

        self::assertSame('.aimd-cache', $config->cacheDir);
        self::assertTrue($config->cacheEnabled);
        self::assertSame('text', $config->format);
        self::assertSame('chain', $config->namespaceStrategy);
        self::assertNull($config->composerJsonPath);
        self::assertSame([], $config->aggregationPrefixes);
        self::assertNull($config->aggregationAutoDepth);
        self::assertSame([], $config->disabledRules);
        self::assertSame([], $config->onlyRules);
        self::assertSame([], $config->excludePaths);
    }

    public function testFromArrayWithDefaults(): void
    {
        $config = AnalysisConfiguration::fromArray([]);

        self::assertSame('.aimd-cache', $config->cacheDir);
        self::assertTrue($config->cacheEnabled);
        self::assertSame('text', $config->format);
    }

    public function testFromArrayWithValues(): void
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

    public function testMerge(): void
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

    public function testMergeAccumulatesDisabledRules(): void
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

    public function testMergeAccumulatesExcludePaths(): void
    {
        $base = new AnalysisConfiguration(
            excludePaths: ['src/Generated/*'],
        );

        $merged = $base->merge([
            'exclude_paths' => ['src/Legacy/*', 'src/Vendor/*'],
        ]);

        self::assertSame(['src/Generated/*', 'src/Legacy/*', 'src/Vendor/*'], $merged->excludePaths);
    }

    public function testMergeExcludePathsDeduplicates(): void
    {
        $base = new AnalysisConfiguration(
            excludePaths: ['src/Generated/*'],
        );

        $merged = $base->merge([
            'exclude_paths' => ['src/Generated/*', 'src/Legacy/*'],
        ]);

        self::assertSame(['src/Generated/*', 'src/Legacy/*'], $merged->excludePaths);
    }

    public function testIsRuleEnabledWithNoRestrictions(): void
    {
        $config = new AnalysisConfiguration();

        self::assertTrue($config->isRuleEnabled('any-rule'));
    }

    public function testIsRuleEnabledWithDisabledRules(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['disabled-rule'],
        );

        self::assertFalse($config->isRuleEnabled('disabled-rule'));
        self::assertTrue($config->isRuleEnabled('other-rule'));
    }

    public function testIsRuleEnabledWithOnlyRules(): void
    {
        $config = new AnalysisConfiguration(
            onlyRules: ['allowed-rule'],
        );

        self::assertTrue($config->isRuleEnabled('allowed-rule'));
        self::assertFalse($config->isRuleEnabled('other-rule'));
    }

    public function testIsRuleEnabledDisabledTakesPrecedence(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['my-rule'],
            onlyRules: ['my-rule'],
        );

        // Even if in onlyRules, disabledRules takes precedence
        self::assertFalse($config->isRuleEnabled('my-rule'));
    }

    public function testFromArrayIgnoresInvalidTypes(): void
    {
        $config = AnalysisConfiguration::fromArray([
            'cache' => [
                'dir' => 123, // Invalid: should be string
                'enabled' => 'yes', // Invalid: should be bool
            ],
            'aggregation' => [
                'prefixes' => 'not-an-array', // Invalid: should be array
            ],
        ]);

        // Falls back to defaults
        self::assertSame('.aimd-cache', $config->cacheDir);
        self::assertTrue($config->cacheEnabled);
        self::assertSame([], $config->aggregationPrefixes);
    }

    // --- Prefix matching tests ---

    public function testIsRuleEnabledPrefixMatchDisablesGroup(): void
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

    public function testIsRuleEnabledPrefixMatchDisablesSpecificRule(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['complexity.cyclomatic'],
        );

        // Only 'complexity.cyclomatic' and its sub-codes disabled
        self::assertFalse($config->isRuleEnabled('complexity.cyclomatic'));
        self::assertTrue($config->isRuleEnabled('complexity.cognitive'));
        self::assertTrue($config->isRuleEnabled('complexity'));
    }

    public function testIsRuleEnabledOnlyRulesPrefixMatch(): void
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

    public function testIsRuleEnabledOnlyRulesSpecificRule(): void
    {
        $config = new AnalysisConfiguration(
            onlyRules: ['complexity.cyclomatic'],
        );

        self::assertTrue($config->isRuleEnabled('complexity.cyclomatic'));
        self::assertFalse($config->isRuleEnabled('complexity.cognitive'));
        // Parent group is enabled (rule 'complexity' needs to run so violations can be filtered)
        self::assertTrue($config->isRuleEnabled('complexity'));
    }

    public function testIsRuleEnabledOnlyRulesMultiplePatterns(): void
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

    public function testIsViolationCodeEnabledNoRestrictions(): void
    {
        $config = new AnalysisConfiguration();

        self::assertTrue($config->isViolationCodeEnabled('complexity.cyclomatic.method'));
    }

    public function testIsViolationCodeEnabledDisabledGroup(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['complexity'],
        );

        self::assertFalse($config->isViolationCodeEnabled('complexity.cyclomatic.method'));
        self::assertFalse($config->isViolationCodeEnabled('complexity.cyclomatic.class'));
        self::assertTrue($config->isViolationCodeEnabled('size.method-count'));
    }

    public function testIsViolationCodeEnabledDisabledSpecificCode(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['complexity.cyclomatic.class'],
        );

        self::assertFalse($config->isViolationCodeEnabled('complexity.cyclomatic.class'));
        self::assertTrue($config->isViolationCodeEnabled('complexity.cyclomatic.method'));
    }

    public function testIsViolationCodeEnabledOnlyRules(): void
    {
        $config = new AnalysisConfiguration(
            onlyRules: ['complexity.cyclomatic'],
        );

        self::assertTrue($config->isViolationCodeEnabled('complexity.cyclomatic.method'));
        self::assertTrue($config->isViolationCodeEnabled('complexity.cyclomatic.class'));
        self::assertFalse($config->isViolationCodeEnabled('complexity.cognitive.method'));
    }

    public function testIsViolationCodeEnabledDisabledTakesPrecedence(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['complexity.cyclomatic.class'],
            onlyRules: ['complexity'],
        );

        self::assertTrue($config->isViolationCodeEnabled('complexity.cyclomatic.method'));
        self::assertFalse($config->isViolationCodeEnabled('complexity.cyclomatic.class'));
    }
}
