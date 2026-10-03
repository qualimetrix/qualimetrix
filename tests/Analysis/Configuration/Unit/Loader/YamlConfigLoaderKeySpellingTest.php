<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Loader;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document\WrittenFile;

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
            array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($this->path)->roots());
            self::fail('Two spellings of one key must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            if ($second === 'Dir') {
                self::assertSame(\sprintf('Key "cache.Dir" in configuration file "%s" is not written in an accepted spelling; write "dir" (its snake_case, camelCase and kebab-case spellings are accepted).', $this->path), $refusal->summary());
                self::assertSame($this->path, $refusal->sources()[0]->locator());
                self::assertSame(['cache', 'Dir'], $refusal->position()?->segments);
                self::assertSame('Dir', $refusal->position()->written);
                return;
            }
            self::assertStringContainsString(\sprintf('"%s"', $first), $refusal->summary());
            self::assertStringContainsString(\sprintf('"%s"', $second), $refusal->summary());
        }
    }

    /** The lawful neighbours: one spelling, and the same spelling in two sibling blocks. */
    #[Test]
    public function itKeepsSiblingSpellingsAndRefusesAKeyOutsideItsDeclaredSlot(): void
    {
        file_put_contents(
            $this->path,
            "suppressPaths: []\nrules:\n  complexity.ccn:\n    class: {max_warning: 1}\n    callable: {max_warning: 2}\n",
        );

        $loaded = (new YamlConfigLoader())->read($this->path, $this->path);
        self::assertSame(['suppressPaths' => [], 'rules' => ['complexity.ccn' => ['class' => ['max_warning' => 1], 'callable' => ['max_warning' => 2]]]], $loaded->authored->plain());
        try {
            WrittenFile::compose($this->path);
            self::fail('The callable slot must refuse a class-only key.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(\sprintf('Unknown key "rules.complexity.ccn.callable.max_warning" in configuration file "%s". Accepted keys: enabled, error, warning, threshold.', $this->path), $refusal->summary());
            self::assertSame($this->path, $refusal->sources()[0]->locator());
            self::assertSame(['rules', 'complexity.ccn', 'callable', 'max_warning'], $refusal->position()?->segments);
            self::assertSame('max_warning', $refusal->position()->written);
        }
        file_put_contents($this->path, "suppressPaths: []\nrules:\n  complexity.ccn:\n    class: {max_warning: 1}\n    callable: {warning: 2}\n");
        $document = WrittenFile::compose($this->path);
        self::assertSame([], $document->get('suppress_paths')?->plain());
        self::assertSame(1, $document->get('rules', 'complexity.ccn', 'class', 'max-warning')?->plain());
        self::assertSame(2, $document->get('rules', 'complexity.ccn', 'callable', 'warning')?->plain());
    }

    /** @return iterable<string, array{string}> */
    public static function provideAuthorStyles(): iterable
    {
        yield 'snake' => ['exclude_healh: []'];
        yield 'kebab' => ['exclude-healh: []'];
        yield 'camel' => ['excludeHealh: []'];
    }

    /**
     * The document has one voice: whatever style the author wrote the typo
     * in, the key offered is the canonical one, which every style accepts.
     */
    #[Test]
    #[DataProvider('provideAuthorStyles')]
    public function itSuggestsTheCanonicalRootKeyWhateverTheAuthorsStyle(string $yaml): void
    {
        file_put_contents($this->path, $yaml . "\n");

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('did you mean "exclude_health"?');

        WrittenFile::compose($this->path);
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
    public function itListsCanonicalSectionKeysBesideTheAuthorsSpelling(string $written, string $expected): void
    {
        file_put_contents($this->path, \sprintf("coupling:\n  %s: []\n", $written));

        try {
            array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), WrittenFile::compose($this->path)->roots());
            self::fail('An unknown section key must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(\sprintf('Unknown key "coupling.%s" in configuration file "%s" (did you mean "framework_namespaces"?). Accepted keys: framework_namespaces.', $written, $this->path), $refusal->summary());
            self::assertSame($this->path, $refusal->sources()[0]->locator());
            self::assertSame(['coupling', $written], $refusal->position()?->segments);
            self::assertSame($written, $refusal->position()->written);
        }
    }
}
