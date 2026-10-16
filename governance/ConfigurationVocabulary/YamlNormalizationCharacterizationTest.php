<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ConfigurationVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\DocumentRoots;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

/** Characterizes canonical document values and the refusal of superseded authored forms. */
#[CoversClass(YamlConfigLoader::class)]
final class YamlNormalizationCharacterizationTest extends TestCase
{
    private ConfigurationPipelineInterface $pipeline;

    private string $tempDir;

    protected function setUp(): void
    {
        $pipeline = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $pipeline->addStage(new ConfigFileStage(new YamlConfigLoader()));
        $this->pipeline = $pipeline;
        $this->tempDir = sys_get_temp_dir() . '/qmx_yaml_norm_char_' . bin2hex(random_bytes(6));
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
     * @param array<string, mixed> $expected
     * @param array{string, list<string>, string}|null $refusal
     */
    #[Test]
    #[DataProvider('provideRootKeyCases')]
    #[TestDox('root key $description is normalized exactly as today')]
    public function itNormalizesEachRootKeyExactlyAsTheCurrentSnapshot(string $description, string $yaml, array $expected, ?array $refusal = null, ?string $companion = null): void
    {
        self::assertSame($expected, $this->acceptedYaml($yaml, $refusal, $companion), $description);
    }

    #[Test]
    public function itCoversEveryAllowedRootKeyWithACharacterizationCase(): void
    {
        $covered = [];
        foreach (self::provideRootKeyCases() as [, , $expected]) {
            foreach (array_keys($expected) as $rootKey) {
                $covered[(string) $rootKey] = true;
            }
        }

        $missing = [];
        foreach (DocumentRoots::known() as $root) {
            if (!isset($covered[$root])) {
                $missing[] = $root;
            }
        }

        self::assertSame(
            [],
            $missing,
            'Every DocumentRoots::known() entry must have a characterization row. '
            . 'Missing roots: ' . implode(', ', $missing) . '. '
            . 'Add a case to ' . __CLASS__ . '::provideRootKeyCases().',
        );
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>, 3?: array{string, list<string>, string}, 4?: string}>
     */
    public static function provideRootKeyCases(): iterable
    {
        yield 'paths (list)' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "paths:\n  - src\n  - tests\n",
            ['paths' => ['src', 'tests']],
        ];

        yield 'exclude (list)' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "exclude:\n  - vendor\n",
            ['exclude' => [['subtree' => 'vendor']]],
            ['"exclude[0]" in configuration file "{actual_config_path}" must be a map, got string. A selector names its kind: {exact: value}, {subtree: value}, or {regex: value}.', ['exclude', '0'], '0'],
            "exclude:\n  - subtree: vendor\n",
        ];

        yield 'disabled_rules → disabledRules' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "disabled_rules:\n  - complexity.ccn\n",
            ['disabled_rules' => ['complexity.ccn']],
        ];

        yield 'only_rules → onlyRules' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "only_rules:\n  - complexity.cognitive\n",
            ['only_rules' => ['complexity.cognitive']],
        ];

        yield 'suppress_paths → suppressPaths' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "suppress_paths:\n  - src/Generated/*\n",
            ['suppress_paths' => [['exact' => 'src/Generated/*']]],
            ['"suppress_paths[0]" in configuration file "{actual_config_path}" must be a map, got string. A selector names its kind: {exact: value}, {subtree: value}, or {regex: value}.', ['suppress_paths', '0'], '0'],
            "suppress_paths:\n  - exact: 'src/Generated/*'\n",
        ];

        yield 'suppress_namespaces → suppressNamespaces' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "suppress_namespaces:\n  - App\\Generated\n",
            ['suppress_namespaces' => [['subtree' => 'App\\Generated']]],
            ['"suppress_namespaces[0]" in configuration file "{actual_config_path}" must be a map, got string. A selector names its kind: {exact: value}, {subtree: value}, or {regex: value}.', ['suppress_namespaces', '0'], '0'],
            "suppress_namespaces:\n  - subtree: 'App\\Generated'\n",
        ];

        yield 'exclude_health → exclude_health' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "exclude_health:\n  - tests/**\n",
            ['exclude_health' => ['tests/**']],
        ];

        yield 'format (scalar)' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "format: json\n",
            ['format' => 'json'],
        ];

        yield 'fail_on → failOn (scalar)' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "fail_on: error\n",
            ['fail_on' => 'error'],
        ];

        yield 'include_generated → includeGenerated (scalar bool)' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "include_generated: true\n",
            ['include_generated' => true],
        ];

        yield 'include_autoload_dev → includeAutoloadDev (scalar bool)' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "include_autoload_dev: true\n",
            ['include_autoload_dev' => true],
        ];

        yield 'memory_limit → memoryLimit (scalar)' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "memory_limit: 512M\n",
            ['memory_limit' => '512M'],
        ];

        yield 'cache: sub-keys camelCased' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "cache:\n  dir: .qmx-cache\n  enabled: true\n",
            [
                'cache' => [
                    'dir' => '.qmx-cache',
                    'enabled' => true,
                ],
            ],
        ];

        yield 'parallel: workers' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "parallel:\n  workers: 4\n",
            ['parallel' => ['workers' => 4]],
        ];

        yield 'coupling: framework_namespaces → frameworkNamespaces' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "coupling:\n  framework_namespaces:\n    - Symfony\\\n",
            ['coupling' => ['framework_namespaces' => [['subtree' => 'Symfony']]]],
            ['"coupling.framework_namespaces[0]" in configuration file "{actual_config_path}" must be a map, got string.', ['coupling', 'framework_namespaces', '0'], '0'],
            "coupling:\n  framework_namespaces:\n    - subtree: Symfony\n",
        ];

        yield 'rules: identifier preserved, option keys normalized' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "rules:\n  complexity.ccn:\n    enabled: true\n    warning_threshold: 10\n  namespace_size:\n    enabled: false\n",
            ['rules' => ['complexity.ccn' => ['enabled' => true, 'callable' => ['warning' => 10]], 'size.class-count' => ['enabled' => false]]],
            ['Unknown key "rules.complexity.ccn.warning_threshold" in configuration file "{actual_config_path}". Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.', ['rules', 'complexity.ccn', 'warning_threshold'], 'warning_threshold'],
            "rules:\n  complexity.ccn:\n    enabled: true\n    callable:\n      warning: 10\n  size.class-count:\n    enabled: false\n",
        ];

        yield 'rules: nested option subtree normalizes recursively' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "rules:\n  complexity:\n    callable:\n      warning_threshold: 12\n",
            ['rules' => ['complexity.ccn' => ['callable' => ['warning' => 12]]]],
            ['Rule option owner "complexity" does not match any registered producer rule.', ['rules', 'complexity'], 'complexity'],
            "rules:\n  complexity.ccn:\n    callable:\n      warning: 12\n",
        ];

        yield 'computed_metrics → computed_metrics root; identifier preserved, options normalized' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "computed_metrics:\n  computed.my-score:\n    formula: 'loc * 2'\n    warning_threshold: 80\n",
            ['computed_metrics' => ['computed.my-score' => ['formula' => 'm["size.loc"] * 2', 'warning' => 80]]],
            ['Invalid formula syntax for computed metric "computed.my-score": Variable "loc" is not valid around position 1 for expression `loc * 2`. (formula: loc * 2)', ['computed_metrics', 'computed.my-score', 'formula'], 'formula'],
            "computed_metrics:\n  computed.my-score:\n    formula: 'm[\"size.loc\"] * 2'\n    warning: 80\n",
        ];

        yield 'architecture.layers (list, items preserved)' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "architecture:\n  layers:\n    - name: app\n      patterns: ['App']\n",
            ['architecture' => ['layers' => [['name' => 'app', 'patterns' => [['App']]]]]],
        ];

        yield 'architecture.allow subtree preserves snake_case verbatim' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "architecture:\n  allow:\n    app_core:\n      - target: app_service\n        allow_cross_instance: true\n",
            ['architecture' => ['layers' => [['name' => 'app_core', 'patterns' => [['Core']]], ['name' => 'app_service', 'patterns' => [['Service']]]], 'allow' => ['app_core' => [[['target' => 'app_service', 'allow_cross_instance' => true]]]]]],
            ['Unknown name "app_core" under "architecture.allow", written in configuration file "{actual_config_path}": the names come from "layers", which declares none.', ['architecture', 'allow', 'app_core'], 'app_core'],
            "architecture:\n  layers:\n    - name: app_core\n      patterns: ['Core']\n    - name: app_service\n      patterns: ['Service']\n  allow:\n    app_core:\n      - target: app_service\n        allow_cross_instance: true\n",
        ];

        yield 'architecture.coverage-gap (scalar, single-word key)' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "architecture:\n  coverage-gap: ignore\n",
            ['architecture' => ['coverage-gap' => 'ignore']],
        ];

        yield 'architecture.max_expanded_layers (scalar leaf preserved under PRESERVE_SUBTREE)' => [
            'Canonical document value; original refused forms retain a separate literal oracle',
            "architecture:\n  max_expanded_layers: 256\n",
            ['architecture' => ['max_expanded_layers' => 256]],
        ];
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
}
