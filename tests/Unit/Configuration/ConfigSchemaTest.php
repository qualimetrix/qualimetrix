<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\ConfigSchema;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;
use ReflectionClass;
use ReflectionNamedType;

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

        // camelCase top-level keys (no snake_case — loader normalizes before validation)
        self::assertContains('computedMetrics', $keys);
        self::assertContains('memoryLimit', $keys);
        self::assertContains('excludeHealth', $keys);
        self::assertContains('includeGenerated', $keys);
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
     * Uses realistic sub-keys for sections (derived from dotted source paths)
     * to verify that both root keys AND their actual sub-keys are accepted.
     */
    private function buildFullConfigYaml(): string
    {
        $sections = array_flip(ConfigSchema::sectionKeys());
        $lists = array_flip(ConfigSchema::listKeys());
        $lines = [];
        $handledRoots = [];
        $sectionSubKeys = [];

        // Collect real sub-keys for sections from dotted source paths
        foreach (ConfigSchema::ENTRIES as [$sourcePath]) {
            if (str_contains($sourcePath, '.')) {
                [$root, $subKey] = explode('.', $sourcePath, 2);
                $sectionSubKeys[$root][] = $subKey;
            }
        }

        foreach (ConfigSchema::ENTRIES as [$sourcePath, , $type]) {
            $root = str_contains($sourcePath, '.') ? explode('.', $sourcePath, 2)[0] : $sourcePath;

            if (isset($handledRoots[$root])) {
                continue;
            }
            $handledRoots[$root] = true;

            if (isset($sections[$root])) {
                $lines[] = "{$root}:";
                // Use real sub-keys from ENTRIES instead of dummy values
                foreach ($sectionSubKeys[$root] ?? [] as $subKey) {
                    $lines[] = "  {$subKey}: dummy";
                }
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
    public function allowedSectionSubKeysReturnsCorrectKeysPerSection(): void
    {
        $subKeys = ConfigSchema::allowedSectionSubKeys();

        self::assertSame(['dir', 'enabled'], $subKeys['cache']);
        self::assertSame(['strategy', 'composerJson'], $subKeys['namespace']);
        self::assertSame(['prefixes', 'autoDepth'], $subKeys['aggregation']);
        self::assertSame(['workers'], $subKeys['parallel']);
        self::assertSame(['frameworkNamespaces'], $subKeys['coupling']);

        // All section roots should match sectionKeys()
        self::assertEqualsCanonicalizing(
            ConfigSchema::sectionKeys(),
            array_keys($subKeys),
        );
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

    /**
     * Reverse of everyEntryHasMatchingConstant: every string constant
     * must appear in ENTRIES (to have a YAML path) or be explicitly
     * documented as internal-only.
     */
    #[Test]
    public function everyConstantHasEntryOrIsInternal(): void
    {
        $internalConstants = ConfigSchema::INTERNAL_KEYS;

        $reflection = new ReflectionClass(ConfigSchema::class);
        $entryResultKeys = array_map(static fn(array $e): string => $e[1], ConfigSchema::ENTRIES);

        foreach ($reflection->getReflectionConstants() as $rc) {
            if (!$rc->isPublic() || !$rc->getType() instanceof ReflectionNamedType || $rc->getType()->getName() !== 'string') {
                continue;
            }

            $value = $rc->getValue();

            if (\in_array($value, $internalConstants, true)) {
                continue;
            }

            self::assertContains(
                $value,
                $entryResultKeys,
                \sprintf(
                    "ConfigSchema::%s = '%s' has no ENTRIES row (YAML path unreachable). "
                    . 'Add an entry to ENTRIES or list it in the $internalConstants allowlist.',
                    $rc->getName(),
                    $value,
                ),
            );
        }
    }

    /**
     * Verifies no key constant is defined in ConfigSchema but unused
     * by any consumer. A dangling constant means the key is configurable
     * in YAML but silently ignored at runtime.
     *
     * Consumer files are discovered dynamically to avoid hardcoded lists
     * going stale when new consumers are added.
     */
    #[Test]
    public function noConstantIsDangling(): void
    {
        $configDir = __DIR__ . '/../../../src/Configuration';
        $consumerFiles = glob($configDir . '/{,*/,*/*/}*.php', \GLOB_BRACE) ?: [];
        self::assertNotEmpty($consumerFiles, 'No PHP files found in src/Configuration/');

        // Exclude ConfigSchema itself — we check consumers, not the definition
        $consumerFiles = array_filter(
            $consumerFiles,
            static fn(string $f): bool => !str_ends_with($f, 'ConfigSchema.php'),
        );

        $allCode = '';
        foreach ($consumerFiles as $file) {
            $allCode .= file_get_contents($file);
        }

        $reflection = new ReflectionClass(ConfigSchema::class);

        foreach ($reflection->getReflectionConstants() as $rc) {
            if (!$rc->isPublic() || !$rc->getType() instanceof ReflectionNamedType || $rc->getType()->getName() !== 'string') {
                continue;
            }

            $search = 'ConfigSchema::' . $rc->getName();
            self::assertStringContainsString(
                $search,
                $allCode,
                \sprintf(
                    '%s is defined but not referenced in any consumer. '
                    . 'Either wire it to a consumer or remove the constant.',
                    $search,
                ),
            );
        }
    }
}
