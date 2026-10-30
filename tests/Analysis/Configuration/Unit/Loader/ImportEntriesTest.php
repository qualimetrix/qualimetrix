<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Loader;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\Loader\ImportEntries;
use Qualimetrix\Analysis\Configuration\Loader\ImportGlob;
use Qualimetrix\Analysis\Configuration\Loader\ImportingFile;
use Qualimetrix\Analysis\Configuration\Loader\ImportTarget;
use Qualimetrix\Analysis\Configuration\Loader\ResolvedImportEntry;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;

#[CoversClass(ImportEntries::class)]
#[CoversClass(ImportGlob::class)]
#[CoversClass(ImportTarget::class)]
final class ImportEntriesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/qmx-import-entries-' . bin2hex(random_bytes(6));
        mkdir($directory . '/cfg', 0o777, true);
        $this->directory = (string) realpath($directory);
        $this->write('qmx.yaml', '');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory . '/locked')) {
            chmod($this->directory . '/locked', 0o755);
        }
        self::remove($this->directory);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideEntryClassification(): iterable
    {
        yield 'built-in name' => ['strict', true];
        yield 'unknown name' => ['base', true];
        yield 'with extension' => ['strict.yaml', false];
        yield 'upper-case extension' => ['team.YML', false];
        yield 'other extension' => ['a.json', false];
        yield 'with a directory' => ['cfg/base', false];
        yield 'with a backslash' => ['cfg\\base', false];
        yield 'hidden file' => ['.yaml', false];
    }

    #[Test]
    #[DataProvider('provideEntryClassification')]
    public function itTellsAPresetNameFromAPath(string $entry, bool $isName): void
    {
        self::assertSame($isName, ImportEntries::namesPreset($entry));
    }

    #[Test]
    public function itResolvesABuiltInNameToTheBundledFileAndNamesItAsAPresetImportedByTheFile(): void
    {
        $targets = $this->resolve(['strict'])[0]->targets;

        self::assertCount(1, $targets);
        self::assertSame((new PresetResolver())->builtInPath('strict'), $targets[0]->physicalPath);
        self::assertSame('preset "strict" (imported by configuration file "qmx.yaml")', $targets[0]->origin->describe());
        self::assertSame(ConfigurationSource::Preset, $targets[0]->origin->source());
        self::assertStringStartsWith('file:', $targets[0]->key);
        self::assertFalse($this->resolve(['strict'])[0]->isGlob);
    }

    #[Test]
    public function itRefusesAnUnknownNameAtItsPosition(): void
    {
        $this->write('cfg/a.yaml', '');

        $refusal = $this->refusal(['cfg/a.yaml', 'base']);

        self::assertSame('Unknown preset "base". Available presets: ci, legacy, strict. A file needs a .yaml or .yml extension.', $refusal->summary());
        self::assertSame(['imports', '1'], $refusal->position()?->segments);
    }

    #[Test]
    public function itRefusesTheNameAndTheBundledPathOfAPresetAsOneFile(): void
    {
        $path = (new PresetResolver())->builtInPath('strict');

        $refusal = $this->refusal(['strict', (string) $path]);

        self::assertStringStartsWith('Entries imports[0] ("strict") and imports[1] (', $refusal->summary());
        self::assertStringContainsString('name the same file; list each file once.', $refusal->summary());
    }

    #[Test]
    public function itTakesAnExtensionOfAnyCaseAsAPathAndNamesItFromTheImportingFile(): void
    {
        $this->write('cfg/team.YML', '');

        $targets = $this->resolve(['./cfg/team.YML'])[0]->targets;

        self::assertSame($this->directory . '/./cfg/team.YML', $targets[0]->physicalPath);
        self::assertSame('cfg/team.YML', $targets[0]->origin->locator());
    }

    #[Test]
    public function itPlacesAGlobsMatchesInByteOrderWhateverTheOrderTheyWereCreatedIn(): void
    {
        foreach (['b.yaml', 'B1.yaml', 'a-b.yaml', 'a.yaml', 'c.yaml'] as $file) {
            $this->write('cfg/' . $file, '');
        }

        $entry = $this->resolve(['cfg/*.yaml'])[0];

        self::assertTrue($entry->isGlob);
        self::assertSame(['cfg/B1.yaml', 'cfg/a-b.yaml', 'cfg/a.yaml', 'cfg/b.yaml', 'cfg/c.yaml'], self::locators($entry));
    }

    #[Test]
    public function itAcceptsAGlobThatMatchesNothing(): void
    {
        $entry = $this->resolve(['cfg/*.yaml'])[0];

        self::assertTrue($entry->isGlob);
        self::assertSame([], $entry->targets);
    }

    #[Test]
    public function itKeepsTheListsWrittenOrderBetweenEntries(): void
    {
        $this->write('cfg/a.yaml', '');
        $this->write('cfg/b.yaml', '');
        $this->write('last.yaml', '');

        $entries = $this->resolve(['cfg/*.yaml', 'strict', 'last.yaml']);

        self::assertSame([0, 1, 2], array_map(static fn(ResolvedImportEntry $entry): int => $entry->position, $entries));
        self::assertSame([true, false, false], array_map(static fn(ResolvedImportEntry $entry): bool => $entry->isGlob, $entries));
    }

    #[Test]
    public function itRefusesAGlobWhoseLiteralDirectoryIsMissing(): void
    {
        $refusal = $this->refusal(['nowhere/*.yaml']);

        self::assertSame('Import "nowhere/*.yaml" (imports[0]) names the directory "nowhere" before its first glob character, which does not exist.', $refusal->summary());
        self::assertSame(['imports', '0'], $refusal->position()?->segments);
    }

    #[Test]
    public function itRefusesADirectoryItMustListAndCannot(): void
    {
        $this->lock();

        $refusal = $this->refusal(['locked/*.yaml']);

        self::assertSame('Import "locked/*.yaml" (imports[0]) needs the contents of the directory "locked", which cannot be listed.', $refusal->summary());
    }

    #[Test]
    public function itRefusesADirectoryItMustEnterToReachALiteralSegmentAndCannot(): void
    {
        mkdir($this->directory . '/vendor/a', 0o777, true);
        $this->lock('vendor/a');

        try {
            $refusal = $this->refusal(['vendor/*/qmx.yaml']);
        } finally {
            chmod($this->directory . '/vendor/a', 0o755);
        }

        self::assertSame('Import "vendor/*/qmx.yaml" (imports[0]) needs to enter the directory "vendor/a", which cannot be entered.', $refusal->summary());
    }

    #[Test]
    public function itPassesOverACandidateThatIsNotADirectoryWhereADirectoryIsNeeded(): void
    {
        mkdir($this->directory . '/vendor/acme/x', 0o777, true);
        $this->write('vendor/autoload.php', '');
        $this->write('vendor/acme/x/qmx.yaml', '');
        mkdir($this->directory . '/vendor/other/x', 0o777, true);

        $entry = $this->resolve(['vendor/*/x/qmx.yaml'])[0];

        self::assertSame(['vendor/acme/x/qmx.yaml'], self::locators($entry));
    }

    #[Test]
    public function itNeverMatchesTheDotEntriesAndKeepsHiddenFilesToAnExplicitDot(): void
    {
        $this->write('cfg/.a.yaml', '');
        $this->write('cfg/b.yaml', '');
        $this->write('x.yaml', '');

        self::assertSame(['cfg/b.yaml'], self::locators($this->resolve(['cfg/*.yaml'])[0]));
        self::assertSame(['cfg/.a.yaml'], self::locators($this->resolve(['cfg/.*.yaml'])[0]));
        self::assertSame([], self::locators($this->resolve(['cfg/.*/x.yaml'])[0]));
    }

    #[Test]
    public function itMatchesCaseSensitively(): void
    {
        $this->write('cfg/a.YAML', '');

        self::assertSame([], self::locators($this->resolve(['cfg/*.yaml'])[0]));
    }

    #[Test]
    public function itMatchesWithinOneSegmentByQuestionMarkAndBracket(): void
    {
        foreach (['a1.yaml', 'a2.yaml', 'a3.yaml', 'ab.yaml'] as $file) {
            $this->write('cfg/' . $file, '');
        }

        self::assertSame(['cfg/a1.yaml', 'cfg/a2.yaml', 'cfg/a3.yaml', 'cfg/ab.yaml'], self::locators($this->resolve(['cfg/a?.yaml'])[0]));
        self::assertSame(['cfg/a1.yaml', 'cfg/a3.yaml'], self::locators($this->resolve(['cfg/a[13].yaml'])[0]));
        self::assertSame(['cfg/a1.yaml', 'cfg/a2.yaml', 'cfg/a3.yaml'], self::locators($this->resolve(['cfg/a[0-9].yaml'])[0]));
    }

    /** @return iterable<string, array{list<string>, list<list<string>>}> */
    public static function provideOverlappingEntries(): iterable
    {
        yield 'explicit after the glob' => [['cfg/*.yaml', 'cfg/b.yaml'], [['cfg/a.yaml', 'cfg/c.yaml'], ['cfg/b.yaml']]];
        yield 'explicit before the glob' => [['cfg/b.yaml', 'cfg/*.yaml'], [['cfg/b.yaml'], ['cfg/a.yaml', 'cfg/c.yaml']]];
        yield 'two globs' => [['cfg/[ab].yaml', 'cfg/*.yaml'], [['cfg/a.yaml', 'cfg/b.yaml'], ['cfg/c.yaml']]];
        yield 'the same glob twice' => [['cfg/*.yaml', 'cfg/*.yaml'], [['cfg/a.yaml', 'cfg/b.yaml', 'cfg/c.yaml'], []]];
    }

    /**
     * @param list<string> $entries
     * @param list<list<string>> $expected
     */
    #[Test]
    #[DataProvider('provideOverlappingEntries')]
    public function itPlacesAFileOnceAtTheEntryThatNamesItFirstOrExplicitly(array $entries, array $expected): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->write('cfg/' . $name . '.yaml', '');
        }

        self::assertSame($expected, array_map(self::locators(...), $this->resolve($entries)));
    }

    #[Test]
    public function itSkipsTheImportingFileInItsOwnDirectory(): void
    {
        $this->write('other.yaml', '');

        self::assertSame(['other.yaml'], self::locators($this->resolve(['*.yaml'])[0]));
    }

    #[Test]
    public function itPlacesOneFileReachedTwiceThroughSymlinkedDirectoriesOnceAtItsFirstMatch(): void
    {
        mkdir($this->directory . '/vendor/a', 0o777, true);
        $this->write('vendor/a/qmx.yaml', '');
        symlink($this->directory . '/vendor/a', $this->directory . '/vendor/b');

        self::assertSame(['vendor/a/qmx.yaml'], self::locators($this->resolve(['vendor/*/qmx.yaml'])[0]));
    }

    #[Test]
    public function itRefusesAMatchThatIsNotARegularFile(): void
    {
        mkdir($this->directory . '/cfg/d.yaml');

        $refusal = $this->refusal(['cfg/*.yaml']);

        self::assertSame('Configuration file "cfg/d.yaml" (imported by configuration file "qmx.yaml") is not a regular file.', $refusal->summary());
    }

    #[Test]
    public function itNamesMatchesFromTheDisplayDirectoryOfTheImportingFile(): void
    {
        $this->write('cfg/a.yaml', '');

        $entries = (new ImportEntries())->resolve(
            $this->values(['cfg/*.yaml', $this->directory . '/cfg/*.yaml']),
            new ImportingFile(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'sub/qmx.yaml'), $this->directory, 'sub', ImportTarget::ofFile($this->directory . '/qmx.yaml', ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'))->key),
        );

        self::assertSame(['sub/cfg/a.yaml'], self::locators($entries[0]));
        self::assertSame([], self::locators($entries[1]));
    }

    #[Test]
    public function itNamesAnAbsolutePatternsMatchesByTheirAbsolutePath(): void
    {
        $this->write('cfg/a.yaml', '');

        self::assertSame([$this->directory . '/cfg/a.yaml'], self::locators($this->resolve([$this->directory . '/cfg/*.yaml'])[0]));
    }

    #[Test]
    public function itMarksASymlinkTarget(): void
    {
        $this->write('cfg/a.yaml', '');
        $this->write('other.yaml', '');
        symlink($this->directory . '/other.yaml', $this->directory . '/cfg/b.yaml');

        $targets = $this->resolve(['cfg/*.yaml'])[0]->targets;

        self::assertSame([false, true], array_map(static fn(ImportTarget $target): bool => $target->isSymlink, $targets));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function provideGrammar(): iterable
    {
        yield 'plain' => ['cfg/*.yaml', null];
        yield 'class' => ['cfg/[ab]*.yaml', null];
        yield 'class holding a bracket first' => ['cfg/[]a].yaml', null];
        yield 'recursive' => ['cfg/**/a.yaml', 'uses "**"; a glob stands for one directory level, so name each level'];
        yield 'brace' => ['cfg/{a,b}.yaml', 'uses "{"; braces are not expanded, write one entry per alternative'];
        yield 'backslash' => ['cfg/a\\*.yaml', 'contains a backslash; a pattern cannot escape characters'];
        yield 'unclosed' => ['cfg/[a.yaml', 'has a "[" without its closing "]"'];
        yield 'empty set' => ['cfg/[].yaml', 'has a "[" without its closing "]"'];
        yield 'second unclosed' => ['cfg/[a]b[.yaml', 'has a "[" without its closing "]"'];
    }

    #[Test]
    #[DataProvider('provideGrammar')]
    public function itJudgesAPatternByItsGrammar(string $pattern, ?string $problem): void
    {
        self::assertSame($problem, ImportGlob::grammarProblem($pattern));
    }

    /**
     * @param list<string> $entries
     *
     * @return list<ResolvedImportEntry>
     */
    private function resolve(array $entries): array
    {
        $origin = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');

        return (new ImportEntries())->resolve(
            $this->values($entries),
            new ImportingFile($origin, $this->directory, '.', ImportTarget::ofFile($this->directory . '/qmx.yaml', $origin)->key),
        );
    }

    /** @param list<string> $entries */
    private function refusal(array $entries): ConfigurationRefusal
    {
        try {
            $this->resolve($entries);
        } catch (ConfigurationRefusal $refusal) {
            return $refusal;
        }

        self::fail('The list must be refused.');
    }

    /**
     * @param list<string> $entries
     *
     * @return list<\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface>
     */
    private function values(array $entries): array
    {
        $list = DocumentComposer::compose(new DocumentSchema([ConfigurationRoot::Imports]), [
            new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'), AuthoredNode::fromPlain(['imports' => $entries])),
        ])->get('imports');
        self::assertInstanceOf(ResolvedListInterface::class, $list);

        return $list->items();
    }

    /** @return list<string> */
    private static function locators(ResolvedImportEntry $entry): array
    {
        return array_map(static fn(ImportTarget $target): string => (string) $target->origin->locator(), $entry->targets);
    }

    private function lock(string $directory = 'locked'): void
    {
        if (!is_dir($this->directory . '/' . $directory)) {
            mkdir($this->directory . '/' . $directory);
        }
        chmod($this->directory . '/' . $directory, 0o000);
        if (is_readable($this->directory . '/' . $directory)) {
            chmod($this->directory . '/' . $directory, 0o755);
            self::markTestSkipped('The process ignores directory permissions.');
        }
    }

    private function write(string $file, string $content): void
    {
        $path = $this->directory . '/' . $file;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }
        file_put_contents($path, $content);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
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
