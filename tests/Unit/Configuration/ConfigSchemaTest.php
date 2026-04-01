<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\ConfigSchema;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;
use ReflectionClass;

#[CoversClass(ConfigSchema::class)]
final class ConfigSchemaTest extends TestCase
{
    #[Test]
    public function allowedRootKeysContainsAllExpectedKeys(): void
    {
        $keys = ConfigSchema::allowedRootKeys();

        // Top-level direct keys
        self::assertContains('paths', $keys);
        self::assertContains('exclude', $keys);
        self::assertContains('format', $keys);
        self::assertContains('rules', $keys);
        self::assertContains('failOn', $keys);

        // Section keys (derived from dotted entries)
        self::assertContains('cache', $keys);
        self::assertContains('namespace', $keys);
        self::assertContains('aggregation', $keys);
        self::assertContains('coupling', $keys);
        self::assertContains('parallel', $keys);

        // Dual-naming alternatives
        self::assertContains('computedMetrics', $keys);
        self::assertContains('computed_metrics', $keys);
        self::assertContains('memoryLimit', $keys);
        self::assertContains('memory_limit', $keys);
    }

    #[Test]
    public function sectionKeysIncludesDottedRoots(): void
    {
        $sections = ConfigSchema::sectionKeys();

        self::assertContains('cache', $sections);
        self::assertContains('namespace', $sections);
        self::assertContains('aggregation', $sections);
        self::assertContains('coupling', $sections);
        self::assertContains('parallel', $sections);

        // These are NOT sections
        self::assertNotContains('rules', $sections);
        self::assertNotContains('paths', $sections);
        self::assertNotContains('format', $sections);
    }

    #[Test]
    public function listKeysReturnsOnlyLists(): void
    {
        $lists = ConfigSchema::listKeys();

        self::assertContains('paths', $lists);
        self::assertContains('exclude', $lists);
        self::assertContains('disabledRules', $lists);
        self::assertContains('onlyRules', $lists);
        self::assertContains('excludePaths', $lists);
        self::assertContains('excludeHealth', $lists);

        // These are NOT lists
        self::assertNotContains('rules', $lists);
        self::assertNotContains('cache', $lists);
        self::assertNotContains('format', $lists);
    }

    #[Test]
    public function sectionAndListKeysDoNotOverlap(): void
    {
        $sections = ConfigSchema::sectionKeys();
        $lists = ConfigSchema::listKeys();

        self::assertSame([], array_intersect($sections, $lists));
    }

    #[Test]
    public function allTypedKeysAreInAllowedRootKeys(): void
    {
        $allowed = ConfigSchema::allowedRootKeys();

        foreach (ConfigSchema::sectionKeys() as $section) {
            self::assertContains($section, $allowed, "Section key '{$section}' not in allowed root keys");
        }
        foreach (ConfigSchema::listKeys() as $list) {
            self::assertContains($list, $allowed, "List key '{$list}' not in allowed root keys");
        }
    }

    /**
     * Regression test: generates a YAML config using ALL known keys from ENTRIES
     * and verifies YamlConfigLoader accepts every one of them.
     *
     * This catches the exact bug we had: coupling.frameworkNamespaces was added
     * to MAPPINGS but not to ALLOWED_ROOT_KEYS, so any qmx.yaml using `coupling:`
     * was rejected with "Unknown configuration keys".
     */
    #[Test]
    public function allSchemaEntriesPassLoaderValidation(): void
    {
        // Build a YAML config that exercises every root key from ENTRIES
        $yaml = $this->buildFullConfigYaml();

        $tmpFile = sys_get_temp_dir() . '/qmx_schema_test_' . uniqid() . '.yaml';
        file_put_contents($tmpFile, $yaml);

        try {
            $loader = new YamlConfigLoader();
            // If any ENTRIES root key is not in allowedRootKeys(), this throws
            $config = $loader->load($tmpFile);
            self::assertNotEmpty($config, 'Full config YAML should produce non-empty result');
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * Builds a YAML string that contains every root key from ConfigSchema::ENTRIES.
     *
     * Uses dummy values appropriate for each key type:
     * - sections get a sub-key with a dummy value
     * - lists get a single-element array
     * - scalars get a string
     * - mixed (rules, computed_metrics) get an associative array
     */
    private function buildFullConfigYaml(): string
    {
        $sections = array_flip(ConfigSchema::sectionKeys());
        $lists = array_flip(ConfigSchema::listKeys());
        $lines = [];
        $handledRoots = [];

        foreach (ConfigSchema::ENTRIES as [$sourcePath, , $type]) {
            // Take only the first alternative for dual-naming entries
            $source = explode('|', $sourcePath)[0];

            // Get the root key
            $root = str_contains($source, '.') ? explode('.', $source, 2)[0] : $source;

            if (isset($handledRoots[$root])) {
                continue;
            }
            $handledRoots[$root] = true;

            if (isset($sections[$root])) {
                $lines[] = "{$root}:";
                $lines[] = '  _dummy: true';
            } elseif (isset($lists[$root])) {
                $lines[] = "{$root}:";
                $lines[] = '  - dummy';
            } elseif ($type === 'mixed') {
                $lines[] = "{$root}:";
                $lines[] = '  dummy.rule: true';
            } else {
                $lines[] = "{$root}: dummy";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    #[Test]
    public function everyEntryHasMatchingConstant(): void
    {
        $reflection = new ReflectionClass(ConfigSchema::class);
        $constantValues = array_values($reflection->getConstants());

        foreach (ConfigSchema::ENTRIES as [, $resultKey]) {
            self::assertContains(
                $resultKey,
                $constantValues,
                "Result key '{$resultKey}' has no matching constant in ConfigSchema",
            );
        }
    }
}
