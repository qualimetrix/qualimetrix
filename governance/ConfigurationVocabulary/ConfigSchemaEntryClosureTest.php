<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ConfigurationVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The closure `ConfigSchema` promises over its own vocabulary: every
 * `ENTRIES` row has a constant, every public string constant has an
 * `ENTRIES` row or is marked internal, no constant is left unreferenced by a
 * consumer, section and list keys never overlap, and every typed key is a
 * root key the full config loader still accepts.
 */
#[CoversClass(ConfigSchema::class)]
final class ConfigSchemaEntryClosureTest extends TestCase
{
    #[Test]
    public function itKeepsSectionAndListKeysDisjoint(): void
    {
        $sections = ConfigSchema::sectionKeys();
        $lists = ConfigSchema::listKeys();

        self::assertSame([], array_intersect($sections, $lists));
    }

    #[Test]
    public function itIncludesEveryTypedKeyInAllowedRootKeys(): void
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
    public function itAcceptsAConfigCoveringEverySchemaEntry(): void
    {
        // Build a YAML config that exercises every root key from ENTRIES
        $yaml = $this->buildFullConfigYaml();

        $tmpFile = sys_get_temp_dir() . '/qmx_schema_test_' . bin2hex(random_bytes(6)) . '.yaml';
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
        $scalarTypes = [];

        // Collect real sub-keys for sections from dotted source paths
        foreach (ConfigSchema::ENTRIES as [$sourcePath, , , $scalarType]) {
            $scalarTypes[$sourcePath] = $scalarType;

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
                    $lines[] = '  ' . $subKey . ': ' . self::dummyScalarValue($scalarTypes["{$root}.{$subKey}"] ?? null);
                }
            } elseif (isset($lists[$root])) {
                $lines[] = "{$root}:";
                $lines[] = '  - dummy';
            } elseif ($type === 'mixed') {
                $lines[] = "{$root}:";
                $lines[] = '  dummy.rule: true';
            } else {
                $lines[] = $root . ': ' . self::dummyScalarValue($scalarTypes[$sourcePath] ?? null);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Returns a YAML literal whose type matches the given scalar marker.
     */
    private static function dummyScalarValue(?string $scalarType): string
    {
        return match ($scalarType) {
            'boolean' => 'true',
            'integer' => '4',
            default => 'dummy',
        };
    }

    #[Test]
    public function itReturnsTheCorrectSubKeysPerSection(): void
    {
        $subKeys = ConfigSchema::allowedSectionSubKeys();

        self::assertSame(['dir', 'enabled'], $subKeys['cache']);
        self::assertArrayNotHasKey('namespace', $subKeys);
        self::assertArrayNotHasKey('aggregation', $subKeys);
        self::assertSame(['workers'], $subKeys['parallel']);
        self::assertSame(['frameworkNamespaces'], $subKeys['coupling']);

        // All section roots should match sectionKeys()
        self::assertEqualsCanonicalizing(
            ConfigSchema::sectionKeys(),
            array_keys($subKeys),
        );
    }

    #[Test]
    public function itGivesEveryEntryAMatchingConstant(): void
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
     * Reverse of itGivesEveryEntryAMatchingConstant: every string constant
     * must appear in ENTRIES (to have a YAML path) or be explicitly
     * documented as internal-only.
     */
    #[Test]
    public function itGivesEveryConstantAnEntryOrMarksItInternal(): void
    {
        $internalConstants = [...ConfigSchema::INTERNAL_KEYS, ...ConfigSchema::DOCUMENT_ROOTS];

        $reflection = new ReflectionClass(ConfigSchema::class);
        $entryResultKeys = array_map(static fn(array $e): string => $e[1], ConfigSchema::ENTRIES);

        foreach ($reflection->getReflectionConstants() as $rc) {
            if (!$rc->isPublic() || !$rc->getType() instanceof ReflectionNamedType || $rc->getType()->getName() !== 'string') {
                continue;
            }

            $value = $rc->getValue();

            if (\in_array($value, ConfigSchema::DOCUMENT_ROOTS, true)) {
                continue;
            }

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
    public function itLeavesNoConstantUnreferencedByAConsumer(): void
    {
        $sourceDir = \dirname(__DIR__, 2) . '/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir));
        $consumerFiles = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $consumerFiles[] = $file->getPathname();
            }
        }
        self::assertNotEmpty($consumerFiles, 'No PHP files found in src/.');

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

            if (\in_array($rc->getValue(), ConfigSchema::DOCUMENT_ROOTS, true)) {
                continue;
            }

            // Coupling owns the semantic consumer through ConfigurationDocument;
            // Configuration owns only this normalized document-schema entry.
            if ($rc->getValue() === ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES) {
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
