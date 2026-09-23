<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Loader;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;

/**
 * Three spellings name one key, so a document can write one key twice
 * without the YAML parser seeing a duplicate — and the later spelling used to
 * overwrite the earlier one in silence, at any depth. A refusal about a key
 * answers in the spelling its author used.
 */
#[CoversClass(YamlConfigLoader::class)]
final class YamlConfigLoaderKeySpellingTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/qmx-spelling-' . bin2hex(random_bytes(6)) . '.yaml';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function provideCollidingSpellings(): iterable
    {
        yield 'root, snake then camel' => ["suppress_paths: [{subtree: src}]\nsuppressPaths: []\n", 'suppress_paths', 'suppressPaths'];
        yield 'root, kebab then snake' => ["suppress-paths: []\nsuppress_paths: [{subtree: src}]\n", 'suppress-paths', 'suppress_paths'];
        yield 'section sub-key' => ["cache:\n  dir: a\n  Dir: b\n", 'dir', 'Dir'];
        yield 'rule option group' => [
            "rules:\n  complexity.ccn:\n    class:\n      max_warning: 1\n      maxWarning: 999\n",
            'max_warning',
            'maxWarning',
        ];
    }

    #[Test]
    #[DataProvider('provideCollidingSpellings')]
    public function itRefusesTwoSpellingsOfOneKeyInOneDocument(string $yaml, string $first, string $second): void
    {
        file_put_contents($this->path, $yaml);

        try {
            (new YamlConfigLoader())->load($this->path);
            self::fail('Two spellings of one key must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString(\sprintf('"%s"', $first), $refusal->summary());
            self::assertStringContainsString(\sprintf('"%s"', $second), $refusal->summary());
        }
    }

    /** The lawful neighbours: one spelling, and the same spelling in two sibling blocks. */
    #[Test]
    public function itAcceptsOneSpellingAndRepeatsAcrossSiblings(): void
    {
        file_put_contents(
            $this->path,
            "suppressPaths: []\nrules:\n  complexity.ccn:\n    class: {max_warning: 1}\n    callable: {max_warning: 2}\n",
        );

        $config = (new YamlConfigLoader())->load($this->path);

        self::assertSame([], $config['suppressPaths']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideAuthorStyles(): iterable
    {
        yield 'snake' => ['exclude_healh: []', 'exclude_health'];
        yield 'kebab' => ['exclude-healh: []', 'exclude-health'];
        yield 'camel' => ['excludeHealh: []', 'excludeHealth'];
    }

    #[Test]
    #[DataProvider('provideAuthorStyles')]
    public function itSuggestsARootKeyInTheAuthorsSpelling(string $yaml, string $suggestion): void
    {
        file_put_contents($this->path, $yaml . "\n");

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage(\sprintf('did you mean "%s"?', $suggestion));

        (new YamlConfigLoader())->load($this->path);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideSectionKeyStyles(): iterable
    {
        yield 'snake' => ['framework_namespacez', 'Allowed keys: framework_namespaces'];
        yield 'kebab' => ['framework-namespacez', 'Allowed keys: framework-namespaces'];
        yield 'camel' => ['frameworkNamespacez', 'Allowed keys: frameworkNamespaces'];
    }

    #[Test]
    #[DataProvider('provideSectionKeyStyles')]
    public function itListsSectionKeysInTheAuthorsSpelling(string $written, string $expected): void
    {
        file_put_contents($this->path, \sprintf("coupling:\n  %s: []\n", $written));

        try {
            (new YamlConfigLoader())->load($this->path);
            self::fail('An unknown section key must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString($expected, $refusal->summary());
        }
    }
}
