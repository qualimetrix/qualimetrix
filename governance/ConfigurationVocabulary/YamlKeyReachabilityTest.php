<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ConfigurationVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;
use ReflectionClass;
use ReflectionMethod;

/** Verifies canonical paths through the compiled document and preserves each illegal original as a refusal. */
#[CoversClass(YamlConfigLoader::class)]
final class YamlKeyReachabilityTest extends TestCase
{
    private ConfigurationPipelineInterface $pipeline;

    private string $tempDir;

    protected function setUp(): void
    {
        $pipeline = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $pipeline->addStage(new ConfigFileStage(new YamlConfigLoader()));
        $this->pipeline = $pipeline;
        $this->tempDir = sys_get_temp_dir() . '/qmx_yaml_key_reachability_' . bin2hex(random_bytes(6));
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
     * @param non-empty-string $yaml
     * @param non-empty-list<string|int> $path Dot-separated path through the
     * @param array{string, list<string>, string}|null $refusal
     */
    #[Test]
    #[DataProvider('provideTopLevelKeyCases')]
    #[TestDox('top-level key $description survives loader normalization')]
    public function itLetsATopLevelKeySurviveNormalization(string $description, string $yaml, array $path, mixed $expectedValue, ?array $refusal = null, ?string $companion = null): void
    {
        $config = $this->acceptedYaml($yaml, $refusal, $companion);
        $this->assertPathReachesValue($config, $path, $expectedValue, $description);
    }

    /**
     * @param non-empty-list<string|int> $path
     * @param array{string, list<string>, string}|null $refusal
     */
    #[Test]
    #[DataProvider('provideSectionSubKeyCases')]
    #[TestDox('section sub-key $description survives loader normalization')]
    public function itLetsASectionSubKeySurviveNormalization(string $description, string $yaml, array $path, mixed $expectedValue, ?array $refusal = null, ?string $companion = null): void
    {
        $config = $this->acceptedYaml($yaml, $refusal, $companion);
        $this->assertPathReachesValue($config, $path, $expectedValue, $description);
    }

    /**
     * @param non-empty-list<string|int> $path
     * @param array{string, list<string>, string}|null $refusal
     */
    #[Test]
    #[DataProvider('provideIdentifierSectionCases')]
    #[TestDox('identifier section $description preserves the identifier and normalizes options')]
    public function itPreservesTheIdentifierAndNormalizesOptionsInAnIdentifierSection(string $description, string $yaml, array $path, mixed $expectedValue, ?array $refusal = null, ?string $companion = null): void
    {
        $config = $this->acceptedYaml($yaml, $refusal, $companion);
        $this->assertPathReachesValue($config, $path, $expectedValue, $description);
    }

    /**
     * @param non-empty-list<string|int> $path
     * @param array{string, list<string>, string}|null $refusal
     */
    #[Test]
    #[DataProvider('provideArchitectureSubKeyCases')]
    #[TestDox('architecture sub-key $description follows current loader behavior')]
    public function itFollowsTheCurrentLoaderBehaviorForAnArchitectureSubKey(string $description, string $yaml, array $path, mixed $expectedValue, ?array $refusal = null, ?string $companion = null): void
    {
        $config = $this->acceptedYaml($yaml, $refusal, $companion);
        $this->assertPathReachesValue($config, $path, $expectedValue, $description);
    }

    /**
     * @param non-empty-list<string|int> $path
     * @param array{string, list<string>, string}|null $refusal
     */
    #[Test]
    #[DataProvider('provideArchitectureLayerEntryCases')]
    #[TestDox('architecture.layers entry key $description survives loader normalization')]
    public function itLetsAnArchitectureLayerEntryKeySurviveNormalization(string $description, string $yaml, array $path, mixed $expectedValue, ?array $refusal = null, ?string $companion = null): void
    {
        $config = $this->acceptedYaml($yaml, $refusal, $companion);
        $this->assertPathReachesValue($config, $path, $expectedValue, $description);
    }

    /**
     * @param non-empty-list<string|int> $path
     * @param array{string, list<string>, string}|null $refusal
     */
    #[Test]
    #[DataProvider('provideArchitectureAllowCases')]
    #[TestDox('architecture.allow $description preserves snake_case verbatim')]
    public function itPreservesSnakeCaseInTheArchitectureAllowSubtree(string $description, string $yaml, array $path, mixed $expectedValue, ?array $refusal = null, ?string $companion = null): void
    {
        $config = $this->acceptedYaml($yaml, $refusal, $companion);
        $this->assertPathReachesValue($config, $path, $expectedValue, $description);
    }

    /**
     * @return iterable<string, array{string, string, non-empty-list<string|int>, mixed, 4?: array{string, list<string>, string}, 5?: string}>
     */
    public static function provideTopLevelKeyCases(): iterable
    {
        yield 'paths (list)' => [
            'paths',
            "paths:\n  - src\n  - tests\n",
            ['paths'],
            ['src', 'tests'],
        ];

        yield 'exclude (list)' => [
            'exclude',
            "exclude:\n  - vendor\n",
            ['exclude'],
            [['subtree' => 'vendor']],
            ['"exclude[0]" in configuration file "{actual_config_path}" must be a map, got string. A selector names its kind: {exact: value}, {subtree: value}, or {regex: value}.', ['exclude', '0'], '0'],
            "exclude:\n  - subtree: vendor\n",
        ];

        yield 'format (scalar)' => [
            'format',
            "format: json\n",
            ['format'],
            'json',
        ];

        yield 'disabled_rules → disabledRules (list)' => [
            'disabled_rules',
            "disabled_rules:\n  - complexity.ccn\n",
            ['disabled_rules'],
            ['complexity.ccn'],
        ];

        yield 'only_rules → onlyRules (list)' => [
            'only_rules',
            "only_rules:\n  - complexity.ccn\n",
            ['only_rules'],
            ['complexity.ccn'],
        ];

        yield 'suppress_paths → suppressPaths (list)' => [
            'suppress_paths',
            "suppress_paths:\n  - src/Generated/*\n",
            ['suppress_paths'],
            [['exact' => 'src/Generated/*']],
            ['"suppress_paths[0]" in configuration file "{actual_config_path}" must be a map, got string. A selector names its kind: {exact: value}, {subtree: value}, or {regex: value}.', ['suppress_paths', '0'], '0'],
            "suppress_paths:\n  - exact: 'src/Generated/*'\n",
        ];

        yield 'suppress_namespaces → suppressNamespaces (list)' => [
            'suppress_namespaces',
            "suppress_namespaces:\n  - App\\Generated\n",
            ['suppress_namespaces'],
            [['subtree' => 'App\\Generated']],
            ['"suppress_namespaces[0]" in configuration file "{actual_config_path}" must be a map, got string. A selector names its kind: {exact: value}, {subtree: value}, or {regex: value}.', ['suppress_namespaces', '0'], '0'],
            "suppress_namespaces:\n  - subtree: 'App\\Generated'\n",
        ];

        yield 'fail_on → failOn (scalar)' => [
            'fail_on',
            "fail_on: error\n",
            ['fail_on'],
            'error',
        ];

        yield 'exclude_health → exclude_health (list)' => [
            'exclude_health',
            "exclude_health:\n  - tests/**\n",
            ['exclude_health'],
            ['tests/**'],
        ];

        yield 'include_generated → includeGenerated (scalar bool)' => [
            'include_generated',
            "include_generated: true\n",
            ['include_generated'],
            true,
        ];

        yield 'include_autoload_dev → includeAutoloadDev (scalar bool)' => [
            'include_autoload_dev',
            "include_autoload_dev: true\n",
            ['include_autoload_dev'],
            true,
        ];

        yield 'memory_limit → memoryLimit (scalar)' => [
            'memory_limit',
            "memory_limit: 512M\n",
            ['memory_limit'],
            '512M',
        ];
    }

    /**
     * @return iterable<string, array{string, string, non-empty-list<string|int>, mixed, 4?: array{string, list<string>, string}, 5?: string}>
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

        yield 'parallel.workers (int)' => [
            'parallel.workers',
            "parallel:\n  workers: 4\n",
            ['parallel', 'workers'],
            4,
        ];

        yield 'coupling.framework_namespaces → coupling.frameworkNamespaces (list)' => [
            'coupling.framework_namespaces',
            "coupling:\n  framework_namespaces:\n    - Symfony\\\n",
            ['coupling', 'framework_namespaces'],
            [['subtree' => 'Symfony']],
            ['"coupling.framework_namespaces[0]" in configuration file "{actual_config_path}" must be a map, got string.', ['coupling', 'framework_namespaces', '0'], '0'],
            "coupling:\n  framework_namespaces:\n    - subtree: Symfony\n",
        ];
    }

    /**
     * @return iterable<string, array{string, string, non-empty-list<string|int>, mixed, 4?: array{string, list<string>, string}, 5?: string}>
     */
    public static function provideIdentifierSectionCases(): iterable
    {

        yield 'rules: dotted rule name preserved' => [
            'rules.complexity.ccn',
            "rules:\n  complexity.ccn:\n    enabled: true\n",
            ['rules', 'complexity.ccn', 'enabled'],
            true,
        ];

        yield 'rules: kebab-case rule name preserved' => [
            'rules.cyclomatic-complexity',
            "rules:\n  cyclomatic-complexity:\n    enabled: true\n",
            ['rules', 'complexity.ccn', 'enabled'],
            true,
            ['Rule option owner "cyclomatic-complexity" does not match any registered producer rule.', ['rules', 'cyclomatic-complexity'], 'cyclomatic-complexity'],
            "rules:\n  complexity.ccn:\n    enabled: true\n",
        ];

        yield 'rules: snake_case rule name preserved' => [
            'rules.namespace_size',
            "rules:\n  namespace_size:\n    enabled: true\n",
            ['rules', 'size.class-count', 'enabled'],
            true,
            ['Rule option owner "namespace_size" does not match any registered producer rule.', ['rules', 'namespace_size'], 'namespace_size'],
            "rules:\n  size.class-count:\n    enabled: true\n",
        ];

        yield 'rules: option warning_threshold → warningThreshold' => [
            'rules.complexity.ccn.warning_threshold',
            "rules:\n  complexity.ccn:\n    warning_threshold: 10\n",
            ['rules', 'complexity.ccn', 'callable', 'warning'],
            10,
            ['Unknown key "rules.complexity.ccn.warning_threshold" in configuration file "{actual_config_path}". Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.', ['rules', 'complexity.ccn', 'warning_threshold'], 'warning_threshold'],
            "rules:\n  complexity.ccn:\n    callable:\n      warning: 10\n",
        ];

        yield 'rules: option error_threshold → errorThreshold' => [
            'rules.complexity.ccn.error_threshold',
            "rules:\n  complexity.ccn:\n    error_threshold: 20\n",
            ['rules', 'complexity.ccn', 'callable', 'error'],
            20,
            ['Unknown key "rules.complexity.ccn.error_threshold" in configuration file "{actual_config_path}". Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.', ['rules', 'complexity.ccn', 'error_threshold'], 'error_threshold'],
            "rules:\n  complexity.ccn:\n    callable:\n      error: 20\n",
        ];

        yield 'rules: nested hierarchical option preserved.callable.warning' => [
            'rules.complexity.callable.warning',
            "rules:\n  complexity:\n    callable:\n      warning: 12\n",
            ['rules', 'complexity.ccn', 'callable', 'warning'],
            12,
            ['Rule option owner "complexity" does not match any registered producer rule.', ['rules', 'complexity'], 'complexity'],
            "rules:\n  complexity.ccn:\n    callable:\n      warning: 12\n",
        ];

        yield 'computed_metrics → computed_metrics root key' => [
            'computed_metrics',
            "computed_metrics:\n  computed.my-score:\n    formula: 'loc * 2'\n",
            ['computed_metrics', 'computed.my-score', 'formula'],
            'm["size.loc"] * 2',
            ['Invalid formula syntax for computed metric "computed.my-score": Variable "loc" is not valid around position 1 for expression `loc * 2`. (formula: loc * 2)', ['computed_metrics', 'computed.my-score', 'formula'], 'formula'],
            "computed_metrics:\n  computed.my-score:\n    formula: 'm[\"size.loc\"] * 2'\n",
        ];

        yield 'computed_metrics: dotted metric name preserved' => [
            'computed_metrics.computed.my-score',
            "computed_metrics:\n  computed.my-score:\n    formula: 'size.loc'\n",
            ['computed_metrics', 'computed.my-score'],
            ['formula' => 'm["size.loc"]'],
            ['Invalid formula syntax for computed metric "computed.my-score": Variable "size" is not valid around position 1 for expression `size.loc`. (formula: size.loc)', ['computed_metrics', 'computed.my-score', 'formula'], 'formula'],
            "computed_metrics:\n  computed.my-score:\n    formula: 'm[\"size.loc\"]'\n",
        ];

        yield 'computed_metrics: option warning_threshold → warningThreshold' => [
            'computed_metrics.<name>.warning_threshold',
            "computed_metrics:\n  computed.my-score:\n    formula: 'size.loc'\n    warning_threshold: 80\n",
            ['computed_metrics', 'computed.my-score', 'warning'],
            80,
            ['Invalid formula syntax for computed metric "computed.my-score": Variable "size" is not valid around position 1 for expression `size.loc`. (formula: size.loc)', ['computed_metrics', 'computed.my-score', 'formula'], 'formula'],
            "computed_metrics:\n  computed.my-score:\n    formula: 'm[\"size.loc\"]'\n    warning: 80\n",
        ];
    }

    /**
     * @return iterable<string, array{string, string, non-empty-list<string|int>, mixed, 4?: array{string, list<string>, string}, 5?: string}>
     */
    public static function provideArchitectureSubKeyCases(): iterable
    {

        yield 'architecture.layers (single-word key)' => [
            'architecture.layers',
            "architecture:\n  layers:\n    - name: app\n      patterns: ['App']\n",
            ['architecture', 'layers', 0, 'name'],
            'app',
        ];

        yield 'architecture.allow (single-word key)' => [
            'architecture.allow',
            "architecture:\n  layers:\n    - name: a\n      patterns: ['A']\n    - name: b\n      patterns: ['B']\n  allow:\n    a:\n      - b\n",
            ['architecture', 'allow', 'a'],
            [['b']],
        ];

        yield 'architecture.coverage-gap (single-word scalar)' => [
            'architecture.coverage-gap',
            "architecture:\n  layers:\n    - name: a\n      patterns: ['A']\n  coverage-gap: ignore\n",
            ['architecture', 'coverage-gap'],
            'ignore',
        ];

        yield 'architecture.max_expanded_layers (PRESERVE_SUBTREE — fixed in Phase 3.5)' => [
            'architecture.max_expanded_layers (snake_case preserved verbatim by section policy)',
            "architecture:\n  layers:\n    - name: a\n      patterns: ['A']\n  max_expanded_layers: 256\n",
            ['architecture', 'max_expanded_layers'],
            256,
        ];
    }

    /**
     * @return iterable<string, array{string, string, non-empty-list<string|int>, mixed, 4?: array{string, list<string>, string}, 5?: string}>
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
            [['App\\Pattern']],
        ];

        yield 'layers[].suffix (string)' => [
            'architecture.layers[].suffix',
            $base . "    - name: a\n      suffix: 'Service'\n",
            ['architecture', 'layers', 0, 'suffix'],
            ['Service'],
        ];

        yield 'layers[].attributes (list)' => [
            'architecture.layers[].attributes',
            $base . "    - name: a\n      attributes:\n        - 'App\\Attr'\n",
            ['architecture', 'layers', 0, 'attributes'],
            [['App\\Attr']],
        ];

        yield 'layers[].implements (list)' => [
            'architecture.layers[].implements',
            $base . "    - name: a\n      implements:\n        - 'App\\Iface'\n",
            ['architecture', 'layers', 0, 'implements'],
            [['App\\Iface']],
        ];

        yield 'layers[].extends (list)' => [
            'architecture.layers[].extends',
            $base . "    - name: a\n      extends:\n        - 'App\\Base'\n",
            ['architecture', 'layers', 0, 'extends'],
            [['App\\Base']],
        ];

        yield 'layers[].match (string)' => [
            'architecture.layers[].match',
            $base . "    - name: a\n      patterns: ['App']\n      match: any\n",
            ['architecture', 'layers', 0, 'match'],
            'any',
        ];

        yield 'layers[].exclude.patterns (list, nested map)' => [
            'architecture.layers[].exclude.patterns',
            $base . "    - name: a\n      patterns: ['App']\n      exclude:\n        patterns:\n          - 'App\\Legacy\\**'\n",
            ['architecture', 'layers', 0, 'exclude', 'patterns'],
            [['App\\Legacy\\**']],
        ];

        yield 'layers[].exclude.suffix (string)' => [
            'architecture.layers[].exclude.suffix',
            $base . "    - name: a\n      patterns: ['App']\n      exclude:\n        suffix: 'Test'\n",
            ['architecture', 'layers', 0, 'exclude', 'suffix'],
            ['Test'],
        ];

        yield 'layers[].exclude.attributes (list)' => [
            'architecture.layers[].exclude.attributes',
            $base . "    - name: a\n      patterns: ['App']\n      exclude:\n        attributes:\n          - 'App\\Attr'\n",
            ['architecture', 'layers', 0, 'exclude', 'attributes'],
            [['App\\Attr']],
        ];

        yield 'layers[].exclude.implements (list)' => [
            'architecture.layers[].exclude.implements',
            $base . "    - name: a\n      patterns: ['App']\n      exclude:\n        implements:\n          - 'App\\Iface'\n",
            ['architecture', 'layers', 0, 'exclude', 'implements'],
            [['App\\Iface']],
        ];

        yield 'layers[].exclude.extends (list)' => [
            'architecture.layers[].exclude.extends',
            $base . "    - name: a\n      patterns: ['App']\n      exclude:\n        extends:\n          - 'App\\Base'\n",
            ['architecture', 'layers', 0, 'exclude', 'extends'],
            [['App\\Base']],
        ];

        yield 'layers[].exclude.match (string)' => [
            'architecture.layers[].exclude.match',
            $base . "    - name: a\n      patterns: ['App']\n      exclude:\n        patterns: ['X']\n        match: any\n",
            ['architecture', 'layers', 0, 'exclude', 'match'],
            'any',
        ];

        yield 'layers[].name with capture variable preserved' => [
            'architecture.layers[].name (template)',
            $base . "    - name: 'app-{m}'\n      patterns: ['App\\{m}\\App']\n",
            ['architecture', 'layers', 0, 'name'],
            'app-{m}',
        ];
    }

    /**
     * @return iterable<string, array{string, string, non-empty-list<string|int>, mixed, 4?: array{string, list<string>, string}, 5?: string}>
     */
    public static function provideArchitectureAllowCases(): iterable
    {
        $layers = "architecture:\n  layers:\n    - name: a\n      patterns: ['A']\n    - name: b\n      patterns: ['B']\n";

        yield 'snake_case source layer name preserved as map key' => [
            'architecture.allow.<snake_case_source>',
            "architecture:\n  layers:\n    - name: app_core\n      patterns: ['Core']\n    - name: app_service\n      patterns: ['Service']\n  allow:\n    app_core:\n      - app_service\n",
            ['architecture', 'allow', 'app_core'],
            [['app_service']],
        ];

        yield 'kebab-case source layer name preserved as map key' => [
            'architecture.allow.<kebab-source>',
            "architecture:\n  layers:\n    - name: 'app-core'\n      patterns: ['Core']\n    - name: 'app-service'\n      patterns: ['Service']\n  allow:\n    'app-core':\n      - 'app-service'\n",
            ['architecture', 'allow', 'app-core'],
            [['app-service']],
        ];

        yield 'capture-variable source layer template preserved as map key' => [
            'architecture.allow.<template-source>',
            "architecture:\n  layers:\n    - name: 'app-orders'\n      patterns: ['App\\Orders\\App']\n    - name: 'domain-orders'\n      patterns: ['App\\Orders\\Domain']\n  allow:\n    'app-{m}':\n      - 'domain-{m}'\n",
            ['architecture', 'allow', 'app-{m}'],
            [['domain-{m}']],
        ];

        yield 'long-form target key preserved' => [
            'architecture.allow.<src>[].target',
            $layers . "  allow:\n    a:\n      - target: b\n",
            ['architecture', 'allow', 'a', 0, 0, 'target'],
            'b',
        ];

        yield 'long-form relations key preserved (list of tokens)' => [
            'architecture.allow.<src>[].relations',
            $layers . "  allow:\n    a:\n      - target: b\n        relations:\n          - static_call\n",
            ['architecture', 'allow', 'a', 0, 0, 'relations'],
            ['static_call'],
        ];

        yield 'long-form allow_cross_instance preserved (snake_case scalar)' => [
            'architecture.allow.<src>[].allow_cross_instance',
            $layers . "  allow:\n    a:\n      - target: b\n        allow_cross_instance: true\n",
            ['architecture', 'allow', 'a', 0, 0, 'allow_cross_instance'],
            true,
        ];

        yield 'unknown long-form snake_case key reaches validator verbatim' => [
            'architecture.allow.<src>[].future_snake_key',
            $layers . "  allow:\n    a:\n      - target: b\n        future_snake_key: 'whatever'\n",
            ['architecture', 'allow', 'a', 0, 0, 'target'],
            'whatever',
            ['architecture.allow.a[0]: unknown long-form key \'future_snake_key\'. Allowed keys: \'target\', \'relations\', \'allow_cross_instance\'.', ['architecture', 'allow', 'a', '0', 'future_snake_key'], 'future_snake_key'],
            "architecture:\n  layers:\n    - name: a\n      patterns: ['A']\n    - name: b\n      patterns: ['B']\n    - name: whatever\n      patterns: ['Whatever']\n  allow:\n    a:\n      - target: whatever\n",
        ];
    }

    #[Test]
    public function itGivesEveryDocumentedRootKeyAReachabilityCase(): void
    {
        $covered = self::collectCoveredRootKeys();

        $missing = [];
        foreach (ConfigSchema::ENTRIES as [$sourcePath, $resultKey, $rootType]) {
            $root = ConfigKeySpelling::rewriteLike(str_contains($sourcePath, '.') ? explode('.', $sourcePath, 2)[0] : $sourcePath, '_');
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

        // A provider added beside another case must remain visible to this coverage sweep.
        $allCases = [];

        foreach ((new ReflectionClass(self::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() || !str_starts_with($method->getName(), 'provide')) {
                continue;
            }

            $cases = $method->invoke(null);
            $allCases = [...$allCases, ...iterator_to_array($cases, false)];
        }

        self::assertNotSame([], $allCases, 'No reachability provider was found, so this control measures nothing.');

        foreach ($allCases as $case) {
            $first = $case[2][0] ?? null;
            if (\is_string($first)) {
                $covered[$first] = true;
            }
        }

        return $covered;
    }

    /**
     * @param array{string, list<string>, string}|null $refusal
     *
     * @return array<string, mixed>
     */
    private function acceptedYaml(string $yaml, ?array $refusal, ?string $companion): array
    {
        if ($refusal !== null) {
            $file = $this->writeYaml($yaml);
            $this->assertRefusedFile($file, $refusal);
            self::assertNotNull($companion);
            $yaml = $companion;
        }

        return $this->loadYaml($yaml);
    }

    private function writeYaml(string $yaml): string
    {
        $file = $this->tempDir . '/config_' . bin2hex(random_bytes(6)) . '.yaml';
        file_put_contents($file, $yaml);

        return $file;
    }

    /**
     * @param array{string, list<string>, string} $expected
     */
    private function assertRefusedFile(string $file, array $expected): void
    {
        try {
            $this->loadFile($file);
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
     * @return array<string, mixed>
     */
    private function loadYaml(string $yaml): array
    {
        return $this->loadFile($this->writeYaml($yaml));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFile(string $file): array
    {
        $document = $this->pipeline->resolve(new ConfigurationResolutionRequest(
            AbsolutePath::fromString($this->tempDir),
            configFilePath: $file,
        ));

        return array_map(
            static fn(ResolvedValueInterface $value): mixed => $value->plain(),
            $document->resolved()->roots(),
        );
    }

    /**
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
