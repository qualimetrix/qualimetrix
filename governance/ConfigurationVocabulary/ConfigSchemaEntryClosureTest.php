<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ConfigurationVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\DocumentRoots;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;
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
     * Preserves the original full dummy document as a refusal, then accepts a lawful
     * companion covering every ENTRIES root and sub-key in canonical form.
     *
     * This catches the exact bug we had: coupling.frameworkNamespaces was added
     * to MAPPINGS but not to ALLOWED_ROOT_KEYS, so any qmx.yaml using `coupling:`
     * was rejected with "Unknown configuration keys".
     */
    #[Test]
    public function itAcceptsAConfigCoveringEverySchemaEntry(): void
    {
        $yaml = $this->buildFullConfigYaml();
        $tmpFile = sys_get_temp_dir() . '/qmx_schema_test_' . bin2hex(random_bytes(6)) . '.yaml';
        $companionFile = sys_get_temp_dir() . '/qmx_schema_companion_' . bin2hex(random_bytes(6)) . '.yaml';
        file_put_contents($tmpFile, $yaml);
        file_put_contents($companionFile, "paths: [dummy]\nexclude: [{subtree: dummy}]\nformat: json\nrules:\n  complexity.ccn: {enabled: true}\ndisabled_rules: [complexity.cognitive]\nonly_rules: [complexity.ccn]\nsuppress_paths: [{subtree: dummy}]\nsuppress_namespaces: [{subtree: Dummy}]\nfail_on: error\ncache: {dir: dummy, enabled: true}\nparallel: {workers: 4}\ncoupling:\n  framework_namespaces: [{subtree: Dummy}]\ninclude_generated: true\ninclude_autoload_dev: true\nmemory_limit: 512M\narchitecture:\n  layers:\n    - name: dummy\n      patterns: [Dummy]\n");

        try {
            $pipeline = new ConfigurationPipeline(LayeredDocument::standaloneSections());
            $pipeline->addStage(new ConfigFileStage(new YamlConfigLoader()));
            $this->assertRefusedFile($pipeline, $tmpFile, ['"exclude[0]" in configuration file "{actual_config_path}" must be a map, got string. A selector names its kind: {exact: value}, {subtree: value}, or {regex: value}.', ['exclude', '0'], '0']);
            $document = $pipeline->resolve(new ConfigurationResolutionRequest(
                AbsolutePath::fromString(\dirname($companionFile)),
                configFilePath: $companionFile,
            ));
            $config = array_map(
                static fn(ResolvedValueInterface $value): mixed => $value->plain(),
                $document->resolved()->roots(),
            );
            self::assertNotEmpty($config, 'Full config YAML should produce non-empty result');
            self::assertSame(['paths' => ['dummy'], 'exclude' => [['subtree' => 'dummy']], 'format' => 'json', 'rules' => ['complexity.ccn' => ['enabled' => true]], 'disabled_rules' => ['complexity.cognitive'], 'only_rules' => ['complexity.ccn'], 'suppress_paths' => [['subtree' => 'dummy']], 'suppress_namespaces' => [['subtree' => 'Dummy']], 'fail_on' => 'error', 'cache' => ['dir' => 'dummy', 'enabled' => true], 'parallel' => ['workers' => 4], 'coupling' => ['framework_namespaces' => [['subtree' => 'Dummy']]], 'include_generated' => true, 'include_autoload_dev' => true, 'memory_limit' => '512M', 'architecture' => ['layers' => [['name' => 'dummy', 'patterns' => [['Dummy']]]]]], $config);

            foreach (ConfigSchema::ENTRIES as [, $resultKey]) {
                $cursor = $config;
                foreach (DocumentRoots::pathOf($resultKey) as $segment) {
                    self::assertIsArray($cursor);
                    self::assertArrayHasKey($segment, $cursor, $resultKey);
                    $cursor = $cursor[$segment];
                }
            }
        } finally {
            unlink($tmpFile);
            unlink($companionFile);
        }
    }

    /** @param array{string, list<string>, string} $expected */
    private function assertRefusedFile(ConfigurationPipeline $pipeline, string $file, array $expected): void
    {
        try {
            $pipeline->resolve(new ConfigurationResolutionRequest(
                AbsolutePath::fromString(\dirname($file)),
                configFilePath: $file,
            ));
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(str_replace('{actual_config_path}', $file, $expected[0]), $refusal->summary());
            self::assertCount(1, $refusal->sources());
            self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
            self::assertSame($file, $refusal->sources()[0]->locator());
            $position = $refusal->position();
            self::assertNotNull($position);
            self::assertSame($expected[1], $position->segments);
            self::assertSame($expected[2], $position->written);

            return;
        }

        self::fail('The original YAML must be refused before loading its lawful companion.');
    }

    /**
     * Builds a YAML string that contains every root key from ConfigSchema::ENTRIES.
     *
     * Retains the original generated dummy values and all ENTRIES sub-keys
     * as the independently refused document.
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
