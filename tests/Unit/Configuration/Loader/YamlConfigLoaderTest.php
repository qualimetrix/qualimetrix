<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Configuration\Loader;

use AiMessDetector\Configuration\Exception\ConfigLoadException;
use AiMessDetector\Configuration\Loader\YamlConfigLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(YamlConfigLoader::class)]
final class YamlConfigLoaderTest extends TestCase
{
    private YamlConfigLoader $loader;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->loader = new YamlConfigLoader();
        $this->tempDir = sys_get_temp_dir() . '/aimd_test_' . uniqid();
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
  dir: .aimd-cache

format: text
YAML);

        $config = $this->loader->load($path);

        self::assertArrayHasKey('rules', $config);
        self::assertArrayHasKey('cyclomaticComplexity', $config['rules']);
        self::assertTrue($config['rules']['cyclomaticComplexity']['enabled']);
        self::assertSame(10, $config['rules']['cyclomaticComplexity']['warningThreshold']);
        self::assertSame(20, $config['rules']['cyclomaticComplexity']['errorThreshold']);
        self::assertTrue($config['cache']['enabled']);
        self::assertSame('.aimd-cache', $config['cache']['dir']);
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
        self::assertArrayHasKey('namespaceSize', $config['rules']);
        self::assertSame(10, $config['rules']['namespaceSize']['warningThreshold']);
        self::assertTrue($config['rules']['namespaceSize']['countInterfaces']);
        self::assertFalse($config['rules']['namespaceSize']['countTraits']);
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
        $this->expectExceptionMessage('"disabled_rules" must be a list');

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
YAML);

        $config = $this->loader->load($path);

        self::assertArrayHasKey('rules', $config);
        self::assertArrayHasKey('cache', $config);
        self::assertSame('json', $config['format']);
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
