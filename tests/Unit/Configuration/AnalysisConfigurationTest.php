<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Configuration;

use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Core\Rule\RuleLevel;
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
            'disabled_rules' => ['cyclomatic-complexity'],
            'only_rules' => ['namespace-size'],
        ]);

        self::assertSame('/tmp/cache', $config->cacheDir);
        self::assertFalse($config->cacheEnabled);
        self::assertSame('json', $config->format);
        self::assertSame('psr4', $config->namespaceStrategy);
        self::assertSame('composer.json', $config->composerJsonPath);
        self::assertSame(['App\\Domain', 'App\\Infrastructure'], $config->aggregationPrefixes);
        self::assertSame(2, $config->aggregationAutoDepth);
        self::assertSame(['cyclomatic-complexity'], $config->disabledRules);
        self::assertSame(['namespace-size'], $config->onlyRules);
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

    // --- isRuleLevelEnabled tests ---

    public function testIsRuleLevelEnabledWithNoRestrictions(): void
    {
        $config = new AnalysisConfiguration();

        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Method));
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Class_));
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Namespace_));
    }

    public function testIsRuleLevelEnabledDisableEntireRule(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['complexity'],
        );

        // All levels disabled when rule name is in disabledRules
        self::assertFalse($config->isRuleLevelEnabled('complexity', RuleLevel::Method));
        self::assertFalse($config->isRuleLevelEnabled('complexity', RuleLevel::Class_));
        self::assertFalse($config->isRuleLevelEnabled('complexity', RuleLevel::Namespace_));

        // Other rules are not affected
        self::assertTrue($config->isRuleLevelEnabled('size', RuleLevel::Class_));
    }

    public function testIsRuleLevelEnabledDisableSpecificLevel(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['complexity.class'],
        );

        // Only class level disabled
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Method));
        self::assertFalse($config->isRuleLevelEnabled('complexity', RuleLevel::Class_));
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Namespace_));
    }

    public function testIsRuleLevelEnabledDisableMixed(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['complexity.class', 'size'],
        );

        // complexity: only class disabled
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Method));
        self::assertFalse($config->isRuleLevelEnabled('complexity', RuleLevel::Class_));

        // size: all disabled
        self::assertFalse($config->isRuleLevelEnabled('size', RuleLevel::Method));
        self::assertFalse($config->isRuleLevelEnabled('size', RuleLevel::Class_));
        self::assertFalse($config->isRuleLevelEnabled('size', RuleLevel::Namespace_));
    }

    public function testIsRuleLevelEnabledOnlyRuleEnablesAllLevels(): void
    {
        $config = new AnalysisConfiguration(
            onlyRules: ['complexity'],
        );

        // All levels enabled for complexity
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Method));
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Class_));
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Namespace_));

        // Other rules are disabled
        self::assertFalse($config->isRuleLevelEnabled('size', RuleLevel::Method));
    }

    public function testIsRuleLevelEnabledOnlySpecificLevel(): void
    {
        $config = new AnalysisConfiguration(
            onlyRules: ['complexity.method'],
        );

        // Only method level enabled
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Method));
        self::assertFalse($config->isRuleLevelEnabled('complexity', RuleLevel::Class_));
        self::assertFalse($config->isRuleLevelEnabled('complexity', RuleLevel::Namespace_));

        // Other rules still disabled
        self::assertFalse($config->isRuleLevelEnabled('size', RuleLevel::Method));
    }

    public function testIsRuleLevelEnabledOnlyMultipleLevels(): void
    {
        $config = new AnalysisConfiguration(
            onlyRules: ['complexity.method', 'complexity.class', 'size.namespace'],
        );

        // complexity: method and class enabled, namespace disabled
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Method));
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Class_));
        self::assertFalse($config->isRuleLevelEnabled('complexity', RuleLevel::Namespace_));

        // size: only namespace enabled
        self::assertFalse($config->isRuleLevelEnabled('size', RuleLevel::Method));
        self::assertFalse($config->isRuleLevelEnabled('size', RuleLevel::Class_));
        self::assertTrue($config->isRuleLevelEnabled('size', RuleLevel::Namespace_));
    }

    public function testIsRuleLevelEnabledOnlyAndDisableCombined(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: ['complexity.class'],
            onlyRules: ['complexity'],
        );

        // complexity enabled, but class level disabled
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Method));
        self::assertFalse($config->isRuleLevelEnabled('complexity', RuleLevel::Class_));
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Namespace_));
    }

    public function testIsRuleLevelEnabledOnlyRuleAndOnlyLevelMixed(): void
    {
        $config = new AnalysisConfiguration(
            onlyRules: ['complexity', 'size.namespace'],
        );

        // complexity: all levels enabled (no specific level specified)
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Method));
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Class_));
        self::assertTrue($config->isRuleLevelEnabled('complexity', RuleLevel::Namespace_));

        // size: only namespace specified, so only namespace enabled
        self::assertFalse($config->isRuleLevelEnabled('size', RuleLevel::Method));
        self::assertFalse($config->isRuleLevelEnabled('size', RuleLevel::Class_));
        self::assertTrue($config->isRuleLevelEnabled('size', RuleLevel::Namespace_));
    }
}
