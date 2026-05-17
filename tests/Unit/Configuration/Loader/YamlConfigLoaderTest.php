<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Loader;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;

#[CoversClass(YamlConfigLoader::class)]
final class YamlConfigLoaderTest extends TestCase
{
    private YamlConfigLoader $loader;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->loader = new YamlConfigLoader();
        $this->tempDir = sys_get_temp_dir() . '/qmx_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function itSupportsYamlExtension(): void
    {
        self::assertTrue($this->loader->supports('/path/to/config.yaml'));
        self::assertTrue($this->loader->supports('/path/to/config.yml'));
        self::assertTrue($this->loader->supports('/path/to/config.YAML'));
        self::assertTrue($this->loader->supports('/path/to/config.YML'));
    }

    #[Test]
    public function itDoesNotSupportOtherExtensions(): void
    {
        self::assertFalse($this->loader->supports('/path/to/config.php'));
        self::assertFalse($this->loader->supports('/path/to/config.json'));
        self::assertFalse($this->loader->supports('/path/to/config.xml'));
    }

    #[Test]
    public function itLoadsValidYaml(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  cyclomatic-complexity:
    enabled: true
    warning_threshold: 10
    error_threshold: 20

cache:
  enabled: true
  dir: .qmx-cache

format: text
YAML);

        $config = $this->loader->load($path);

        self::assertArrayHasKey('rules', $config);
        // Rule name keys are preserved as-is (not normalized)
        self::assertArrayHasKey('cyclomatic-complexity', $config['rules']);
        self::assertTrue($config['rules']['cyclomatic-complexity']['enabled']);
        // But option keys within rules ARE normalized
        self::assertSame(10, $config['rules']['cyclomatic-complexity']['warningThreshold']);
        self::assertSame(20, $config['rules']['cyclomatic-complexity']['errorThreshold']);
        self::assertTrue($config['cache']['enabled']);
        self::assertSame('.qmx-cache', $config['cache']['dir']);
        self::assertSame('text', $config['format']);
    }

    #[Test]
    public function itNormalizesSnakeCaseToCamelCase(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  namespace_size:
    warning_threshold: 10
    count_interfaces: true
    count_traits: false
YAML);

        $config = $this->loader->load($path);

        self::assertArrayHasKey('rules', $config);
        // Rule name keys are preserved as-is
        self::assertArrayHasKey('namespace_size', $config['rules']);
        // But option keys within rules ARE normalized to camelCase
        self::assertSame(10, $config['rules']['namespace_size']['warningThreshold']);
        self::assertTrue($config['rules']['namespace_size']['countInterfaces']);
        self::assertFalse($config['rules']['namespace_size']['countTraits']);
    }

    #[Test]
    public function itLoadsEmptyFile(): void
    {
        $path = $this->tempDir . '/empty.yaml';
        file_put_contents($path, '');

        $config = $this->loader->load($path);

        self::assertSame([], $config);
    }

    #[Test]
    public function itThrowsWhenFileNotFound(): void
    {
        $path = $this->tempDir . '/nonexistent.yaml';

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('Configuration file not found');

        $this->loader->load($path);
    }

    #[Test]
    public function itThrowsForInvalidYaml(): void
    {
        $path = $this->tempDir . '/invalid.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  - this: is
    invalid: yaml:
      syntax: [
YAML);

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('Failed to parse configuration file');

        $this->loader->load($path);
    }

    #[Test]
    public function itThrowsForScalarValue(): void
    {
        $path = $this->tempDir . '/scalar.yaml';
        file_put_contents($path, 'just a string');

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('is not valid YAML format');

        $this->loader->load($path);
    }

    #[Test]
    public function itPreservesCamelCaseKeys(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  cyclomaticComplexity:
    warningThreshold: 15
YAML);

        $config = $this->loader->load($path);

        // Rule name keys are preserved exactly as written
        self::assertArrayHasKey('cyclomaticComplexity', $config['rules']);
        self::assertSame(15, $config['rules']['cyclomaticComplexity']['warningThreshold']);
    }

    #[Test]
    public function itRejectsUnknownRootKeys(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity:
    enabled: true
unknown_key: some_value
another_bad_key: true
YAML);

        self::expectException(ConfigLoadException::class);
        // Error message should show original key names (snake_case), not camelCase
        self::expectExceptionMessage('"unknown_key", "another_bad_key"');

        $this->loader->load($path);
    }

    #[Test]
    public function itRejectsNonArrayRules(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'rules: not_an_array');

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('"rules" must be an associative array');

        $this->loader->load($path);
    }

    #[Test]
    public function itRejectsInvalidRuleConfig(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity: "invalid string value"
YAML);

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('Rule "complexity" configuration must be an array, boolean, or null');

        $this->loader->load($path);
    }

    #[Test]
    public function itAcceptsBooleanRuleConfig(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity: true
  size: false
YAML);

        $config = $this->loader->load($path);

        self::assertTrue($config['rules']['complexity']);
        self::assertFalse($config['rules']['size']);
    }

    #[Test]
    public function itAcceptsNullRuleConfig(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity: ~
YAML);

        $config = $this->loader->load($path);

        self::assertNull($config['rules']['complexity']);
    }

    #[Test]
    public function itRejectsNonArrayCache(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'cache: not_an_array');

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('"cache" must be an associative array');

        $this->loader->load($path);
    }

    #[Test]
    public function itRejectsNonArrayNamespace(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'namespace: not_an_array');

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('"namespace" must be an associative array');

        $this->loader->load($path);
    }

    #[Test]
    public function itRejectsNonListDisabledRules(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'disabled_rules: not_a_list');

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('"disabled_rules" must be a list');

        $this->loader->load($path);
    }

    #[Test]
    public function itAcceptsAllValidRootKeys(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity:
    enabled: true
cache:
  enabled: true
format: json
namespace:
  strategy: psr4
aggregation:
  prefixes:
    - App
disabled_rules:
  - size
only_rules:
  - complexity
paths:
  - src
exclude:
  - vendor
exclude_paths:
  - src/Entity/*
YAML);

        $config = $this->loader->load($path);

        self::assertArrayHasKey('rules', $config);
        self::assertArrayHasKey('cache', $config);
        self::assertSame('json', $config['format']);
        self::assertSame(['src/Entity/*'], $config['excludePaths']);
    }

    #[Test]
    public function itRejectsNonListExcludePaths(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'exclude_paths: not_a_list');

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('"exclude_paths" must be a list');

        $this->loader->load($path);
    }

    #[Test]
    public function itPreservesDottedKebabCaseRuleNames(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  size.method-count:
    warning_threshold: 15
    error_threshold: 30
YAML);

        $config = $this->loader->load($path);

        // Rule name key is preserved exactly as-is
        self::assertArrayHasKey('size.method-count', $config['rules']);
        // Option keys within the rule are normalized to camelCase
        self::assertSame(15, $config['rules']['size.method-count']['warningThreshold']);
        self::assertSame(30, $config['rules']['size.method-count']['errorThreshold']);
    }

    #[Test]
    public function itPreservesCodeSmellRuleNames(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  code-smell.boolean-argument:
    enabled: false
YAML);

        $config = $this->loader->load($path);

        self::assertArrayHasKey('code-smell.boolean-argument', $config['rules']);
        self::assertFalse($config['rules']['code-smell.boolean-argument']['enabled']);
    }

    #[Test]
    public function itNormalizesNonRuleRootKeys(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
disabled_rules:
  - size.method-count
exclude_paths:
  - vendor
YAML);

        $config = $this->loader->load($path);

        // Root-level snake_case keys are normalized to camelCase
        self::assertArrayHasKey('disabledRules', $config);
        self::assertArrayHasKey('excludePaths', $config);
        self::assertSame(['size.method-count'], $config['disabledRules']);
        self::assertSame(['vendor'], $config['excludePaths']);
    }

    #[Test]
    public function itAcceptsParallelSection(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
parallel:
  workers: 4
YAML);

        $config = $this->loader->load($path);

        self::assertSame(4, $config['parallel']['workers']);
    }

    #[Test]
    public function itAcceptsCouplingSection(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
coupling:
  framework_namespaces:
    - Symfony
    - Doctrine
YAML);

        $config = $this->loader->load($path);

        self::assertSame(['Symfony', 'Doctrine'], $config['coupling']['frameworkNamespaces']);
    }

    #[Test]
    public function itLoadsProjectQmxYamlWithoutErrors(): void
    {
        $projectRoot = \dirname(__DIR__, 4);
        $configPath = $projectRoot . '/qmx.yaml';

        if (!file_exists($configPath)) {
            self::markTestSkipped('No qmx.yaml in project root');
        }

        // Smoke test: the project's own config file must load without errors
        $config = $this->loader->load($configPath);

        self::assertNotEmpty($config, 'Project qmx.yaml should produce non-empty config');
    }

    #[Test]
    public function itLoadsProjectQmxYamlExampleWithoutErrors(): void
    {
        $projectRoot = \dirname(__DIR__, 4);
        $examplePath = $projectRoot . '/qmx.yaml.example';

        if (!file_exists($examplePath)) {
            self::markTestSkipped('No qmx.yaml.example in project root');
        }

        // The example file is fully commented out — should parse as empty
        $config = $this->loader->load($examplePath);

        self::assertSame([], $config);
    }

    #[Test]
    public function itPreservesMultipleRuleNamesWithMixedFormats(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity.cyclomatic:
    warning_threshold: 10
  size.method-count:
    warning_threshold: 15
  code-smell.boolean-argument:
    enabled: false
  simple_rule:
    enabled: true
YAML);

        $config = $this->loader->load($path);

        // All rule name keys are preserved exactly as written
        self::assertArrayHasKey('complexity.cyclomatic', $config['rules']);
        self::assertArrayHasKey('size.method-count', $config['rules']);
        self::assertArrayHasKey('code-smell.boolean-argument', $config['rules']);
        self::assertArrayHasKey('simple_rule', $config['rules']);

        // Option keys are still normalized
        self::assertSame(10, $config['rules']['complexity.cyclomatic']['warningThreshold']);
        self::assertSame(15, $config['rules']['size.method-count']['warningThreshold']);
    }

    #[Test]
    public function itPreservesComputedMetricNameKeys(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
computed_metrics:
  computed.my_score:
    formula: "loc * 2"
    warning_threshold: 80
  health.complexity:
    error_threshold: 50
YAML);

        $config = $this->loader->load($path);

        // Computed metric name keys are preserved exactly as written
        self::assertArrayHasKey('computed.my_score', $config['computedMetrics']);
        self::assertArrayHasKey('health.complexity', $config['computedMetrics']);

        // Option keys within metrics are still normalized
        self::assertSame('loc * 2', $config['computedMetrics']['computed.my_score']['formula']);
        self::assertSame(80, $config['computedMetrics']['computed.my_score']['warningThreshold']);
        self::assertSame(50, $config['computedMetrics']['health.complexity']['errorThreshold']);
    }

    #[Test]
    public function itRejectsUnknownCacheSubKey(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
cache:
  enabled: true
  typo_key: something
YAML);

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('Unknown key in "cache" section: "typo_key"');

        $this->loader->load($path);
    }

    #[Test]
    public function itRejectsUnknownNamespaceSubKeyWithSuggestion(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
namespace:
  straetgy: psr4
YAML);

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('did you mean "strategy"?');

        $this->loader->load($path);
    }

    #[Test]
    public function itRejectsUnknownParallelSubKeyWithSuggestion(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
parallel:
  worker: 4
YAML);

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('did you mean "workers"?');

        $this->loader->load($path);
    }

    #[Test]
    public function itSuggestsCorrectRootKeyForTypo(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
cahce:
  enabled: true
YAML);

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('did you mean "cache"?');

        $this->loader->load($path);
    }

    #[Test]
    public function itShowsNoSuggestionForDistantKey(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
zzzzzzz: true
YAML);

        try {
            $this->loader->load($path);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('"zzzzzzz"', $e->getMessage());
            self::assertStringNotContainsString('did you mean', $e->getMessage());
        }
    }

    #[Test]
    public function itRejectsMultipleUnknownSubKeys(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
cache:
  foo: bar
  baz: qux
YAML);

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('Unknown keys in "cache" section');

        $this->loader->load($path);
    }

    #[Test]
    public function itShowsAllowedKeysInSubKeyError(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
cache:
  foo: bar
YAML);

        try {
            $this->loader->load($path);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('Allowed keys: dir, enabled', $e->getMessage());
        }
    }

    #[Test]
    public function itRejectsScalarArchitecture(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'architecture: false');

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('"architecture" must be an associative array');

        $this->loader->load($path);
    }

    #[Test]
    public function itRejectsScalarComputedMetrics(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'computed_metrics: not_a_map');

        // Belongs to the same associativeRootKeys() family — verify symmetry with rules
        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('"computed_metrics" must be an associative array');

        $this->loader->load($path);
    }

    #[Test]
    public function itPreservesArchitectureLayerNamesVerbatim(): void
    {
        // Under ADR 0006 `architecture.layers` is an ordered list; layer names
        // live in the `name` field of each entry, not as map keys. The values
        // of `name` are scalars and never touched by the loader's key
        // normalization, so the new shape preserves snake_case/kebab-case
        // names by construction. This test pins that behaviour.
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
architecture:
  layers:
    - name: app_core
      patterns: ['App\Core']
    - name: app-core-services
      patterns: ['App\CoreServices']
    - name: appCore
      patterns: ['App\AppCore']
YAML);

        $config = $this->loader->load($path);

        // Layer list preserved as a sequential list.
        self::assertIsArray($config['architecture']['layers']);
        self::assertCount(3, $config['architecture']['layers']);
        self::assertSame('app_core', $config['architecture']['layers'][0]['name']);
        self::assertSame('app-core-services', $config['architecture']['layers'][1]['name']);
        self::assertSame('appCore', $config['architecture']['layers'][2]['name']);
    }

    #[Test]
    public function itPreservesArchitectureAllowSourceLayerNames(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
architecture:
  layers:
    - name: app_core
      patterns: ['App\Core']
    - name: app_service
      patterns: ['App\Service']
  allow:
    app_core:
      - app_service
YAML);

        $config = $this->loader->load($path);

        // Source layer name in `allow` is still a map key — preserved verbatim
        // by the architecture section's PRESERVE_SUBTREE policy
        // (ConfigSchema::sectionPolicies()).
        self::assertArrayHasKey('app_core', $config['architecture']['allow']);
        // Target list values are scalars — unaffected by key normalization
        self::assertSame(['app_service'], $config['architecture']['allow']['app_core']);
    }

    #[Test]
    public function itPreservesLongFormTargetSnakeCaseKeysUnderArchitectureAllow(): void
    {
        // Subtree-preservation guarantee: long-form target maps below
        // architecture.allow.* carry documented snake_case keys
        // (allow_cross_instance, future relations) that must survive
        // normalization untransformed so they reach AllowValidator as the
        // user wrote them.
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
architecture:
  layers:
    - name: 'app-orders'
      patterns: ['App\Orders\App']
    - name: 'domain-orders'
      patterns: ['App\Orders\Domain']
  allow:
    'app-{m}':
      - target: 'domain-{m}'
        allow_cross_instance: true
YAML);

        $config = $this->loader->load($path);

        $entry = $config['architecture']['allow']['app-{m}'][0];

        self::assertArrayHasKey('target', $entry);
        self::assertArrayHasKey('allow_cross_instance', $entry, 'snake_case long-form key must survive normalization.');
        self::assertArrayNotHasKey('allowCrossInstance', $entry, 'long-form key must not be camelCased.');
        self::assertSame('domain-{m}', $entry['target']);
        self::assertTrue($entry['allow_cross_instance']);
    }

    #[Test]
    public function itStillNormalizesCliKeysOutsideArchitecture(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
architecture:
  layers:
    - name: app_core
      patterns: ['App\Core']
disabled_rules:
  - architecture.layer-violation
exclude_paths:
  - tests/
YAML);

        $config = $this->loader->load($path);

        // CLI-style top-level snake_case keys are normalized to camelCase as before
        self::assertArrayHasKey('disabledRules', $config);
        self::assertArrayHasKey('excludePaths', $config);
        // Architecture layer name preserved (as the value of `name`).
        self::assertSame('app_core', $config['architecture']['layers'][0]['name']);
    }

    #[Test]
    public function itAcceptsKebabCaseLayerNamesMatchingLayerDefinitionRegex(): void
    {
        // LayerDefinition::NAME_REGEX accepts [a-z][a-z0-9_-]*; confirm the loader
        // preserves names that fit the regex without mutating them.
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
architecture:
  layers:
    - name: 'app-core'
      patterns: ['App\Core']
    - name: 'app_core_v2'
      patterns: ['App\CoreV2']
YAML);

        $config = $this->loader->load($path);

        self::assertSame('app-core', $config['architecture']['layers'][0]['name']);
        self::assertSame('app_core_v2', $config['architecture']['layers'][1]['name']);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
