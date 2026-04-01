<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Loader;

use PHPUnit\Framework\Attributes\CoversClass;
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

    public function testSupportsYamlExtension(): void
    {
        self::assertTrue($this->loader->supports('/path/to/config.yaml'));
        self::assertTrue($this->loader->supports('/path/to/config.yml'));
        self::assertTrue($this->loader->supports('/path/to/config.YAML'));
        self::assertTrue($this->loader->supports('/path/to/config.YML'));
    }

    public function testDoesNotSupportOtherExtensions(): void
    {
        self::assertFalse($this->loader->supports('/path/to/config.php'));
        self::assertFalse($this->loader->supports('/path/to/config.json'));
        self::assertFalse($this->loader->supports('/path/to/config.xml'));
    }

    public function testLoadValidYaml(): void
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

    public function testLoadNormalizesSnakeCaseToCamelCase(): void
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

    public function testLoadEmptyFile(): void
    {
        $path = $this->tempDir . '/empty.yaml';
        file_put_contents($path, '');

        $config = $this->loader->load($path);

        self::assertSame([], $config);
    }

    public function testLoadFileNotFound(): void
    {
        $path = $this->tempDir . '/nonexistent.yaml';

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('Configuration file not found');

        $this->loader->load($path);
    }

    public function testLoadInvalidYaml(): void
    {
        $path = $this->tempDir . '/invalid.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  - this: is
    invalid: yaml:
      syntax: [
YAML);

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('Failed to parse configuration file');

        $this->loader->load($path);
    }

    public function testLoadScalarValueThrows(): void
    {
        $path = $this->tempDir . '/scalar.yaml';
        file_put_contents($path, 'just a string');

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('is not valid YAML format');

        $this->loader->load($path);
    }

    public function testLoadPreservesCamelCaseKeys(): void
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

    public function testLoadRejectsUnknownRootKeys(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity:
    enabled: true
unknown_key: some_value
another_bad_key: true
YAML);

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('Unknown configuration keys');

        $this->loader->load($path);
    }

    public function testLoadRejectsNonArrayRules(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'rules: not_an_array');

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('"rules" must be an associative array');

        $this->loader->load($path);
    }

    public function testLoadRejectsInvalidRuleConfig(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity: "invalid string value"
YAML);

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('Rule "complexity" configuration must be an array, boolean, or null');

        $this->loader->load($path);
    }

    public function testLoadAcceptsBooleanRuleConfig(): void
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

    public function testLoadAcceptsNullRuleConfig(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity: ~
YAML);

        $config = $this->loader->load($path);

        self::assertNull($config['rules']['complexity']);
    }

    public function testLoadRejectsNonArrayCache(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'cache: not_an_array');

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('"cache" must be an associative array');

        $this->loader->load($path);
    }

    public function testLoadRejectsNonArrayNamespace(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'namespace: not_an_array');

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('"namespace" must be an associative array');

        $this->loader->load($path);
    }

    public function testLoadRejectsNonListDisabledRules(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'disabled_rules: not_a_list');

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('"disabledRules" must be a list');

        $this->loader->load($path);
    }

    public function testLoadAcceptsAllValidRootKeys(): void
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

    public function testLoadRejectsNonListExcludePaths(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'exclude_paths: not_a_list');

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('"excludePaths" must be a list');

        $this->loader->load($path);
    }

    public function testLoadPreservesDottedKebabCaseRuleNames(): void
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

    public function testLoadPreservesCodeSmellRuleNames(): void
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

    public function testLoadNormalizesNonRuleRootKeys(): void
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

    public function testLoadAcceptsParallelSection(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
parallel:
  workers: 4
YAML);

        $config = $this->loader->load($path);

        self::assertSame(4, $config['parallel']['workers']);
    }

    public function testLoadAcceptsCouplingSection(): void
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

    public function testLoadPreservesMultipleRuleNamesWithMixedFormats(): void
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
