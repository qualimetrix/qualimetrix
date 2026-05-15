<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\ConfigSchema;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;

/**
 * Static guard for YAML key reachability through {@see YamlConfigLoader::load()}.
 *
 * This test exists because of three recurring bugs caused by the loader's
 * default snake_case → camelCase normalization model:
 *
 *  1. `architecture.allow.*` subtree (user-defined layer names mangled).
 *  2. `allow_cross_instance` deep-descendant long-form key mangled.
 *  3. `max_expanded_layers` scalar-leaf MIXED-root sub-key mangled — fixed
 *     in Phase 3.5 by migrating `architecture` to PRESERVE_SUBTREE (ADR
 *     0009); the row in {@see provideArchitectureSubKeyCases()} flipped
 *     from inverse pin to positive assertion.
 *
 * For every documented YAML key in every consumer (factory or schema
 * entry), this test:
 *
 *  - Writes a minimal YAML containing the key at its documented path.
 *  - Loads it through {@see YamlConfigLoader::load()} (the same entry point
 *    used by {@see \Qualimetrix\Configuration\Pipeline\Stage\ConfigFileStage}).
 *  - Asserts the key reaches the expected post-normalization path with the
 *    expected value.
 *
 * The test does NOT exercise factories or full validation — minimal YAML
 * typically lacks the other fields a factory needs. The single concern is
 * "does the YAML key survive the loader so that *something* downstream can
 * see it under the documented name?"
 *
 * Complementary to:
 *  - Plan Phase 3.3 ({@code ConfigSchema::sectionPolicies()} exhaustive
 *    coverage) — eliminates the bug class structurally.
 *  - Coverage-invariant guard test (Phase 3.3 DoD) — asserts every root
 *    key has an explicit policy entry.
 *
 * When Phase 3.5 lands and `architecture` migrates to `PRESERVE_SUBTREE`,
 * the currently-broken `max_expanded_layers` row in
 * {@see provideArchitectureSubKeyCases()} flips from documenting the bug
 * to asserting the fix.
 */
#[CoversClass(YamlConfigLoader::class)]
final class YamlKeyReachabilityTest extends TestCase
{
    private YamlConfigLoader $loader;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->loader = new YamlConfigLoader();
        $this->tempDir = sys_get_temp_dir() . '/qmx_yaml_key_reachability_' . uniqid();
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
     * Top-level keys (paths, format, fail_on, …) — driven by
     * {@see ConfigSchema::ENTRIES}. Snake_case inputs must reach the
     * documented camelCase form so downstream consumers
     * ({@see \Qualimetrix\Configuration\Pipeline\Stage\ConfigFileStage},
     * {@see \Qualimetrix\Configuration\AnalysisConfiguration}) find them.
     *
     * @param non-empty-string $yaml
     * @param non-empty-list<string|int> $path Dot-separated path through the
     *                                         post-normalization array.
     */
    #[Test]
    #[DataProvider('provideTopLevelKeyCases')]
    #[TestDox('top-level key $description survives loader normalization')]
    public function topLevelKeySurvivesNormalization(string $description, string $yaml, array $path, mixed $expectedValue): void
    {
        $config = $this->loadYaml($yaml);
        $this->assertPathReachesValue($config, $path, $expectedValue, $description);
    }

    /**
     * Section sub-keys: keys living under a section root
     * ({@code cache.*}, {@code namespace.*}, {@code aggregation.*},
     * {@code parallel.*}, {@code coupling.*}). The loader normalizes their
     * snake_case form to camelCase per {@see ConfigSchema::ENTRIES}.
     *
     * @param non-empty-list<string|int> $path
     */
    #[Test]
    #[DataProvider('provideSectionSubKeyCases')]
    #[TestDox('section sub-key $description survives loader normalization')]
    public function sectionSubKeySurvivesNormalization(string $description, string $yaml, array $path, mixed $expectedValue): void
    {
        $config = $this->loadYaml($yaml);
        $this->assertPathReachesValue($config, $path, $expectedValue, $description);
    }

    /**
     * Identifier sections ({@code rules}, {@code computedMetrics}):
     * immediate children (rule names, computed-metric names) preserve
     * snake_case / kebab-case verbatim; their nested option keys ARE
     * normalized to camelCase. Both halves must hold or downstream factories
     * never see the rule the user configured.
     *
     * @param non-empty-list<string|int> $path
     */
    #[Test]
    #[DataProvider('provideIdentifierSectionCases')]
    #[TestDox('identifier section $description preserves the identifier and normalizes options')]
    public function identifierSectionPreservesIdentifierAndNormalizesOptions(string $description, string $yaml, array $path, mixed $expectedValue): void
    {
        $config = $this->loadYaml($yaml);
        $this->assertPathReachesValue($config, $path, $expectedValue, $description);
    }

    /**
     * {@see \Qualimetrix\Architecture\Configuration\ArchitectureConfigurationFactory}
     * sub-keys: {@code layers}, {@code allow}, {@code coverage},
     * {@code max_expanded_layers}. Sub-keys of a MIXED root are
     * validated by the factory, not the schema. Since Phase 3.5 the
     * {@code architecture} root has policy {@code PRESERVE_SUBTREE}, so
     * every snake_case sub-key (including the {@code max_expanded_layers}
     * scalar leaf) survives normalization verbatim.
     *
     * @param non-empty-list<string|int> $path
     */
    #[Test]
    #[DataProvider('provideArchitectureSubKeyCases')]
    #[TestDox('architecture sub-key $description follows current loader behavior')]
    public function architectureSubKeyFollowsCurrentLoaderBehavior(string $description, string $yaml, array $path, mixed $expectedValue): void
    {
        $config = $this->loadYaml($yaml);
        $this->assertPathReachesValue($config, $path, $expectedValue, $description);
    }

    /**
     * Architecture layer entry keys — every documented key on a single
     * {@code architecture.layers[*]} entry plus its nested {@code exclude:}
     * block ({@see \Qualimetrix\Architecture\Configuration\Validation\LayersValidator::ALLOWED_ENTRY_KEYS},
     * {@see \Qualimetrix\Architecture\Configuration\Validation\ExcludeBlockValidator::ALLOWED_EXCLUDE_KEYS}).
     *
     * Layer entries are sequential list items — their inner keys are
     * leaf-level config and must survive untouched (and they do: every
     * documented entry key is already lowercase or a single word).
     *
     * @param non-empty-list<string|int> $path
     */
    #[Test]
    #[DataProvider('provideArchitectureLayerEntryCases')]
    #[TestDox('architecture.layers entry key $description survives loader normalization')]
    public function architectureLayerEntryKeySurvivesNormalization(string $description, string $yaml, array $path, mixed $expectedValue): void
    {
        $config = $this->loadYaml($yaml);
        $this->assertPathReachesValue($config, $path, $expectedValue, $description);
    }

    /**
     * Architecture allow subtree — verified verbatim under the
     * {@code architecture} section's {@code PRESERVE_SUBTREE} policy
     * ({@see ConfigSchema::sectionPolicies()}). Covers:
     *  - source layer name keys (the immediate child level, user identifiers)
     *  - long-form target keys ({@code target}, {@code relations},
     *    {@code allow_cross_instance}) deep below.
     *
     * @param non-empty-list<string|int> $path
     */
    #[Test]
    #[DataProvider('provideArchitectureAllowCases')]
    #[TestDox('architecture.allow $description preserves snake_case verbatim')]
    public function architectureAllowSubtreePreservesSnakeCase(string $description, string $yaml, array $path, mixed $expectedValue): void
    {
        $config = $this->loadYaml($yaml);
        $this->assertPathReachesValue($config, $path, $expectedValue, $description);
    }

    /**
     * @return iterable<string, array{string, string, non-empty-list<string|int>, mixed}>
     */
    public static function provideTopLevelKeyCases(): iterable
    {
        yield 'paths (list)' => [
            'paths',
            "paths:\n  - src\n  - tests\n",
            ['paths'],
            ['src', 'tests'],
        ];

        // ConfigSchema entry: sourcePath='exclude' resultKey='excludes' — but the
        // loader returns RAW post-normalization, so the user-written `exclude`
        // key stays as is (it is already a single word; no snake_case to mangle).
        // The 'exclude' → 'excludes' rename happens in ConfigDataNormalizer, not here.
        yield 'exclude (list)' => [
            'exclude',
            "exclude:\n  - vendor\n",
            ['exclude'],
            ['vendor'],
        ];

        yield 'format (scalar)' => [
            'format',
            "format: json\n",
            ['format'],
            'json',
        ];

        yield 'disabled_rules → disabledRules (list)' => [
            'disabled_rules',
            "disabled_rules:\n  - complexity.cyclomatic\n",
            ['disabledRules'],
            ['complexity.cyclomatic'],
        ];

        yield 'only_rules → onlyRules (list)' => [
            'only_rules',
            "only_rules:\n  - complexity.cyclomatic\n",
            ['onlyRules'],
            ['complexity.cyclomatic'],
        ];

        yield 'exclude_paths → excludePaths (list)' => [
            'exclude_paths',
            "exclude_paths:\n  - src/Generated/*\n",
            ['excludePaths'],
            ['src/Generated/*'],
        ];

        yield 'exclude_namespaces → excludeNamespaces (list)' => [
            'exclude_namespaces',
            "exclude_namespaces:\n  - App\\Generated\n",
            ['excludeNamespaces'],
            ['App\\Generated'],
        ];

        yield 'fail_on → failOn (scalar)' => [
            'fail_on',
            "fail_on: error\n",
            ['failOn'],
            'error',
        ];

        yield 'exclude_health → excludeHealth (list)' => [
            'exclude_health',
            "exclude_health:\n  - tests/**\n",
            ['excludeHealth'],
            ['tests/**'],
        ];

        yield 'include_generated → includeGenerated (scalar bool)' => [
            'include_generated',
            "include_generated: true\n",
            ['includeGenerated'],
            true,
        ];

        yield 'memory_limit → memoryLimit (scalar)' => [
            'memory_limit',
            "memory_limit: 512M\n",
            ['memoryLimit'],
            '512M',
        ];
    }

    /**
     * @return iterable<string, array{string, string, non-empty-list<string|int>, mixed}>
     */
    public static function provideSectionSubKeyCases(): iterable
    {
        yield 'cache.dir (string)' => [
            'cache.dir',
            "cache:\n  dir: .qmx-cache\n",
            ['cache', 'dir'],
            '.qmx-cache',
        ];

        yield 'cache.enabled (bool)' => [
            'cache.enabled',
            "cache:\n  enabled: true\n",
            ['cache', 'enabled'],
            true,
        ];

        yield 'namespace.strategy (string)' => [
            'namespace.strategy',
            "namespace:\n  strategy: psr4\n",
            ['namespace', 'strategy'],
            'psr4',
        ];

        yield 'namespace.composer_json → namespace.composerJson (string)' => [
            'namespace.composer_json',
            "namespace:\n  composer_json: ./composer.json\n",
            ['namespace', 'composerJson'],
            './composer.json',
        ];

        yield 'aggregation.prefixes (list)' => [
            'aggregation.prefixes',
            "aggregation:\n  prefixes:\n    - App\n",
            ['aggregation', 'prefixes'],
            ['App'],
        ];

        yield 'aggregation.auto_depth → aggregation.autoDepth (bool)' => [
            'aggregation.auto_depth',
            "aggregation:\n  auto_depth: true\n",
            ['aggregation', 'autoDepth'],
            true,
        ];

        yield 'parallel.workers (int)' => [
            'parallel.workers',
            "parallel:\n  workers: 4\n",
            ['parallel', 'workers'],
            4,
        ];

        yield 'coupling.framework_namespaces → coupling.frameworkNamespaces (list)' => [
            'coupling.framework_namespaces',
            "coupling:\n  framework_namespaces:\n    - Symfony\\\n",
            ['coupling', 'frameworkNamespaces'],
            ['Symfony\\'],
        ];
    }

    /**
     * @return iterable<string, array{string, string, non-empty-list<string|int>, mixed}>
     */
    public static function provideIdentifierSectionCases(): iterable
    {
        // rules.<rule-name> — name preserved verbatim (identifier section).
        yield 'rules: dotted rule name preserved' => [
            'rules.complexity.cyclomatic',
            "rules:\n  complexity.cyclomatic:\n    enabled: true\n",
            ['rules', 'complexity.cyclomatic', 'enabled'],
            true,
        ];

        yield 'rules: kebab-case rule name preserved' => [
            'rules.cyclomatic-complexity',
            "rules:\n  cyclomatic-complexity:\n    enabled: true\n",
            ['rules', 'cyclomatic-complexity', 'enabled'],
            true,
        ];

        yield 'rules: snake_case rule name preserved' => [
            'rules.namespace_size',
            "rules:\n  namespace_size:\n    enabled: true\n",
            ['rules', 'namespace_size', 'enabled'],
            true,
        ];

        // Rule option keys: snake_case normalized to camelCase under the
        // (preserved) rule identifier.
        yield 'rules: option warning_threshold → warningThreshold' => [
            'rules.complexity.cyclomatic.warning_threshold',
            "rules:\n  complexity.cyclomatic:\n    warning_threshold: 10\n",
            ['rules', 'complexity.cyclomatic', 'warningThreshold'],
            10,
        ];

        yield 'rules: option error_threshold → errorThreshold' => [
            'rules.complexity.cyclomatic.error_threshold',
            "rules:\n  complexity.cyclomatic:\n    error_threshold: 20\n",
            ['rules', 'complexity.cyclomatic', 'errorThreshold'],
            20,
        ];

        yield 'rules: nested hierarchical option preserved.method.warning' => [
            'rules.complexity.method.warning',
            "rules:\n  complexity:\n    method:\n      warning: 12\n",
            ['rules', 'complexity', 'method', 'warning'],
            12,
        ];

        // computed_metrics → computedMetrics root; child identifiers preserved.
        yield 'computed_metrics → computedMetrics root key' => [
            'computed_metrics',
            "computed_metrics:\n  computed.my_score:\n    formula: 'loc * 2'\n",
            ['computedMetrics', 'computed.my_score', 'formula'],
            'loc * 2',
        ];

        yield 'computed_metrics: dotted metric name preserved' => [
            'computed_metrics.computed.my_score',
            "computed_metrics:\n  computed.my_score:\n    formula: 'loc'\n",
            ['computedMetrics', 'computed.my_score'],
            ['formula' => 'loc'],
        ];

        yield 'computed_metrics: option warning_threshold → warningThreshold' => [
            'computed_metrics.<name>.warning_threshold',
            "computed_metrics:\n  computed.my_score:\n    formula: 'loc'\n    warning_threshold: 80\n",
            ['computedMetrics', 'computed.my_score', 'warningThreshold'],
            80,
        ];
    }

    /**
     * @return iterable<string, array{string, string, non-empty-list<string|int>, mixed}>
     */
    public static function provideArchitectureSubKeyCases(): iterable
    {
        // `layers` is a single-word key — survives unchanged.
        yield 'architecture.layers (single-word key)' => [
            'architecture.layers',
            "architecture:\n  layers:\n    - name: app\n      patterns: ['App']\n",
            ['architecture', 'layers', 0, 'name'],
            'app',
        ];

        // `allow` is a single-word key — survives unchanged. (Subtree
        // verbatim-preservation covered by provideArchitectureAllowCases.)
        yield 'architecture.allow (single-word key)' => [
            'architecture.allow',
            "architecture:\n  layers:\n    - name: a\n      patterns: ['A']\n    - name: b\n      patterns: ['B']\n  allow:\n    a:\n      - b\n",
            ['architecture', 'allow', 'a'],
            ['b'],
        ];

        // `coverage` is a single-word key — survives unchanged.
        yield 'architecture.coverage (single-word scalar)' => [
            'architecture.coverage',
            "architecture:\n  layers:\n    - name: a\n      patterns: ['A']\n  coverage: ignore\n",
            ['architecture', 'coverage'],
            'ignore',
        ];

        // FIXED in Phase 3.5 (ADR 0009): `architecture` migrated to
        // PRESERVE_SUBTREE, so the `max_expanded_layers` scalar leaf
        // survives normalization verbatim and reaches
        // ArchitectureConfigurationFactory under the snake_case spelling
        // it looks for. Independent consumer-expectation test at
        // {@see \Qualimetrix\Tests\Integration\Architecture\MaxExpandedLayersFromYamlTest}
        // verifies the end-to-end factory wiring.
        yield 'architecture.max_expanded_layers (PRESERVE_SUBTREE — fixed in Phase 3.5)' => [
            'architecture.max_expanded_layers (snake_case preserved verbatim by section policy)',
            "architecture:\n  layers:\n    - name: a\n      patterns: ['A']\n  max_expanded_layers: 256\n",
            ['architecture', 'max_expanded_layers'],
            256,
        ];
    }

    /**
     * @return iterable<string, array{string, string, non-empty-list<string|int>, mixed}>
     */
    public static function provideArchitectureLayerEntryCases(): iterable
    {
        $base = "architecture:\n  layers:\n";

        yield 'layers[].name (preserved verbatim)' => [
            'architecture.layers[].name',
            $base . "    - name: my-layer\n      patterns: ['App']\n",
            ['architecture', 'layers', 0, 'name'],
            'my-layer',
        ];

        yield 'layers[].patterns (list of strings)' => [
            'architecture.layers[].patterns',
            $base . "    - name: a\n      patterns:\n        - 'App\\Pattern'\n",
            ['architecture', 'layers', 0, 'patterns'],
            ['App\\Pattern'],
        ];

        yield 'layers[].suffix (string)' => [
            'architecture.layers[].suffix',
            $base . "    - name: a\n      suffix: 'Service'\n",
            ['architecture', 'layers', 0, 'suffix'],
            'Service',
        ];

        yield 'layers[].attributes (list)' => [
            'architecture.layers[].attributes',
            $base . "    - name: a\n      attributes:\n        - 'App\\Attr'\n",
            ['architecture', 'layers', 0, 'attributes'],
            ['App\\Attr'],
        ];

        yield 'layers[].implements (list)' => [
            'architecture.layers[].implements',
            $base . "    - name: a\n      implements:\n        - 'App\\Iface'\n",
            ['architecture', 'layers', 0, 'implements'],
            ['App\\Iface'],
        ];

        yield 'layers[].extends (list)' => [
            'architecture.layers[].extends',
            $base . "    - name: a\n      extends:\n        - 'App\\Base'\n",
            ['architecture', 'layers', 0, 'extends'],
            ['App\\Base'],
        ];

        yield 'layers[].match (string)' => [
            'architecture.layers[].match',
            $base . "    - name: a\n      patterns: ['App']\n      match: any\n",
            ['architecture', 'layers', 0, 'match'],
            'any',
        ];

        // Nested exclude block keys — note `match` and the criterion keys
        // are all single-word, so they survive trivially. The shape itself
        // is the contract.
        yield 'layers[].exclude.patterns (list, nested map)' => [
            'architecture.layers[].exclude.patterns',
            $base . "    - name: a\n      patterns: ['App']\n      exclude:\n        patterns:\n          - 'App\\Legacy\\**'\n",
            ['architecture', 'layers', 0, 'exclude', 'patterns'],
            ['App\\Legacy\\**'],
        ];

        yield 'layers[].exclude.suffix (string)' => [
            'architecture.layers[].exclude.suffix',
            $base . "    - name: a\n      patterns: ['App']\n      exclude:\n        suffix: 'Test'\n",
            ['architecture', 'layers', 0, 'exclude', 'suffix'],
            'Test',
        ];

        yield 'layers[].exclude.attributes (list)' => [
            'architecture.layers[].exclude.attributes',
            $base . "    - name: a\n      patterns: ['App']\n      exclude:\n        attributes:\n          - 'App\\Attr'\n",
            ['architecture', 'layers', 0, 'exclude', 'attributes'],
            ['App\\Attr'],
        ];

        yield 'layers[].exclude.implements (list)' => [
            'architecture.layers[].exclude.implements',
            $base . "    - name: a\n      patterns: ['App']\n      exclude:\n        implements:\n          - 'App\\Iface'\n",
            ['architecture', 'layers', 0, 'exclude', 'implements'],
            ['App\\Iface'],
        ];

        yield 'layers[].exclude.extends (list)' => [
            'architecture.layers[].exclude.extends',
            $base . "    - name: a\n      patterns: ['App']\n      exclude:\n        extends:\n          - 'App\\Base'\n",
            ['architecture', 'layers', 0, 'exclude', 'extends'],
            ['App\\Base'],
        ];

        yield 'layers[].exclude.match (string)' => [
            'architecture.layers[].exclude.match',
            $base . "    - name: a\n      patterns: ['App']\n      exclude:\n        patterns: ['X']\n        match: any\n",
            ['architecture', 'layers', 0, 'exclude', 'match'],
            'any',
        ];

        // Template layer captures (curly braces in name) preserve verbatim.
        yield 'layers[].name with capture variable preserved' => [
            'architecture.layers[].name (template)',
            $base . "    - name: 'app-{m}'\n      patterns: ['App\\{m}\\App']\n",
            ['architecture', 'layers', 0, 'name'],
            'app-{m}',
        ];
    }

    /**
     * @return iterable<string, array{string, string, non-empty-list<string|int>, mixed}>
     */
    public static function provideArchitectureAllowCases(): iterable
    {
        $layers = "architecture:\n  layers:\n    - name: a\n      patterns: ['A']\n    - name: b\n      patterns: ['B']\n";

        yield 'snake_case source layer name preserved as map key' => [
            'architecture.allow.<snake_case_source>',
            "architecture:\n  layers:\n    - name: app_core\n      patterns: ['Core']\n    - name: app_service\n      patterns: ['Service']\n  allow:\n    app_core:\n      - app_service\n",
            ['architecture', 'allow', 'app_core'],
            ['app_service'],
        ];

        yield 'kebab-case source layer name preserved as map key' => [
            'architecture.allow.<kebab-source>',
            "architecture:\n  layers:\n    - name: 'app-core'\n      patterns: ['Core']\n    - name: 'app-service'\n      patterns: ['Service']\n  allow:\n    'app-core':\n      - 'app-service'\n",
            ['architecture', 'allow', 'app-core'],
            ['app-service'],
        ];

        yield 'capture-variable source layer template preserved as map key' => [
            'architecture.allow.<template-source>',
            "architecture:\n  layers:\n    - name: 'app-orders'\n      patterns: ['App\\Orders\\App']\n    - name: 'domain-orders'\n      patterns: ['App\\Orders\\Domain']\n  allow:\n    'app-{m}':\n      - 'domain-{m}'\n",
            ['architecture', 'allow', 'app-{m}'],
            ['domain-{m}'],
        ];

        yield 'long-form target key preserved' => [
            'architecture.allow.<src>[].target',
            $layers . "  allow:\n    a:\n      - target: b\n",
            ['architecture', 'allow', 'a', 0, 'target'],
            'b',
        ];

        yield 'long-form relations key preserved (list of tokens)' => [
            'architecture.allow.<src>[].relations',
            $layers . "  allow:\n    a:\n      - target: b\n        relations:\n          - static_call\n",
            ['architecture', 'allow', 'a', 0, 'relations'],
            ['static_call'],
        ];

        yield 'long-form allow_cross_instance preserved (snake_case scalar)' => [
            'architecture.allow.<src>[].allow_cross_instance',
            $layers . "  allow:\n    a:\n      - target: b\n        allow_cross_instance: true\n",
            ['architecture', 'allow', 'a', 0, 'allow_cross_instance'],
            true,
        ];

        // Documenting subtree preservation: even if a user invented a
        // snake_case key under allow (a typo or future field), it would
        // survive verbatim — that's the contract of the
        // architecture section's PRESERVE_SUBTREE policy
        // (ConfigSchema::sectionPolicies()). The downstream long-form
        // normalizer will reject the unknown key, but the loader must
        // NOT have mangled it on the way in.
        yield 'unknown long-form snake_case key reaches validator verbatim' => [
            'architecture.allow.<src>[].future_snake_key',
            $layers . "  allow:\n    a:\n      - target: b\n        future_snake_key: 'whatever'\n",
            ['architecture', 'allow', 'a', 0, 'future_snake_key'],
            'whatever',
        ];
    }

    /**
     * Schema-coverage smoke test: every documented top-level key in
     * {@see ConfigSchema::ENTRIES} appears in at least one positive case.
     * Catches the failure mode where a contributor adds a new ENTRIES row
     * but forgets to add a reachability case.
     *
     * Architecture is checked via the dedicated providers above (not as a
     * single top-level row).
     */
    #[Test]
    public function everyDocumentedRootKeyHasAReachabilityCase(): void
    {
        $covered = self::collectCoveredRootKeys();

        $missing = [];
        foreach (ConfigSchema::ENTRIES as [$sourcePath, $resultKey, $rootType]) {
            $root = str_contains($sourcePath, '.') ? explode('.', $sourcePath, 2)[0] : $sourcePath;
            if (isset($covered[$root])) {
                continue;
            }
            $missing[] = $root;
        }

        self::assertSame(
            [],
            array_values(array_unique($missing)),
            'Every ConfigSchema::ENTRIES root key must have at least one reachability case. '
            . 'Missing roots: ' . implode(', ', $missing) . '. '
            . 'Add a case to the appropriate dataProvider in ' . __CLASS__ . '.',
        );
    }

    /**
     * @return array<string, true>
     */
    private static function collectCoveredRootKeys(): array
    {
        $covered = [];

        $allCases = [
            ...iterator_to_array(self::provideTopLevelKeyCases(), false),
            ...iterator_to_array(self::provideSectionSubKeyCases(), false),
            ...iterator_to_array(self::provideIdentifierSectionCases(), false),
            ...iterator_to_array(self::provideArchitectureSubKeyCases(), false),
            ...iterator_to_array(self::provideArchitectureLayerEntryCases(), false),
            ...iterator_to_array(self::provideArchitectureAllowCases(), false),
        ];

        foreach ($allCases as $case) {
            $first = $case[2][0] ?? null;
            if (\is_string($first)) {
                $covered[$first] = true;
            }
        }

        return $covered;
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

    /**
     * Walks the post-normalization array along {@code $path} and asserts the
     * leaf equals {@code $expected}. Each path segment is either a string
     * (associative key) or an integer (sequential list index).
     *
     * @param array<string, mixed> $config
     * @param non-empty-list<string|int> $path
     */
    private function assertPathReachesValue(array $config, array $path, mixed $expected, string $description): void
    {
        $cursor = $config;
        $traversed = [];

        foreach ($path as $segment) {
            $traversed[] = (string) $segment;
            $parentPath = \array_slice($traversed, 0, -1);
            $parentLabel = $parentPath === [] ? '<root>' : implode('.', $parentPath);

            if (\is_int($segment)) {
                self::assertIsArray(
                    $cursor,
                    \sprintf('%s: expected list at path "%s", got %s.', $description, implode('.', $traversed), get_debug_type($cursor)),
                );
                self::assertArrayHasKey(
                    $segment,
                    $cursor,
                    \sprintf('%s: list at "%s" missing index %d.', $description, $parentLabel, $segment),
                );
            } else {
                self::assertIsArray(
                    $cursor,
                    \sprintf('%s: expected map at path "%s", got %s.', $description, implode('.', $traversed), get_debug_type($cursor)),
                );
                self::assertArrayHasKey(
                    $segment,
                    $cursor,
                    \sprintf(
                        '%s: key "%s" not reachable at path "%s" — present keys: %s.',
                        $description,
                        $segment,
                        implode('.', $traversed),
                        implode(', ', array_map(static fn($k) => (string) $k, array_keys($cursor))),
                    ),
                );
            }

            $cursor = $cursor[$segment];
        }

        self::assertSame(
            $expected,
            $cursor,
            \sprintf('%s: value at path "%s" does not match expected.', $description, implode('.', $traversed)),
        );
    }
}
