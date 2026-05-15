<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Configuration\Loader;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\ConfigSchema;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;

/**
 * Pins the current key-normalization behavior of {@see YamlConfigLoader} for
 * every {@see ConfigSchema::allowedRootKeys()} root. Acts as the safety net
 * for the Phase 3 (ADR 0009) migration to a policy-driven loader: any change
 * to {@link YamlConfigLoader::normalizeKeys()} or
 * {@link ConfigSchema::sectionPolicies()} that drifts existing behavior makes
 * a row here flip from green to red.
 *
 * Migration responsibilities of this test (see ADR 0009 §5):
 *
 *  - Steps 3.2–3.4 (enum, `sectionPolicies()`, policy-driven loader): test
 *    stays green; no behavior change.
 *  - Step 3.5 (`architecture` → `PRESERVE_SUBTREE`): the architecture rows
 *    below switch from "snake_case mangled" to "snake_case preserved" with
 *    an inline marker documenting the migration. Existing positive
 *    assertions for non-architecture roots remain untouched.
 *
 * Distinct from {@see \Qualimetrix\Tests\Integration\Configuration\YamlKeyReachabilityTest}:
 *
 *  - Reachability test: documented-key coverage (every YAML key in
 *    `ConfigSchema::ENTRIES` survives to the spelling the factory looks
 *    for). Asserts the contract surface.
 *  - Characterization test (this class): per-root behavior snapshot of
 *    every root key — including unexposed keys and bug-state pins —
 *    intended to catch regressions in the normalization model itself.
 */
#[CoversClass(YamlConfigLoader::class)]
final class YamlNormalizationCharacterizationTest extends TestCase
{
    private YamlConfigLoader $loader;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->loader = new YamlConfigLoader();
        $this->tempDir = sys_get_temp_dir() . '/qmx_yaml_norm_char_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $files = glob($this->tempDir . '/*');
        if ($files === false) {
            $files = [];
        }
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    /**
     * Drives one assertion per root key. The provider yields the post-load
     * array shape expected for a minimal YAML containing that root; the
     * test simply parses the YAML and compares to {@code $expected}.
     *
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('provideRootKeyCases')]
    #[TestDox('root key $description is normalized exactly as today')]
    public function rootKeyNormalizationMatchesSnapshot(string $description, string $yaml, array $expected): void
    {
        self::assertSame($expected, $this->loadYaml($yaml), $description);
    }

    /**
     * Coverage smoke test: every root key in {@see ConfigSchema::allowedRootKeys()}
     * has at least one characterization row. Catches the failure mode where a
     * contributor adds a new root key without pinning its current behavior
     * (which would let Phase 3.4's policy-driven loader silently change
     * behavior for it).
     */
    #[Test]
    public function everyAllowedRootKeyHasACharacterizationCase(): void
    {
        $covered = [];
        foreach (self::provideRootKeyCases() as [, , $expected]) {
            foreach (array_keys($expected) as $rootKey) {
                $covered[(string) $rootKey] = true;
            }
        }

        $missing = [];
        foreach (ConfigSchema::allowedRootKeys() as $root) {
            if (!isset($covered[$root])) {
                $missing[] = $root;
            }
        }

        self::assertSame(
            [],
            $missing,
            'Every ConfigSchema::allowedRootKeys() entry must have a characterization row. '
            . 'Missing roots: ' . implode(', ', $missing) . '. '
            . 'Add a case to ' . __CLASS__ . '::provideRootKeyCases().',
        );
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function provideRootKeyCases(): iterable
    {
        // --- TOP-LEVEL LIST ROOTS --------------------------------------------

        yield 'paths (list)' => [
            'paths preserves list items, key already lowercase',
            "paths:\n  - src\n  - tests\n",
            ['paths' => ['src', 'tests']],
        ];

        yield 'exclude (list)' => [
            'exclude (singular) key already lowercase, list items preserved',
            "exclude:\n  - vendor\n",
            ['exclude' => ['vendor']],
        ];

        yield 'disabled_rules → disabledRules' => [
            'snake_case root list key normalized to camelCase, items preserved',
            "disabled_rules:\n  - complexity.cyclomatic\n",
            ['disabledRules' => ['complexity.cyclomatic']],
        ];

        yield 'only_rules → onlyRules' => [
            'snake_case root list key normalized to camelCase',
            "only_rules:\n  - complexity.cognitive\n",
            ['onlyRules' => ['complexity.cognitive']],
        ];

        yield 'exclude_paths → excludePaths' => [
            'snake_case root list key normalized to camelCase, glob items preserved',
            "exclude_paths:\n  - src/Generated/*\n",
            ['excludePaths' => ['src/Generated/*']],
        ];

        yield 'exclude_namespaces → excludeNamespaces' => [
            'snake_case root list key normalized to camelCase, namespace strings preserved',
            "exclude_namespaces:\n  - App\\Generated\n",
            ['excludeNamespaces' => ['App\\Generated']],
        ];

        yield 'exclude_health → excludeHealth' => [
            'snake_case root list key normalized to camelCase',
            "exclude_health:\n  - tests/**\n",
            ['excludeHealth' => ['tests/**']],
        ];

        // --- TOP-LEVEL SCALAR ROOTS ------------------------------------------

        yield 'format (scalar)' => [
            'single-word scalar key passes through',
            "format: json\n",
            ['format' => 'json'],
        ];

        yield 'fail_on → failOn (scalar)' => [
            'snake_case scalar root normalized to camelCase',
            "fail_on: error\n",
            ['failOn' => 'error'],
        ];

        yield 'include_generated → includeGenerated (scalar bool)' => [
            'snake_case scalar root normalized to camelCase',
            "include_generated: true\n",
            ['includeGenerated' => true],
        ];

        yield 'memory_limit → memoryLimit (scalar)' => [
            'snake_case scalar root normalized to camelCase',
            "memory_limit: 512M\n",
            ['memoryLimit' => '512M'],
        ];

        // --- TYPED SECTIONS (camelCase everywhere) ---------------------------

        yield 'cache: sub-keys camelCased' => [
            'cache section sub-keys are typed options — normalize at every level',
            "cache:\n  dir: .qmx-cache\n  enabled: true\n",
            [
                'cache' => [
                    'dir' => '.qmx-cache',
                    'enabled' => true,
                ],
            ],
        ];

        yield 'namespace: composer_json → composerJson' => [
            'namespace section sub-keys are typed options — normalize at every level',
            "namespace:\n  strategy: psr4\n  composer_json: ./composer.json\n",
            [
                'namespace' => [
                    'strategy' => 'psr4',
                    'composerJson' => './composer.json',
                ],
            ],
        ];

        yield 'aggregation: auto_depth → autoDepth' => [
            'aggregation section sub-keys are typed options — normalize at every level',
            "aggregation:\n  prefixes:\n    - App\n  auto_depth: true\n",
            [
                'aggregation' => [
                    'prefixes' => ['App'],
                    'autoDepth' => true,
                ],
            ],
        ];

        yield 'parallel: workers' => [
            'parallel section sub-keys are typed options — already single-word, pass through',
            "parallel:\n  workers: 4\n",
            ['parallel' => ['workers' => 4]],
        ];

        yield 'coupling: framework_namespaces → frameworkNamespaces' => [
            'coupling section sub-keys are typed options — normalize at every level',
            "coupling:\n  framework_namespaces:\n    - Symfony\\\n",
            ['coupling' => ['frameworkNamespaces' => ['Symfony\\']]],
        ];

        // --- IDENTIFIER SECTIONS (preserve level 1, normalize level 2+) -----

        yield 'rules: identifier preserved, option keys normalized' => [
            'rules section — level 1 keys are rule slugs (preserve); level 2+ are options (normalize)',
            "rules:\n  complexity.cyclomatic:\n    enabled: true\n    warning_threshold: 10\n  namespace_size:\n    enabled: false\n",
            [
                'rules' => [
                    'complexity.cyclomatic' => [
                        'enabled' => true,
                        'warningThreshold' => 10,
                    ],
                    'namespace_size' => [
                        'enabled' => false,
                    ],
                ],
            ],
        ];

        yield 'rules: nested option subtree normalizes recursively' => [
            'rules.*.complexity.method.warning — nested options camelCased at every level below the identifier',
            "rules:\n  complexity:\n    method:\n      warning_threshold: 12\n",
            [
                'rules' => [
                    'complexity' => [
                        'method' => [
                            'warningThreshold' => 12,
                        ],
                    ],
                ],
            ],
        ];

        yield 'computed_metrics → computedMetrics root; identifier preserved, options normalized' => [
            'computed_metrics — level 1 keys are user-defined metric names (preserve); level 2+ are typed options',
            "computed_metrics:\n  computed.my_score:\n    formula: 'loc * 2'\n    warning_threshold: 80\n",
            [
                'computedMetrics' => [
                    'computed.my_score' => [
                        'formula' => 'loc * 2',
                        'warningThreshold' => 80,
                    ],
                ],
            ],
        ];

        // --- ARCHITECTURE (MIXED with nested-identifier opt-out subtree) -----
        //
        // Current behavior pinned at the time of authoring (pre-Phase 3.5):
        //   * `architecture.layers` is a list — items pass through with their
        //     own (typically camelCase) keys.
        //   * `architecture.allow` subtree is subtree-preserved via
        //     `ConfigSchema::nestedIdentifierKeyPaths()` so snake_case layer
        //     names and snake_case long-form keys (`allow_cross_instance`)
        //     survive verbatim.
        //   * `architecture.coverage` is a scalar — value preserved, key
        //     already lowercase, no change.
        //   * `architecture.max_expanded_layers` is a SCALAR LEAF — current
        //     loader normalizes the key to `maxExpandedLayers`, which
        //     `ArchitectureConfigurationFactory` does not look for. This is
        //     the C1 bug pinned by ADR 0009; Phase 3.5 migrates `architecture`
        //     to `PRESERVE_SUBTREE` and the row below flips to assert the
        //     fixed behavior.

        yield 'architecture.layers (list, items preserved)' => [
            'architecture.layers is a list; entries pass through as-is',
            "architecture:\n  layers:\n    - name: app\n      patterns: ['App']\n",
            [
                'architecture' => [
                    'layers' => [
                        ['name' => 'app', 'patterns' => ['App']],
                    ],
                ],
            ],
        ];

        yield 'architecture.allow subtree preserves snake_case verbatim' => [
            'architecture.allow under nestedIdentifierKeyPaths — layer names AND long-form keys preserved at every depth',
            "architecture:\n  allow:\n    app_core:\n      - target: app_service\n        allow_cross_instance: true\n",
            [
                'architecture' => [
                    'allow' => [
                        'app_core' => [
                            [
                                'target' => 'app_service',
                                'allow_cross_instance' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        yield 'architecture.coverage (scalar, single-word key)' => [
            'architecture.coverage scalar value flows through; key already lowercase',
            "architecture:\n  coverage: ignore\n",
            ['architecture' => ['coverage' => 'ignore']],
        ];

        // Phase 3.5 will flip this row to assert
        //   ['architecture' => ['max_expanded_layers' => 256]]
        // alongside migrating `architecture` to PRESERVE_SUBTREE.
        yield 'architecture.max_expanded_layers (CURRENT BUG: scalar leaf camelCased — flips in Phase 3.5)' => [
            'architecture.max_expanded_layers — known C1 bug: scalar leaf under MIXED root is mangled, factory falls back to default',
            "architecture:\n  max_expanded_layers: 256\n",
            ['architecture' => ['maxExpandedLayers' => 256]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadYaml(string $yaml): array
    {
        $path = $this->tempDir . '/config_' . uniqid('', true) . '.yaml';
        file_put_contents($path, $yaml);

        return $this->loader->load($path);
    }
}
