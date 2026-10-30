<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Loader;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Analysis\Configuration\Loader\ImportChain;
use Qualimetrix\Analysis\Configuration\Loader\LoadedDocument;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;

#[CoversClass(ImportChain::class)]
final class ImportChainTest extends TestCase
{
    private string $directory;

    /** @var ArrayObject<int, string> physical paths in the order the loader read them */
    private ArrayObject $reads;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/qmx-import-chain-' . bin2hex(random_bytes(6));
        mkdir($directory . '/cfg', 0o777, true);
        $this->directory = (string) realpath($directory);
        $this->reads = new ArrayObject();
    }

    protected function tearDown(): void
    {
        self::remove($this->directory);
    }

    #[Test]
    public function itPlacesTheEntriesInWrittenOrderBelowTheRootAndReadsEachOnce(): void
    {
        $this->write('qmx.yaml', "imports: [cfg/a.yaml, cfg/b.yaml, cfg/c.yaml]\nfail_on: error\n");
        $this->write('cfg/a.yaml', "format: json\n");
        $this->write('cfg/b.yaml', "format: text\n");
        $this->write('cfg/c.yaml', '');

        $layers = $this->expand();

        self::assertSame([
            'configuration file "cfg/a.yaml" (imported by configuration file "qmx.yaml")',
            'configuration file "cfg/b.yaml" (imported by configuration file "qmx.yaml")',
            'configuration file "cfg/c.yaml" (imported by configuration file "qmx.yaml")',
            'configuration file "qmx.yaml"',
        ], self::described($layers));
        self::assertSame([['format' => 'json'], ['format' => 'text'], [], ['fail_on' => 'error']], self::plain($layers));
        self::assertSame(
            array_map(fn(string $file): string => $this->directory . '/' . $file, ['qmx.yaml', 'cfg/a.yaml', 'cfg/b.yaml', 'cfg/c.yaml']),
            $this->reads->getArrayCopy(),
        );
    }

    #[Test]
    public function itFlattensAGlobsMatchesInByteOrderAtTheEntrysPlaceAndReadsEachFileOnce(): void
    {
        $this->write('qmx.yaml', "imports: [first.yaml, \"cfg/*.yaml\", strict, cfg/a.yaml]\n");
        $this->write('first.yaml', '');
        $this->write('cfg/b.yaml', '');
        $this->write('cfg/a.yaml', '');

        $layers = $this->expand();

        self::assertSame([
            'configuration file "first.yaml" (imported by configuration file "qmx.yaml")',
            'configuration file "cfg/b.yaml" (imported by configuration file "qmx.yaml")',
            'preset "strict" (imported by configuration file "qmx.yaml")',
            'configuration file "cfg/a.yaml" (imported by configuration file "qmx.yaml")',
            'configuration file "qmx.yaml"',
        ], self::described($layers));
        self::assertCount(5, $this->reads);
    }

    #[Test]
    public function itRefusesAnImportedGlobMatchThatWritesImports(): void
    {
        $this->write('qmx.yaml', "imports: [\"cfg/*.yaml\"]\n");
        $this->write('cfg/a.yaml', "imports: [b.yaml]\n");

        $refusal = $this->refusal();

        self::assertSame('configuration file "cfg/a.yaml" (imported by configuration file "qmx.yaml")', $refusal->sources()[0]->describe());
    }

    /** @return iterable<string, array{string}> */
    public static function provideRootsWithoutImports(): iterable
    {
        yield 'no key' => ["fail_on: error\n"];
        yield 'empty list' => ["imports: []\nfail_on: error\n"];
        yield 'null' => ["imports: ~\nfail_on: error\n"];
    }

    #[Test]
    #[DataProvider('provideRootsWithoutImports')]
    public function itHandsTheRootAloneWhenItImportsNothing(string $root): void
    {
        $this->write('qmx.yaml', $root);

        $layers = $this->expand();

        self::assertSame(['configuration file "qmx.yaml"'], self::described($layers));
        self::assertSame([['fail_on' => 'error']], self::plain($layers));
    }

    /** @return iterable<string, array{string}> */
    public static function provideNestedImports(): iterable
    {
        yield 'a list' => ["imports: [b.yaml]\n"];
        yield 'an empty list' => ["imports: []\n"];
        yield 'null' => ["imports: ~\n"];
    }

    #[Test]
    #[DataProvider('provideNestedImports')]
    public function itRefusesAnImportedFileThatWritesImportsAtThatKey(string $imported): void
    {
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\n");
        $this->write('cfg/a.yaml', $imported);

        $refusal = $this->refusal();

        self::assertStringContainsString('only the root configuration file may import files', $refusal->summary());
        self::assertStringContainsString('in the "imports" of configuration file "qmx.yaml"', $refusal->summary());
        self::assertSame('configuration file "cfg/a.yaml" (imported by configuration file "qmx.yaml")', $refusal->sources()[0]->describe());
        self::assertSame(['imports'], $refusal->position()?->segments);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function provideSameFileTwice(): iterable
    {
        yield 'dot segment' => [['cfg/a.yaml', './cfg/a.yaml'], 'imports[0] ("cfg/a.yaml") and imports[1] ("./cfg/a.yaml")'];
        yield 'parent segment' => [['cfg/a.yaml', 'cfg/../cfg/a.yaml'], 'imports[0] ("cfg/a.yaml") and imports[1] ("cfg/../cfg/a.yaml")'];
        yield 'symlink' => [['cfg/a.yaml', 'link.yaml'], 'imports[0] ("cfg/a.yaml") and imports[1] ("link.yaml")'];
        yield 'not adjacent' => [['cfg/a.yaml', 'cfg/b.yaml', 'cfg/a.yaml'], 'imports[0] ("cfg/a.yaml") and imports[2] ("cfg/a.yaml")'];
    }

    /** @param list<string> $entries */
    #[Test]
    #[DataProvider('provideSameFileTwice')]
    public function itRefusesTheSameFileTwiceNamingBothPositions(array $entries, string $named): void
    {
        $this->write('cfg/a.yaml', '');
        $this->write('cfg/b.yaml', '');
        symlink($this->directory . '/cfg/a.yaml', $this->directory . '/link.yaml');
        $this->write('qmx.yaml', 'imports: [' . implode(', ', $entries) . "]\n");

        $refusal = $this->refusal();

        self::assertSame(\sprintf('Entries %s of configuration file "qmx.yaml" name the same file; list each file once.', $named), $refusal->summary());
        self::assertSame(['imports', (string) (\count($entries) - 1)], $refusal->position()?->segments);
        self::assertSame('configuration file "qmx.yaml"', $refusal->sources()[0]->describe());
    }

    /** @return iterable<string, array{string}> */
    public static function provideTheRootItself(): iterable
    {
        yield 'its name' => ['qmx.yaml'];
        yield 'dot segment' => ['./qmx.yaml'];
        yield 'symlink' => ['root-link.yaml'];
    }

    #[Test]
    #[DataProvider('provideTheRootItself')]
    public function itRefusesAnEntryNamingTheRootItself(string $entry): void
    {
        $this->write('qmx.yaml', \sprintf("imports: [%s]\n", $entry));
        symlink($this->directory . '/qmx.yaml', $this->directory . '/root-link.yaml');

        $refusal = $this->refusal();

        self::assertStringContainsString('names that file itself; a file cannot import itself', $refusal->summary());
        self::assertSame(['imports', '0'], $refusal->position()?->segments);
        self::assertSame([$this->directory . '/qmx.yaml'], $this->reads->getArrayCopy());
    }

    #[Test]
    public function itResolvesAnEntryAgainstTheImportingFilesDirectoryAndNamesItFromTheRootsName(): void
    {
        mkdir($this->directory . '/sub/cfg', 0o777, true);
        $this->write('sub/qmx.yaml', "imports: [cfg/a.yaml, ./b.yaml]\n");
        $this->write('sub/cfg/a.yaml', '');
        $this->write('sub/b.yaml', '');

        $layers = $this->chain()->expand($this->directory . '/sub/qmx.yaml', self::root('sub/qmx.yaml'));

        self::assertSame(['sub/cfg/a.yaml', 'sub/b.yaml', 'sub/qmx.yaml'], array_map(static fn(AuthoredLayer $layer): ?string => $layer->origin->locator(), $layers));
        self::assertSame([$this->directory . '/sub/qmx.yaml', $this->directory . '/sub/cfg/a.yaml', $this->directory . '/sub/./b.yaml'], $this->reads->getArrayCopy());
    }

    /**
     * `..` after a symlinked directory leads where the kernel resolves it, not
     * where collapsing it would say, so the name keeps it: two different files
     * never share a name.
     */
    #[Test]
    public function itKeepsParentSegmentsInTheNameSoTwoFilesNeverShareOne(): void
    {
        mkdir($this->directory . '/elsewhere/deep', 0o777, true);
        $this->write('elsewhere/a.yaml', "format: json\n");
        symlink($this->directory . '/elsewhere/deep', $this->directory . '/link');
        $this->write('a.yaml', "format: summary\n");
        $this->write('absolute.yaml', "format: text\n");
        $this->write('qmx.yaml', \sprintf("imports: [link/../a.yaml, ./a.yaml, %s]\n", $this->directory . '/absolute.yaml'));

        $layers = $this->expand();

        self::assertSame(['link/../a.yaml', 'a.yaml', $this->directory . '/absolute.yaml', 'qmx.yaml'], array_map(static fn(AuthoredLayer $layer): ?string => $layer->origin->locator(), $layers));
        self::assertSame([['format' => 'json'], ['format' => 'summary'], ['format' => 'text'], []], self::plain($layers));
    }

    /**
     * A configuration linked into a project reads the files beside its
     * target, not a same-named file beside the link, and names them by where
     * they are: a name drawn from the link's directory would point elsewhere.
     */
    #[Test]
    public function itResolvesEntriesBesideTheRealFileOfASymlinkedRoot(): void
    {
        mkdir($this->directory . '/shared');
        mkdir($this->directory . '/project');
        $this->write('shared/qmx.yaml', "imports: [base.yaml]\n");
        $this->write('shared/base.yaml', "fail_on: error\n");
        $this->write('project/base.yaml', "fail_on: none\n");
        symlink($this->directory . '/shared/qmx.yaml', $this->directory . '/project/qmx.yaml');

        $layers = $this->chain()->expand($this->directory . '/project/qmx.yaml', self::root('qmx.yaml'));

        self::assertSame([['fail_on' => 'error'], []], self::plain($layers));
        self::assertSame([$this->directory . '/shared/base.yaml', 'qmx.yaml'], array_map(static fn(AuthoredLayer $layer): ?string => $layer->origin->locator(), $layers));
    }

    /** A name always leads to the file read, so two different files never share one, absolute or relative. */
    #[Test]
    public function itNamesEveryImportOfAnAbsoluteRootByAPathThatLeadsToIt(): void
    {
        mkdir($this->directory . '/elsewhere/deep', 0o777, true);
        $this->write('elsewhere/a.yaml', "format: json\n");
        symlink($this->directory . '/elsewhere/deep', $this->directory . '/link');
        $this->write('a.yaml', "format: text\n");
        $this->write('qmx.yaml', \sprintf("imports: [link/../a.yaml, %s/a.yaml]\n", $this->directory));

        $layers = $this->chain()->expand($this->directory . '/qmx.yaml', self::root($this->directory . '/qmx.yaml'));

        self::assertSame(
            [$this->directory . '/link/../a.yaml', $this->directory . '/a.yaml', $this->directory . '/qmx.yaml'],
            array_map(static fn(AuthoredLayer $layer): ?string => $layer->origin->locator(), $layers),
        );
        self::assertSame([['format' => 'json'], ['format' => 'text'], []], self::plain($layers));
    }

    /** @return iterable<string, array{string, non-empty-string}> */
    public static function provideImportedDocumentsTheLoaderRefuses(): iterable
    {
        yield 'unparseable' => ["rules: [\n", 'Failed to parse configuration file "cfg/a.yaml" (imported by configuration file "qmx.yaml"): '];
        yield 'not a mapping' => ['just a string', 'Configuration file "cfg/a.yaml" (imported by configuration file "qmx.yaml") is not valid YAML format.'];
        yield 'retired root key' => ["exclude_paths: [src]\n", 'The "exclude_paths" option was retired.'];
    }

    /** @param non-empty-string $expected */
    #[Test]
    #[DataProvider('provideImportedDocumentsTheLoaderRefuses')]
    public function itHandsTheLoaderTheImportedOriginSoItsRefusalsNameTheImporter(string $imported, string $expected): void
    {
        $this->write('cfg/a.yaml', $imported);
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\n");

        $refusal = $this->refusal();

        self::assertStringStartsWith($expected, $refusal->summary());
        self::assertSame('configuration file "cfg/a.yaml" (imported by configuration file "qmx.yaml")', $refusal->sources()[0]->describe());
    }

    #[Test]
    public function itRefusesAMissingImportBeforeReadingIt(): void
    {
        $this->write('qmx.yaml', "imports: [cfg/missing.yaml]\n");

        $refusal = $this->refusal();

        self::assertSame('Configuration file "cfg/missing.yaml" (imported by configuration file "qmx.yaml") was not found or is not accessible.', $refusal->summary());
        self::assertSame('qmx.yaml', $refusal->sources()[0]->importer()?->locator());
        self::assertSame([$this->directory . '/qmx.yaml'], $this->reads->getArrayCopy());
    }

    /** @return iterable<string, array{string}> */
    public static function provideTargetsThatAreNotRegularFiles(): iterable
    {
        yield 'directory' => ['dir.yaml'];
        yield 'fifo' => ['fifo.yaml'];
    }

    #[Test]
    #[DataProvider('provideTargetsThatAreNotRegularFiles')]
    public function itRefusesATargetThatIsNotARegularFileWithoutReadingIt(string $entry): void
    {
        mkdir($this->directory . '/dir.yaml');
        if ($entry === 'fifo.yaml' && (!\function_exists('posix_mkfifo') || !posix_mkfifo($this->directory . '/fifo.yaml', 0o600))) {
            self::markTestSkipped('posix_mkfifo is unavailable.');
        }
        $this->write('qmx.yaml', \sprintf("imports: [%s]\n", $entry));

        $refusal = $this->refusal();

        self::assertSame(\sprintf('Configuration file "%s" (imported by configuration file "qmx.yaml") is not a regular file.', $entry), $refusal->summary());
        self::assertSame([$this->directory . '/qmx.yaml'], $this->reads->getArrayCopy());
    }

    #[Test]
    public function itRefusesTheEntryGrammarAtItsPosition(): void
    {
        $this->write('qmx.yaml', "imports: [cfg/a.YAML, a.json]\n");
        $this->write('cfg/a.YAML', '');

        $refusal = $this->refusal();

        self::assertSame('Import "a.json" (imports[1]) is not a .yaml or .yml file.', $refusal->summary());
        self::assertSame(['imports', '1'], $refusal->position()?->segments);
        self::assertSame([$this->directory . '/qmx.yaml'], $this->reads->getArrayCopy());
    }

    private function chain(): ImportChain
    {
        return new ImportChain(new class ($this->reads) implements ConfigLoaderInterface {
            /** @param ArrayObject<int, string> $reads */
            public function __construct(private readonly ArrayObject $reads) {}

            public function read(string $physicalPath, ConfigurationOrigin $origin): LoadedDocument
            {
                $this->reads->append($physicalPath);

                return (new YamlConfigLoader())->read($physicalPath, $origin);
            }
        });
    }

    /** @return non-empty-list<AuthoredLayer> */
    private function expand(): array
    {
        return $this->chain()->expand($this->directory . '/qmx.yaml', self::root('qmx.yaml'));
    }

    private function refusal(): ConfigurationRefusal
    {
        try {
            $this->expand();
        } catch (ConfigurationRefusal $refusal) {
            return $refusal;
        }

        self::fail('The chain must be refused.');
    }

    private function write(string $file, string $content): void
    {
        file_put_contents($this->directory . '/' . $file, $content);
    }

    private static function root(string $locator): ConfigurationOrigin
    {
        return ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $locator);
    }

    /**
     * @param list<AuthoredLayer> $layers
     *
     * @return list<string>
     */
    private static function described(array $layers): array
    {
        return array_map(static fn(AuthoredLayer $layer): string => $layer->origin->describe(), $layers);
    }

    /**
     * @param list<AuthoredLayer> $layers
     *
     * @return list<mixed>
     */
    private static function plain(array $layers): array
    {
        return array_map(static fn(AuthoredLayer $layer): mixed => $layer->root->plain(), $layers);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path) || filetype($path) === 'fifo') {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
