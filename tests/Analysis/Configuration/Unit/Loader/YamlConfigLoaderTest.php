<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Loader;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document\WrittenFile;

#[CoversClass(YamlConfigLoader::class)]
final class YamlConfigLoaderTest extends TestCase
{
    private YamlConfigLoader $loader;
    private string $tempDir;

    #[Test]
    #[TestWith(['computed_metrics', 'computed_metrics'])]
    #[TestWith(['computedMetrics', 'computed_metrics'])]
    #[TestWith(['computed-metrics', 'computed_metrics'])]
    #[TestWith(['exclude_health', 'exclude_health'])]
    #[TestWith(['excludeHealth', 'exclude_health'])]
    #[TestWith(['exclude-health', 'exclude_health'])]
    public function itUsesOneCanonicalNameForEverySpellingOfAnOwnerRoot(string $written, string $canonical): void
    {
        $path = $this->tempDir . '/config.yaml';
        $value = $canonical === ConfigSchema::COMPUTED_METRICS
            ? '{computed.sample: {formula: "1"}}'
            : '[complexity]';
        file_put_contents($path, \sprintf("%s: %s\n", $written, $value));

        self::assertSame([$canonical], array_keys(array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($path)->roots())));
    }

    protected function setUp(): void
    {
        $this->loader = new YamlConfigLoader();
        $this->tempDir = sys_get_temp_dir() . '/qmx_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function itSupportsYamlExtension(): void
    {
        self::assertTrue($this->loader->supports('/path/to/config.yaml'));
        self::assertTrue($this->loader->supports('/path/to/config.yml'));
        self::assertTrue($this->loader->supports('/path/to/config.YAML'));
        self::assertTrue($this->loader->supports('/path/to/config.YML'));
    }

    #[Test]
    public function itDoesNotSupportOtherExtensions(): void
    {
        self::assertFalse($this->loader->supports('/path/to/config.php'));
        self::assertFalse($this->loader->supports('/path/to/config.json'));
        self::assertFalse($this->loader->supports('/path/to/config.xml'));
    }

    #[Test]
    public function itLoadsValidYaml(): void
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

        $config = $this->authoredValues($path);

        self::assertArrayHasKey('rules', $config);
        self::assertArrayHasKey('cyclomatic-complexity', $config['rules']);
        self::assertTrue($config['rules']['cyclomatic-complexity']['enabled']);
        self::assertSame(10, $config['rules']['cyclomatic-complexity']['warning_threshold']);
        self::assertSame(20, $config['rules']['cyclomatic-complexity']['error_threshold']);
        self::assertTrue($config['cache']['enabled']);
        self::assertSame('.qmx-cache', $config['cache']['dir']);
        self::assertSame('text', $config['format']);
        $this->assertDocumentRefusal($path, 'Rule option owner "cyclomatic-complexity" does not match any registered producer rule.', ['rules', 'cyclomatic-complexity'], 'cyclomatic-complexity');
        $lawful = $this->lawfulDocument('rules:
  complexity.ccn:
    callable: {warning: 10, error: 20}
cache: {enabled: true, dir: .qmx-cache}
format: text
');
        self::assertSame(['warning' => 10, 'error' => 20], $lawful->get('rules', 'complexity.ccn', 'callable')?->plain());
        self::assertSame('.qmx-cache', $lawful->get('cache', 'dir')?->plain());
        self::assertSame('text', $lawful->get('format')?->plain());
    }

    #[Test]
    public function itPreservesSnakeCaseKeysUntilTheDeclaredOwnerJudgesThem(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  namespace_size:
    warning_threshold: 10
    count_interfaces: true
    count_traits: false
YAML);

        $config = $this->authoredValues($path);

        self::assertArrayHasKey('rules', $config);
        self::assertArrayHasKey('namespace_size', $config['rules']);
        self::assertSame(10, $config['rules']['namespace_size']['warning_threshold']);
        self::assertTrue($config['rules']['namespace_size']['count_interfaces']);
        self::assertFalse($config['rules']['namespace_size']['count_traits']);
        $this->assertDocumentRefusal($path, 'Rule option owner "namespace_size" does not match any registered producer rule.', ['rules', 'namespace_size'], 'namespace_size');
        $lawful = $this->lawfulDocument('rules:
  size.class-count: {warning: 10, error: 20}
');
        self::assertSame(10, $lawful->get('rules', 'size.class-count', 'warning')?->plain());
    }

    #[Test]
    public function itLoadsEmptyFile(): void
    {
        $path = $this->tempDir . '/empty.yaml';
        file_put_contents($path, '');

        $config = array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($path)->roots());

        self::assertSame([], $config);
    }

    #[Test]
    public function itThrowsWhenFileNotFound(): void
    {
        $path = $this->tempDir . '/nonexistent.yaml';

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Configuration file not found');

        array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($path)->roots());
    }

    #[Test]
    public function itThrowsWhenFileIsNotReadable(): void
    {
        if (\function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('root ignores file permission bits, so this refusal is unreachable here.');
        }

        $path = $this->tempDir . '/unreadable.yaml';
        file_put_contents($path, "rules:\n  size.loc: {}\n");
        chmod($path, 0o000);

        try {
            if (is_readable($path)) {
                self::markTestSkipped('the filesystem does not honour the permission bits this test relies on.');
            }

            self::expectException(ConfigurationRefusal::class);
            self::expectExceptionMessage('Configuration file is not readable');

            array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($path)->roots());
        } finally {
            chmod($path, 0o644);
        }
    }

    #[Test]
    public function itThrowsForInvalidYaml(): void
    {
        $path = $this->tempDir . '/invalid.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  - this: is
    invalid: yaml:
      syntax: [
YAML);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Failed to parse configuration file');

        array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($path)->roots());
    }

    #[Test]
    public function itReportsTheChosenSourceNameForMalformedPhysicalYaml(): void
    {
        $path = $this->tempDir . '/qmx.yaml';
        file_put_contents($path, "rules: [\n");

        try {
            $this->loader->read($path, 'qmx.yaml');
            self::fail('Malformed YAML must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('Failed to parse configuration file qmx.yaml', $refusal->summary());
            self::assertStringNotContainsString($this->tempDir, $refusal->summary());
            self::assertSame('qmx.yaml', $refusal->sources()[0]->locator());
        }
    }

    #[Test]
    public function itThrowsForScalarValue(): void
    {
        $path = $this->tempDir . '/scalar.yaml';
        file_put_contents($path, 'just a string');

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('is not valid YAML format');

        array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($path)->roots());
    }

    #[Test]
    public function itPreservesCamelCaseKeys(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  cyclomaticComplexity:
    warningThreshold: 15
YAML);

        $config = $this->authoredValues($path);

        self::assertArrayHasKey('cyclomaticComplexity', $config['rules']);
        self::assertSame(15, $config['rules']['cyclomaticComplexity']['warningThreshold']);
        $this->assertDocumentRefusal($path, 'Rule option owner "cyclomaticComplexity" does not match any registered producer rule.', ['rules', 'cyclomaticComplexity'], 'cyclomaticComplexity');
        $lawful = $this->lawfulDocument('rules:
  complexity.ccn:
    class: {maxWarning: 15}
');
        self::assertSame(15, $lawful->get('rules', 'complexity.ccn', 'class', 'max-warning')?->plain());
    }

    /** Physical loading preserves the document before the declared schema judges it. */
    #[Test]
    public function itCarriesTheWrittenDocumentUntilTheEngineJudgesIt(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, "rules: 5\nFail_On: error\n");

        $loaded = $this->loader->read($path, $path);

        self::assertSame(['rules' => 5, 'Fail_On' => 'error'], $loaded->authored->plain());
        $this->assertDocumentRefusal($path, \sprintf('"rules" in configuration file "%s" must be a map, got int.', $path), ['rules'], 'rules');
        $lawful = $this->lawfulDocument("rules: {}\nfail_on: error\n");
        self::assertSame('error', $lawful->get('fail_on')?->plain());
        file_put_contents($path, "rules: {}\nFail_On: error\n");
        self::assertSame(['rules' => [], 'Fail_On' => 'error'], $this->loader->read($path, $path)->authored->plain());
        $this->assertDocumentRefusal($path, \sprintf('Key "Fail_On" in configuration file "%s" is not written in an accepted spelling; write "fail_on" (its snake_case, camelCase and kebab-case spellings are accepted).', $path), ['Fail_On'], 'Fail_On');
    }

    #[Test]
    public function itRejectsUnknownRootKeys(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity:
    enabled: true
unknown_key: some_value
another_bad_key: true
YAML);

        $this->assertDocumentRefusal($path, 'Rule option owner "complexity" does not match any registered producer rule.', ['rules', 'complexity'], 'complexity');
        $path = $this->tempDir . '/known-owner.yaml';
        file_put_contents($path, "rules:\n  complexity.ccn: {enabled: true}\nunknown_key: some_value\nanother_bad_key: true\n");
        $this->assertDocumentRefusal($path, \sprintf('Unknown key "unknown_key" in configuration file "%s". Accepted keys: exclude, suppress_paths, suppress_namespaces, include_generated, include_autoload_dev, cache, parallel, coupling, computed_metrics, exclude_health, rules, only_rules, disabled_rules, architecture, fail_on, memory_limit, paths, format.', $path), ['unknown_key'], 'unknown_key');
        file_put_contents($path, "rules:\n  complexity.ccn: {enabled: true}\nformat: json\nanother_bad_key: true\n");
        $this->assertDocumentRefusal($path, \sprintf('Unknown key "another_bad_key" in configuration file "%s". Accepted keys: exclude, suppress_paths, suppress_namespaces, include_generated, include_autoload_dev, cache, parallel, coupling, computed_metrics, exclude_health, rules, only_rules, disabled_rules, architecture, fail_on, memory_limit, paths, format.', $path), ['another_bad_key'], 'another_bad_key');
    }

    /**
     * A null value reads as "the author never wrote this key" only once the key
     * itself is known; writing `~` must not buy an unknown key a pass.
     */
    #[Test]
    public function itRejectsAnUnknownRootKeyWrittenWithANullValue(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
bogus_key: ~
YAML);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown key "bogus_key"');

        WrittenFile::compose($path);
    }

    #[Test]
    public function itRejectsAnUnknownSectionSubKeyWrittenWithANullValue(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
cache:
  bogus: ~
YAML);

        $this->assertDocumentRefusal($path, \sprintf('Unknown key "cache.bogus" in configuration file "%s". Accepted keys: dir, enabled.', $path), ['cache', 'bogus'], 'bogus');
    }

    /**
     * A list under a section root used to reach findOriginalSubKey() with an
     * integer sub-key and kill the process with exit 1 instead of refusing.
     */
    #[Test]
    #[TestWith(['cache', 'dir, enabled'])]
    #[TestWith(['parallel', 'workers'])]
    #[TestWith(['coupling', 'framework_namespaces'])]
    public function itRefusesASectionRootWrittenAsAList(string $section, string $allowed): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, $section . ": [something]\n");

        $this->assertDocumentRefusal($path, \sprintf('"%s" in configuration file "%s" must be a map, got a list.', $section, $path), [$section], $section);
    }

    #[Test]
    #[TestWith(['paths'])]
    #[TestWith(['exclude'])]
    #[TestWith(['disabled_rules'])]
    #[TestWith(['only_rules'])]
    #[TestWith(['suppress_paths'])]
    #[TestWith(['suppress_namespaces'])]
    #[TestWith(['exclude_health'])]
    public function itRefusesAListRootWrittenAsAMap(string $field): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, $field . ": {a: something}\n");

        $this->assertDocumentRefusal($path, \sprintf('"%s" in configuration file "%s" must be a list, got a map.', $field, $path), [$field], $field);
    }

    /**
     * An empty container has not chosen a shape, so neither direction of the
     * check may claim it.
     */
    #[Test]
    public function itAcceptsAnEmptyContainerOnEveryShapedRoot(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
cache: {}
parallel: {}
coupling: {}
paths: []
exclude: []
disabled_rules: []
only_rules: []
suppress_paths: []
suppress_namespaces: []
exclude_health: []
YAML);

        $config = $this->authoredValues($path);

        self::assertSame([], $config['cache']);
        self::assertSame([], $config['paths']);
        self::assertSame([], $config['exclude_health']);
        $this->assertDocumentRefusal($path, 'Invalid value for "paths": the list is empty, so this run would analyse nothing. Name at least one path, or omit the key to analyse the working directory.', ['paths'], 'paths');
        $lawful = $this->lawfulDocument('cache: {}
parallel: {}
coupling: {}
paths: [src]
exclude: []
disabled_rules: []
only_rules: []
suppress_paths: []
suppress_namespaces: []
exclude_health: []
');
        self::assertSame(['src'], $lawful->get('paths')?->plain());
        self::assertSame([], $lawful->get('exclude_health')?->plain());
    }

    #[Test]
    public function itStillAcceptsTheWellShapedFormOfEveryShapedRoot(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
cache: {dir: /tmp/x, enabled: false}
parallel: {workers: 2}
coupling: {framework_namespaces: [Symfony]}
paths: [src]
exclude: [vendor]
exclude_health: [complexity]
YAML);

        $config = $this->authoredValues($path);

        self::assertSame('/tmp/x', $config['cache']['dir']);
        self::assertSame(2, $config['parallel']['workers']);
        self::assertSame(['Symfony'], $config['coupling']['framework_namespaces']);
        self::assertSame(['src'], $config['paths']);
        self::assertSame(['complexity'], $config['exclude_health']);
        $this->assertDocumentRefusal($path, \sprintf('"coupling.framework_namespaces[0]" in configuration file "%s" must be a map, got string.', $path), ['coupling', 'framework_namespaces', '0'], '0');
        $lawful = $this->lawfulDocument('cache: {dir: /tmp/x, enabled: false}
parallel: {workers: 2}
coupling: {framework_namespaces: [{subtree: Symfony}]}
paths: [src]
exclude: [{subtree: vendor}]
exclude_health: [complexity]
');
        self::assertSame('/tmp/x', $lawful->get('cache', 'dir')?->plain());
        self::assertSame(2, $lawful->get('parallel', 'workers')?->plain());
        self::assertSame([['subtree' => 'Symfony']], $lawful->get('coupling', 'framework_namespaces')?->plain());
        self::assertSame(['src'], $lawful->get('paths')?->plain());
        self::assertSame(['complexity'], $lawful->get('exclude_health')?->plain());
    }

    #[Test]
    public function itRejectsNonArrayRules(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'rules: not_an_array');

        $this->assertDocumentRefusal($path, \sprintf('"rules" in configuration file "%s" must be a map, got string.', $path), ['rules'], 'rules');
    }

    #[Test]
    public function itRejectsInvalidRuleConfig(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity: "invalid string value"
YAML);

        $this->assertDocumentRefusal($path, 'Rule option owner "complexity" does not match any registered producer rule.', ['rules', 'complexity'], 'complexity');
        $path = $this->tempDir . '/known-owner.yaml';
        file_put_contents($path, "rules:\n  complexity.ccn: \"invalid string value\"\n");
        $this->assertDocumentRefusal($path, \sprintf('"rules.complexity.ccn" in configuration file "%s" must be boolean, got string.', $path), ['rules', 'complexity.ccn'], 'complexity.ccn');
    }

    #[Test]
    public function itAcceptsBooleanRuleConfig(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity: true
  size: false
YAML);

        $config = $this->authoredValues($path);

        self::assertTrue($config['rules']['complexity']);
        self::assertFalse($config['rules']['size']);
        $this->assertDocumentRefusal($path, 'Rule option owner "complexity" does not match any registered producer rule.', ['rules', 'complexity'], 'complexity');
        $lawful = $this->lawfulDocument('rules:
  complexity.ccn: true
  size.class-count: false
');
        self::assertTrue($lawful->get('rules', 'complexity.ccn', 'enabled')?->plain());
        self::assertFalse($lawful->get('rules', 'size.class-count', 'enabled')?->plain());
    }

    #[Test]
    public function itAcceptsNullRuleConfig(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity: ~
YAML);

        $config = $this->authoredValues($path);

        self::assertNull($config['rules']['complexity']);
        $this->assertDocumentRefusal($path, 'Rule option owner "complexity" does not match any registered producer rule.', ['rules', 'complexity'], 'complexity');
        $lawful = $this->lawfulDocument('rules:
  complexity.ccn: ~
');
        self::assertNull($lawful->get('rules', 'complexity.ccn')?->plain());
        self::assertNotNull($lawful->get('rules', 'complexity.ccn'));
    }

    #[Test]
    public function itRejectsNonArrayCache(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'cache: not_an_array');

        $this->assertDocumentRefusal($path, \sprintf('"cache" in configuration file "%s" must be a map, got string.', $path), ['cache'], 'cache');
    }

    #[Test]
    public function itRejectsTheRemovedNamespaceRoot(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'namespace: not_an_array');

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown key "namespace"');

        WrittenFile::compose($path);
    }

    #[Test]
    public function itRejectsNonListDisabledRules(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'disabled_rules: not_a_list');

        $this->assertDocumentRefusal($path, \sprintf('"disabled_rules" in configuration file "%s" must be a list, got string.', $path), ['disabled_rules'], 'disabled_rules');
    }

    #[Test]
    public function itAcceptsAllValidRootKeys(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity:
    enabled: true
cache:
  enabled: true
format: json
disabled_rules:
  - size
only_rules:
  - complexity
paths:
  - src
exclude:
  - vendor
suppress_paths:
  - src/Entity/*
YAML);

        $config = $this->authoredValues($path);

        self::assertArrayHasKey('rules', $config);
        self::assertArrayHasKey('cache', $config);
        self::assertSame('json', $config['format']);
        self::assertSame(['src/Entity/*'], $config['suppress_paths']);
        $this->assertDocumentRefusal($path, 'Rule option owner "complexity" does not match any registered producer rule.', ['rules', 'complexity'], 'complexity');
        $lawful = $this->lawfulDocument('rules:
  complexity.ccn: {enabled: true}
cache: {enabled: true}
format: json
disabled_rules: [size]
only_rules: [complexity]
paths: [src]
exclude: [{subtree: vendor}]
suppress_paths: [{regex: \'src/Entity/.*\'}]
');
        self::assertSame('json', $lawful->get('format')?->plain());
        self::assertSame([['regex' => 'src/Entity/.*']], $lawful->get('suppress_paths')?->plain());
    }

    #[Test]
    public function itRejectsNonListExcludePaths(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'suppress_paths: not_a_list');

        $this->assertDocumentRefusal($path, \sprintf('"suppress_paths" in configuration file "%s" must be a list, got string.', $path), ['suppress_paths'], 'suppress_paths');
    }

    // The retired root-level `exclude_paths`/`exclude_namespaces` spelling
    // must refuse by name, not fall through as a generic "unknown
    // configuration key": Levenshtein suggestion isn't close enough between
    // `exclude_paths` and `suppress_paths` to name the rename on its own, and
    // the message must also point away from the unrelated top-level
    // `exclude` — the mechanism readers have conflated with suppression.

    #[Test]
    #[DataProvider('provideRetiredRootSpellings')]
    public function itRefusesARetiredRootOptionInTheSpellingItsAuthorUsed(
        string $authored,
        string $replacement,
    ): void {
        $this->assertRefusalEchoesTheAuthoredSpelling(
            \sprintf("%s:\n  - src/Entity/*\n", $authored),
            $authored,
            $replacement,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideRetiredRootSpellings(): iterable
    {
        yield 'snake' => ['exclude_paths', 'suppress_paths'];
        yield 'camel' => ['excludeNamespaces', 'suppressNamespaces'];
    }

    /**
     * The per-rule half of the same refusal, and the reason it lives in the
     * loader: three spellings of one option arrive here and leave as one
     * normalized key, so a refusal raised any later can only answer in a
     * spelling the author may never have typed.
     */
    #[Test]
    #[DataProvider('provideRetiredRuleOptionSpellings')]
    public function itRefusesARetiredRuleOptionInTheSpellingItsAuthorUsed(
        string $authored,
        string $replacement,
    ): void {
        $this->assertRefusalEchoesTheAuthoredSpelling(
            \sprintf(
                "rules:\n  code-smell.long-parameter-list:\n    %s:\n      - src/Entity/Foo.php\n",
                $authored,
            ),
            $authored,
            $replacement,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideRetiredRuleOptionSpellings(): iterable
    {
        yield 'snake' => ['exclude_paths', 'suppress_paths'];
        yield 'camel' => ['excludePaths', 'suppressPaths'];
        yield 'kebab' => ['exclude-namespaces', 'suppress-namespaces'];
    }

    /**
     * Asserted by catching rather than by `expectExceptionMessage()`: that
     * method assigns one expectation, so a second call silently replaces the
     * first and half the claim stops being checked.
     */
    private function assertRefusalEchoesTheAuthoredSpelling(
        string $document,
        string $authored,
        string $replacement,
    ): void {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, $document);

        if (str_starts_with($document, 'rules:')) {
            $canonical = str_contains($authored, 'amespace') ? 'exclude-namespaces' : 'exclude-paths';
            $new = str_contains($authored, 'amespace') ? 'suppress-namespaces' : 'suppress-paths';
            $this->assertDocumentRefusal(
                $path,
                \sprintf('Key "rules.code-smell.long-parameter-list.%s" in configuration file "%s" is retired. The "%s" option was retired. To suppress findings the analysis already produces, use "%s". To exclude files from analysis entirely (the finding is never produced), use the "exclude" option instead — it is a different mechanism, not a renamed one.', $authored, $path, $canonical, $new),
                ['rules', 'code-smell.long-parameter-list', $authored],
                $authored,
            );
            $lawful = $this->lawfulDocument("rules:\n  code-smell.long-parameter-list:\n    suppress-paths: [{exact: src/Entity/Foo.php}]\n");
            self::assertSame([['exact' => 'src/Entity/Foo.php']], $lawful->get('rules', 'code-smell.long-parameter-list', 'suppress-paths')?->plain());
            return;
        }
        try {
            WrittenFile::compose($path);
            self::fail(\sprintf('"%s" was accepted instead of refused.', $authored));
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString(\sprintf('The "%s" option was retired', $authored), $refusal->summary());
            self::assertStringContainsString(\sprintf('use "%s"', $replacement), $refusal->summary());
            self::assertStringContainsString('"exclude" option instead', $refusal->summary());
        }
    }

    #[Test]
    public function itPreservesDottedKebabCaseRuleNames(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  size.method-count:
    warning_threshold: 15
    error_threshold: 30
YAML);

        $config = $this->authoredValues($path);

        self::assertArrayHasKey('size.method-count', $config['rules']);
        self::assertSame(15, $config['rules']['size.method-count']['warning_threshold']);
        self::assertSame(30, $config['rules']['size.method-count']['error_threshold']);
        $this->assertDocumentRefusal($path, \sprintf('Unknown key "rules.size.method-count.warning_threshold" in configuration file "%s". Accepted keys: error, warning, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.', $path), ['rules', 'size.method-count', 'warning_threshold'], 'warning_threshold');
        $lawful = $this->lawfulDocument('rules:
  size.method-count: {warning: 15, error: 30}
');
        self::assertSame(['warning' => 15, 'error' => 30], $lawful->get('rules', 'size.method-count')?->plain());
    }

    #[Test]
    public function itPreservesCodeSmellRuleNames(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  code-smell.boolean-argument:
    enabled: false
YAML);

        $config = array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($path)->roots());

        self::assertArrayHasKey('code-smell.boolean-argument', $config['rules']);
        self::assertFalse($config['rules']['code-smell.boolean-argument']['enabled']);
    }

    #[Test]
    public function itPreservesRootSpellingsUntilTheDeclaredSchemaJudgesThem(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
disabled_rules:
  - size.method-count
suppress_paths:
  - vendor
YAML);

        $config = $this->authoredValues($path);

        self::assertArrayHasKey('disabled_rules', $config);
        self::assertArrayHasKey('suppress_paths', $config);
        self::assertSame(['size.method-count'], $config['disabled_rules']);
        self::assertSame(['vendor'], $config['suppress_paths']);
        $this->assertDocumentRefusal($path, \sprintf('"suppress_paths[0]" in configuration file "%s" must be a map, got string. A selector names its kind: {exact: value}, {subtree: value}, or {regex: value}.', $path), ['suppress_paths', '0'], '0');
        $lawful = $this->lawfulDocument('disabled_rules: [size.method-count]
suppress_paths: [{subtree: vendor}]
');
        self::assertSame(['size.method-count'], $lawful->get('disabled_rules')?->plain());
        self::assertSame([['subtree' => 'vendor']], $lawful->get('suppress_paths')?->plain());
    }

    #[Test]
    public function itAcceptsParallelSection(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
parallel:
  workers: 4
YAML);

        $config = array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($path)->roots());

        self::assertSame(4, $config['parallel']['workers']);
    }

    #[Test]
    public function itRejectsWrongTypeCacheEnabled(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, "cache:\n  enabled: \"false\"\n");

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"cache.enabled" in configuration file "' . $path . '" must be boolean, got string.');

        WrittenFile::compose($path);
    }

    #[Test]
    public function itRejectsWrongTypeParallelWorkers(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, "parallel:\n  workers: \"four\"\n");

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"parallel.workers" in configuration file "' . $path . '" must be integer, got string.');

        WrittenFile::compose($path);
    }

    /** PHP reads an integer memory limit as bytes, and `-1` is the documented "no limit". */
    #[Test]
    #[TestWith([12345])]
    #[TestWith([-1])]
    public function itAcceptsAnIntegerMemoryLimit(int $limit): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, \sprintf("memory_limit: %d\n", $limit));

        self::assertSame($limit, WrittenFile::compose($path)->get('memory_limit')?->plain());
    }

    #[Test]
    public function itRejectsAMemoryLimitThatIsNeitherASizeNorANumber(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, "memory_limit: true\n");

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"memory_limit" in configuration file "' . $path . '" must be string or integer, got bool.');

        WrittenFile::compose($path);
    }

    #[Test]
    public function itRejectsWrongTypeIncludeGenerated(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, "include_generated: \"yes\"\n");

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"include_generated" in configuration file "' . $path . '" must be boolean, got string.');

        WrittenFile::compose($path);
    }

    #[Test]
    public function itPreservesAuthoredNullsWithoutMaterializingUnwrittenKeys(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
cache:
  enabled: ~
parallel:
  workers: ~
include_generated: ~
memory_limit: ~
YAML);

        $authored = $this->authoredValues($path);
        self::assertSame(['cache' => ['enabled' => null], 'parallel' => ['workers' => null], 'include_generated' => null, 'memory_limit' => null], $authored);
        self::assertSame([], WrittenFile::compose($path)->roots());
    }

    #[Test]
    public function itAcceptsCouplingSection(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
coupling:
  framework_namespaces:
    - Symfony
    - Doctrine
YAML);

        $config = $this->authoredValues($path);

        self::assertSame(['Symfony', 'Doctrine'], $config['coupling']['framework_namespaces']);
        $this->assertDocumentRefusal($path, \sprintf('"coupling.framework_namespaces[0]" in configuration file "%s" must be a map, got string.', $path), ['coupling', 'framework_namespaces', '0'], '0');
        $lawful = $this->lawfulDocument('coupling:
  framework_namespaces: [{subtree: Symfony}, {subtree: Doctrine}]
');
        self::assertSame([['subtree' => 'Symfony'], ['subtree' => 'Doctrine']], $lawful->get('coupling', 'framework_namespaces')?->plain());
    }

    #[Test]
    public function itLoadsProjectQmxYamlWithoutErrors(): void
    {
        $projectRoot = \dirname(__DIR__, 5);
        $configPath = $projectRoot . '/qmx.yaml';

        if (!file_exists($configPath)) {
            self::markTestSkipped('No qmx.yaml in project root');
        }

        // Smoke test: the project's own config file must load without errors
        $config = array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($configPath)->roots());

        self::assertNotEmpty($config, 'Project qmx.yaml should produce non-empty config');
    }

    #[Test]
    public function itLoadsProjectQmxYamlExampleWithoutErrors(): void
    {
        $projectRoot = \dirname(__DIR__, 5);
        $examplePath = $projectRoot . '/qmx.yaml.example';

        if (!file_exists($examplePath)) {
            self::markTestSkipped('No qmx.yaml.example in project root');
        }

        // The example file is fully commented out — should parse as empty
        $config = array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($examplePath)->roots());

        self::assertSame([], $config);
    }

    #[Test]
    public function itPreservesMultipleRuleNamesWithMixedFormats(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  complexity.ccn:
    warning_threshold: 10
  size.method-count:
    warning_threshold: 15
  code-smell.boolean-argument:
    enabled: false
  simple_rule:
    enabled: true
YAML);

        $config = $this->authoredValues($path);

        self::assertArrayHasKey('complexity.ccn', $config['rules']);
        self::assertArrayHasKey('size.method-count', $config['rules']);
        self::assertArrayHasKey('code-smell.boolean-argument', $config['rules']);
        self::assertArrayHasKey('simple_rule', $config['rules']);

        self::assertSame(10, $config['rules']['complexity.ccn']['warning_threshold']);
        self::assertSame(15, $config['rules']['size.method-count']['warning_threshold']);
        $this->assertDocumentRefusal($path, \sprintf('Unknown key "rules.complexity.ccn.warning_threshold" in configuration file "%s". Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.', $path), ['rules', 'complexity.ccn', 'warning_threshold'], 'warning_threshold');
        $lawful = $this->lawfulDocument('rules:
  complexity.ccn:
    callable: {warning: 10}
  size.method-count: {warning: 15}
  code-smell.boolean-argument: {enabled: false}
');
        self::assertSame(10, $lawful->get('rules', 'complexity.ccn', 'callable', 'warning')?->plain());
        self::assertSame(15, $lawful->get('rules', 'size.method-count', 'warning')?->plain());
        self::assertFalse($lawful->get('rules', 'code-smell.boolean-argument', 'enabled')?->plain());
    }

    #[Test]
    public function itPreservesComputedMetricNameKeys(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
computed_metrics:
  computed.my-score:
    formula: "loc * 2"
    warning_threshold: 80
  health.complexity:
    error_threshold: 50
YAML);

        $config = $this->authoredValues($path);

        self::assertArrayHasKey('computed.my-score', $config['computed_metrics']);
        self::assertArrayHasKey('health.complexity', $config['computed_metrics']);

        self::assertSame('loc * 2', $config['computed_metrics']['computed.my-score']['formula']);
        self::assertSame(80, $config['computed_metrics']['computed.my-score']['warning_threshold']);
        self::assertSame(50, $config['computed_metrics']['health.complexity']['error_threshold']);
        $this->assertDocumentRefusal($path, 'Invalid formula syntax for computed metric "computed.my-score": Variable "loc" is not valid around position 1 for expression `loc * 2`. (formula: loc * 2)', ['computed_metrics', 'computed.my-score', 'formula'], 'formula');
        $lawful = $this->lawfulDocument('computed_metrics:
  computed.my-score: {formula: \'1 * 2\', warning: 80}
  health.complexity: {error: 50}
');
        self::assertSame('1 * 2', $lawful->get('computed_metrics', 'computed.my-score', 'formula')?->plain());
        self::assertSame(80, $lawful->get('computed_metrics', 'computed.my-score', 'warning')?->plain());
        self::assertSame(50, $lawful->get('computed_metrics', 'health.complexity', 'error')?->plain());
    }

    #[Test]
    public function itRejectsUnknownCacheSubKey(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
cache:
  enabled: true
  typo_key: something
YAML);

        $this->assertDocumentRefusal($path, \sprintf('Unknown key "cache.typo_key" in configuration file "%s". Accepted keys: dir, enabled.', $path), ['cache', 'typo_key'], 'typo_key');
    }

    #[Test]
    public function itRejectsTheRemovedNamespaceRootRegardlessOfItsChildren(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
namespace:
  straetgy: psr4
YAML);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown key "namespace"');

        WrittenFile::compose($path);
    }

    /**
     * The sibling of the `namespace` case above for the other root the schema
     * dropped. Named rather than left to the generic unknown-key case, because
     * only a case that spells the retired key notices it being accepted again.
     */
    #[Test]
    public function itRejectsTheRemovedAggregationRootRegardlessOfItsChildren(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
aggregation:
  prefixes: [App]
  auto_depth: 2
YAML);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown key "aggregation"');

        WrittenFile::compose($path);
    }

    #[Test]
    public function itRejectsUnknownParallelSubKeyWithSuggestion(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
parallel:
  worker: 4
YAML);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('did you mean "workers"?');

        array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($path)->roots());
    }

    #[Test]
    public function itSuggestsCorrectRootKeyForTypo(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
cahce:
  enabled: true
YAML);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('did you mean "cache"?');

        WrittenFile::compose($path);
    }

    #[Test]
    public function itShowsNoSuggestionForDistantKey(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
zzzzzzz: true
YAML);

        try {
            WrittenFile::compose($path);
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $e) {
            self::assertStringContainsString('"zzzzzzz"', $e->getMessage());
            self::assertStringNotContainsString('did you mean', $e->getMessage());
        }
    }

    #[Test]
    public function itRejectsMultipleUnknownSubKeys(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
cache:
  foo: bar
  baz: qux
YAML);

        $this->assertDocumentRefusal($path, \sprintf('Unknown key "cache.foo" in configuration file "%s" (did you mean "dir"?). Accepted keys: dir, enabled.', $path), ['cache', 'foo'], 'foo');
    }

    #[Test]
    public function itShowsAllowedKeysInSubKeyError(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
cache:
  foo: bar
YAML);

        $this->assertDocumentRefusal($path, \sprintf('Unknown key "cache.foo" in configuration file "%s" (did you mean "dir"?). Accepted keys: dir, enabled.', $path), ['cache', 'foo'], 'foo');
    }

    #[Test]
    public function itRejectsScalarArchitecture(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'architecture: false');

        $this->assertDocumentRefusal($path, \sprintf('"architecture" in configuration file "%s" must be a map, got bool.', $path), ['architecture'], 'architecture');
    }

    #[Test]
    public function itRejectsScalarComputedMetrics(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, 'computed_metrics: not_a_map');

        // Belongs to the same associativeRootKeys() family — verify symmetry with rules
        $this->assertDocumentRefusal($path, \sprintf('"computed_metrics" in configuration file "%s" must be a map, got string.', $path), ['computed_metrics'], 'computed_metrics');
    }

    #[Test]
    public function itPreservesArchitectureLayerNamesVerbatim(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
architecture:
  layers:
    - name: app_core
      patterns: ['App\Core']
    - name: app-core-services
      patterns: ['App\CoreServices']
    - name: appCore
      patterns: ['App\AppCore']
YAML);

        $config = $this->authoredValues($path);

        self::assertIsArray($config['architecture']['layers']);
        self::assertCount(3, $config['architecture']['layers']);
        self::assertSame('app_core', $config['architecture']['layers'][0]['name']);
        self::assertSame('app-core-services', $config['architecture']['layers'][1]['name']);
        self::assertSame('appCore', $config['architecture']['layers'][2]['name']);
        $this->assertDocumentRefusal($path, 'architecture.layers[2] ("appCore"): Layer name "appCore" must match pattern /^[a-z][a-z0-9_-]*$/ (lowercase letter followed by lowercase letters, digits, underscores, or hyphens).', ['architecture', 'layers', '2', 'name'], 'name');
        $lawful = $this->lawfulDocument('architecture:
  layers:
    - {name: app_core, patterns: [\'App\\Core\']}
    - {name: app-core-services, patterns: [\'App\\CoreServices\']}
    - {name: app_core_v2, patterns: [\'App\\AppCore\']}
');
        self::assertSame('app_core_v2', $lawful->get('architecture', 'layers', '2', 'name')?->plain());
    }

    #[Test]
    public function itPreservesArchitectureAllowSourceLayerNames(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
architecture:
  layers:
    - name: app_core
      patterns: ['App\Core']
    - name: app_service
      patterns: ['App\Service']
  allow:
    app_core:
      - app_service
YAML);

        $config = array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($path)->roots());

        // Source layer name in `allow` is still a map key — preserved verbatim
        // by the architecture section's PRESERVE_SUBTREE policy
        // (ConfigSchema::sectionPolicies()).
        self::assertArrayHasKey('app_core', $config['architecture']['allow']);
        // Target list values are scalars — unaffected by key normalization
        self::assertSame([['app_service']], $config['architecture']['allow']['app_core']);
    }

    #[Test]
    public function itPreservesLongFormTargetSnakeCaseKeysUnderArchitectureAllow(): void
    {
        // Subtree-preservation guarantee: long-form target maps below
        // architecture.allow.* carry documented snake_case keys
        // (allow_cross_instance, future relations) that must survive
        // normalization untransformed so they reach AllowValidator as the
        // user wrote them.
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
architecture:
  layers:
    - name: 'app-orders'
      patterns: ['App\Orders\App']
    - name: 'domain-orders'
      patterns: ['App\Orders\Domain']
  allow:
    'app-{m}':
      - target: 'domain-{m}'
        allow_cross_instance: true
YAML);

        $config = array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($path)->roots());

        $entry = $config['architecture']['allow']['app-{m}'][0][0];

        self::assertArrayHasKey('target', $entry);
        self::assertArrayHasKey('allow_cross_instance', $entry, 'snake_case long-form key must survive normalization.');
        self::assertArrayNotHasKey('allowCrossInstance', $entry, 'long-form key must not be camelCased.');
        self::assertSame('domain-{m}', $entry['target']);
        self::assertTrue($entry['allow_cross_instance']);
    }

    #[Test]
    public function itPreservesWrittenRootKeysBesideArchitecture(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
architecture:
  layers:
    - name: app_core
      patterns: ['App\Core']
disabled_rules:
  - architecture.layer-violation
suppress_paths:
  - tests/
YAML);

        $config = $this->authoredValues($path);

        self::assertArrayHasKey('disabled_rules', $config);
        self::assertArrayHasKey('suppress_paths', $config);
        self::assertSame('app_core', $config['architecture']['layers'][0]['name']);
        $this->assertDocumentRefusal($path, \sprintf('"suppress_paths[0]" in configuration file "%s" must be a map, got string. A selector names its kind: {exact: value}, {subtree: value}, or {regex: value}.', $path), ['suppress_paths', '0'], '0');
        $lawful = $this->lawfulDocument('architecture:
  layers: [{name: app_core, patterns: [\'App\\Core\']}]
disabled_rules: [architecture.layer-violation]
suppress_paths: [{subtree: tests/}]
');
        self::assertSame(['architecture.layer-violation'], $lawful->get('disabled_rules')?->plain());
        self::assertSame([['subtree' => 'tests/']], $lawful->get('suppress_paths')?->plain());
    }

    #[Test]
    public function itAcceptsKebabCaseLayerNamesMatchingLayerDefinitionRegex(): void
    {
        // LayerDefinition::NAME_REGEX accepts [a-z][a-z0-9_-]*; confirm the loader
        // preserves names that fit the regex without mutating them.
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
architecture:
  layers:
    - name: 'app-core'
      patterns: ['App\Core']
    - name: 'app_core_v2'
      patterns: ['App\CoreV2']
YAML);

        $config = array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($path)->roots());

        self::assertSame('app-core', $config['architecture']['layers'][0]['name']);
        self::assertSame('app_core_v2', $config['architecture']['layers'][1]['name']);
    }

    /**
     * The keys of `suppress_namespace_channels` are channel names, and channel
     * names are kebab: camelCasing them produced a key naming no channel,
     * which the run then refused by the mangled spelling.
     */
    #[Test]
    public function itPreservesTheChannelKeysOfAnExclusionMap(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  size.class-count:
    suppress_namespace_channels:
      size.class-count: ['App\Legacy']
      code-smell.*: ['App\Legacy']
      size.class-count:namespace: ['App\Legacy']
      computed.my-score: ['App\Legacy']
YAML);

        $config = $this->authoredValues($path);

        self::assertSame(
            ['size.class-count', 'code-smell.*', 'size.class-count:namespace', 'computed.my-score'],
            array_keys($config['rules']['size.class-count']['suppress_namespace_channels']),
        );
        self::assertSame(
            ['App\Legacy'],
            $config['rules']['size.class-count']['suppress_namespace_channels']['code-smell.*'],
        );
        $this->assertDocumentRefusal($path, \sprintf('"rules.size.class-count.suppress_namespace_channels.size.class-count[0]" in configuration file "%s" must be a map, got string.', $path), ['rules', 'size.class-count', 'suppress_namespace_channels', 'size.class-count', '0'], '0');
        $lawful = $this->lawfulDocument('rules:
  size.class-count:
    suppress_namespace_channels:
      size.class-count: [{subtree: \'App\\Legacy\'}]
      code-smell.*: [{subtree: \'App\\Legacy\'}]
      \'size.class-count:namespace\': [{subtree: \'App\\Legacy\'}]
      computed.my-score: [{subtree: \'App\\Legacy\'}]
');
        self::assertSame(['size.class-count', 'code-smell.*', 'size.class-count:namespace', 'computed.my-score'], array_keys($lawful->get('rules', 'size.class-count', 'suppress-namespace-channels')?->plain()));
    }

    /** The option is written in either spelling, so both have to reach the same map. */
    #[Test]
    public function itPreservesChannelKeysUnderTheCamelCaseSpellingOfTheOption(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  size.class-count:
    suppressNamespaceChannels:
      size.class-count: ['App\Legacy']
YAML);

        $config = $this->authoredValues($path);

        self::assertSame(
            ['size.class-count'],
            array_keys($config['rules']['size.class-count']['suppressNamespaceChannels']),
        );
        $this->assertDocumentRefusal($path, \sprintf('"rules.size.class-count.suppressNamespaceChannels.size.class-count[0]" in configuration file "%s" must be a map, got string.', $path), ['rules', 'size.class-count', 'suppressNamespaceChannels', 'size.class-count', '0'], '0');
        $lawful = $this->lawfulDocument('rules:
  size.class-count:
    suppressNamespaceChannels:
      size.class-count: [{subtree: \'App\\Legacy\'}]
');
        self::assertSame(['size.class-count' => [['subtree' => 'App\Legacy']]], $lawful->get('rules', 'size.class-count', 'suppress-namespace-channels')?->plain());
    }

    /**
     * Only the channel map is exempt: its siblings are typed option keys and
     * keep being normalized, and so is the name of the map itself.
     */
    #[Test]
    public function itPreservesWrittenOptionKeysBesideAChannelMap(): void
    {
        $path = $this->tempDir . '/config.yaml';
        file_put_contents($path, <<<'YAML'
rules:
  size.class-count:
    warning_threshold: 3
    suppress_namespace_channels:
      size.class-count: ['App\Legacy']
    callable:
      warning_threshold: 5
YAML);

        $config = $this->authoredValues($path);

        self::assertSame(
            ['warning_threshold', 'suppress_namespace_channels', 'callable'],
            array_keys($config['rules']['size.class-count']),
        );
        self::assertSame(['warning_threshold' => 5], $config['rules']['size.class-count']['callable']);
        $this->assertDocumentRefusal($path, \sprintf('Unknown key "rules.size.class-count.warning_threshold" in configuration file "%s". Accepted keys: error, warning, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.', $path), ['rules', 'size.class-count', 'warning_threshold'], 'warning_threshold');
        $lawful = $this->lawfulDocument('rules:
  size.class-count: {warning: 3}
  complexity.ccn:
    callable: {warning: 5}
');
        self::assertSame(3, $lawful->get('rules', 'size.class-count', 'warning')?->plain());
        self::assertSame(5, $lawful->get('rules', 'complexity.ccn', 'callable', 'warning')?->plain());
    }

    /** @return array<string, mixed> */
    private function authoredValues(string $path): array
    {
        $plain = $this->loader->read($path, $path)->authored->plain();
        self::assertIsArray($plain);
        $values = [];
        foreach ($plain as $key => $value) {
            self::assertIsString($key);
            $values[$key] = $value;
        }
        return $values;
    }

    private function lawfulDocument(string $yaml): \Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument
    {
        $path = $this->tempDir . '/lawful.yaml';
        file_put_contents($path, $yaml);
        return WrittenFile::compose($path);
    }

    /** @param list<string> $segments */
    private function assertDocumentRefusal(string $path, string $summary, array $segments, string $written): void
    {
        try {
            WrittenFile::compose($path);
            self::fail('The original written value must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame($summary, $refusal->summary());
            self::assertSame(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
            self::assertSame($path, $refusal->sources()[0]->locator());
            self::assertSame($segments, $refusal->position()?->segments);
            self::assertSame($written, $refusal->position()->written);
        }
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
