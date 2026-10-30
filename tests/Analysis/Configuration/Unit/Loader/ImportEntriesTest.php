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
use Qualimetrix\Analysis\Configuration\Loader\ImportListSchema;
use Qualimetrix\Analysis\Configuration\Loader\ImportSelection;
use Qualimetrix\Analysis\Configuration\Loader\ImportTarget;
use Qualimetrix\Analysis\Configuration\Loader\ResolvedImportEntry;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

#[CoversClass(ImportEntries::class)]
#[CoversClass(ImportSelection::class)]
#[CoversClass(ImportListSchema::class)]
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
        foreach (['locked', 'vendor', 'cfg/inner'] as $directory) {
            if (is_dir($this->directory . '/' . $directory)) {
                chmod($this->directory . '/' . $directory, 0o755);
            }
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
        self::assertSame($isName, ImportListSchema::namesPreset($entry));
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
        self::assertFalse($this->resolve(['strict'])[0]->isSelector);
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
    public function itTakesPatternCharactersInAStringAsOrdinaryCharacters(): void
    {
        $this->write('cfg/a*.yaml', '');
        $this->write('cfg/ab.yaml', '');

        self::assertSame(['cfg/a*.yaml'], self::locators($this->resolve(['cfg/a*.yaml'])[0]));
        self::assertStringContainsString('"cfg/?.yaml"', $this->refusal(['cfg/?.yaml'])->summary());
    }

    #[Test]
    public function itPlacesASubtreesFilesInByteOrderOfTheirPathWhateverTheOrderTheyWereCreatedIn(): void
    {
        foreach (['b.yaml', 'B1.yaml', 'a-b.yaml', 'a.yaml', 'c.yaml', 'e.YML', 'notes.txt', 'sub/d.yaml', 'sub/deep/f.yml'] as $file) {
            $this->write('cfg/' . $file, '');
        }

        $entry = $this->resolve([['subtree' => 'cfg']])[0];

        self::assertTrue($entry->isSelector);
        self::assertSame(
            ['cfg/B1.yaml', 'cfg/a-b.yaml', 'cfg/a.yaml', 'cfg/b.yaml', 'cfg/c.yaml', 'cfg/e.YML', 'cfg/sub/d.yaml', 'cfg/sub/deep/f.yml'],
            self::locators($entry),
        );
    }

    #[Test]
    public function itKeepsTheListsWrittenOrderBetweenEntries(): void
    {
        $this->write('cfg/a.yaml', '');
        $this->write('cfg/b.yaml', '');
        $this->write('last.yaml', '');

        $entries = $this->resolve([['subtree' => 'cfg'], 'strict', 'last.yaml']);

        self::assertSame([0, 1, 2], array_map(static fn(ResolvedImportEntry $entry): int => $entry->position, $entries));
        self::assertSame([true, false, false], array_map(static fn(ResolvedImportEntry $entry): bool => $entry->isSelector, $entries));
    }

    #[Test]
    public function itAcceptsASelectorThatMatchesNothing(): void
    {
        $entry = $this->resolve([['subtree' => 'cfg'], ['regex' => '.*\.nomatch\.yaml']])[0];

        self::assertTrue($entry->isSelector);
        self::assertSame([], $entry->targets);
    }

    #[Test]
    public function itMatchesARegexAgainstTheWholePathBelowTheImportingDirectory(): void
    {
        foreach (['acme/pkg/qmx-extension.yaml', 'acme/pkg/sub/qmx-extension.yaml', 'acme/qmx-extension.yaml', 'acme/pkg/qmx-extension.yaml.bak', 'acme/pkg/qmx-extension.yml'] as $file) {
            $this->write('vendor/' . $file, '');
        }
        $this->write('qmx-extension.yaml', '');

        $entry = $this->resolve([['regex' => 'vendor/[^/]+/[^/]+/qmx-extension\.yaml']])[0];

        self::assertSame(['vendor/acme/pkg/qmx-extension.yaml'], self::locators($entry));
    }

    #[Test]
    public function itWalksFromTheImportingDirectoryWhenARegexHasNoLiteralDirectory(): void
    {
        $this->write('team.local.yaml', '');
        $this->write('cfg/deep/a.local.yaml', '');
        $this->write('cfg/b.yaml', '');

        self::assertSame(['cfg/deep/a.local.yaml', 'team.local.yaml'], self::locators($this->resolve([['regex' => '.*\.local\.yaml']])[0]));
    }

    #[Test]
    public function itDoesNotEnterADirectoryOutsideTheLiteralPrefixOfARegex(): void
    {
        $this->write('vendor/a.yaml', '');
        mkdir($this->directory . '/locked');
        $this->lock();

        self::assertSame(['vendor/a.yaml'], self::locators($this->resolve([['regex' => 'vendor/.*\.yaml']])[0]));
        self::assertSame(
            'Import "regex:.*\.yaml" (imports[0]) needs the contents of the directory "locked", which cannot be listed.',
            $this->refusal([['regex' => '.*\.yaml']])->summary(),
        );
    }

    /** @return iterable<string, array{SelectorKind, string, string}> */
    public static function provideWalkRoots(): iterable
    {
        yield 'a subtree is its own root' => [SelectorKind::Subtree, 'config/qmx', 'config/qmx'];
        yield 'directories before a bracket' => [SelectorKind::Regex, 'vendor/[^/]+/[^/]+/qmx-extension\.yaml', 'vendor'];
        yield 'nested literal directories' => [SelectorKind::Regex, 'cfg/teams/.*\.yaml', 'cfg/teams'];
        yield 'literal file name only' => [SelectorKind::Regex, 'team\.yaml', ''];
        yield 'a hyphenated file name' => [SelectorKind::Regex, 'cfg/team-a\.yaml', 'cfg'];
        yield 'a trailing slash' => [SelectorKind::Regex, 'a/b/.*', 'a/b'];
        yield 'optional last letter of a directory' => [SelectorKind::Regex, 'vendor/acme?/x\.yaml', 'vendor'];
        yield 'optional whole directory' => [SelectorKind::Regex, 'vendor/a*/x\.yaml', 'vendor'];
        yield 'a counted repeat' => [SelectorKind::Regex, 'cfg/x{2}/a\.yaml', 'cfg'];
        yield 'an optional slash' => [SelectorKind::Regex, 'cfg/?x', ''];
        yield 'an alternation in a group' => [SelectorKind::Regex, 'vendor/(a|b)/x\.yaml', ''];
        yield 'a top-level alternation' => [SelectorKind::Regex, 'vendor/a/x|other/y', ''];
        yield 'a group first' => [SelectorKind::Regex, '(?:vendor/)?x\.yaml', ''];
        yield 'a leading anchor' => [SelectorKind::Regex, '^vendor/x', ''];
    }

    #[Test]
    #[DataProvider('provideWalkRoots')]
    public function itBoundsTheWalkByTheDirectoryARegexCannotLeave(SelectorKind $kind, string $value, string $root): void
    {
        self::assertSame($root, ImportSelection::walkRoot(new PathPattern(new SelectorDefinition($kind, $value))));
    }

    #[Test]
    public function itRefusesAWalkRootThatIsMissingOrNotADirectory(): void
    {
        $this->write('file.yaml', '');
        $this->write('notes', '');

        self::assertSame('Import "subtree:nowhere" (imports[0]) names the directory "nowhere", which does not exist.', $this->refusal([['subtree' => 'nowhere']])->summary());
        self::assertSame('Import "subtree:file.yaml" (imports[0]) names "file.yaml", which is not a directory; write a file as a plain string.', $this->refusal([['subtree' => 'file.yaml']])->summary());
        self::assertSame('Import "regex:nowhere/.*\.yaml" (imports[0]) names the directory "nowhere", which does not exist.', $this->refusal([['regex' => 'nowhere/.*\.yaml']])->summary());
        self::assertSame('Import "regex:notes/.*" (imports[0]) names "notes", which is not a directory.', $this->refusal([['regex' => 'notes/.*']])->summary());
    }

    #[Test]
    public function itRefusesADirectoryItMustListAndCannot(): void
    {
        $this->lock();

        $refusal = $this->refusal([['subtree' => 'locked']]);

        self::assertSame('Import "subtree:locked" (imports[0]) needs the contents of the directory "locked", which cannot be listed.', $refusal->summary());
    }

    /** A directory readable but not searchable lists its names yet hides what they stand for. */
    #[Test]
    public function itRefusesADirectoryItCanListButNotEnter(): void
    {
        mkdir($this->directory . '/cfg/inner');
        $this->write('cfg/inner/a.yaml', '');
        $this->lock('cfg/inner', 0o444);

        try {
            $inner = $this->refusal([['subtree' => 'cfg']])->summary();
            $root = $this->refusal([['subtree' => 'cfg/inner']])->summary();
        } finally {
            chmod($this->directory . '/cfg/inner', 0o755);
        }

        self::assertSame('Import "subtree:cfg" (imports[0]) needs to enter the directory "cfg/inner", which cannot be entered.', $inner);
        self::assertSame('Import "subtree:cfg/inner" (imports[0]) needs to enter the directory "cfg/inner", which cannot be entered.', $root);
    }

    #[Test]
    public function itRefusesADirectoryItMustEnterOnTheWayToTheRootAndCannot(): void
    {
        mkdir($this->directory . '/vendor/a', 0o777, true);
        $this->lock('vendor', 0o444);

        try {
            $refusal = $this->refusal([['subtree' => 'vendor/a']]);
        } finally {
            chmod($this->directory . '/vendor', 0o755);
        }

        self::assertSame('Import "subtree:vendor/a" (imports[0]) needs to enter the directory "vendor", which cannot be entered.', $refusal->summary());
    }

    #[Test]
    public function itDescendsADirectoryNamedLikeAConfigurationFile(): void
    {
        $this->write('cfg/d.yaml/e.yaml', '');

        self::assertSame(['cfg/d.yaml/e.yaml'], self::locators($this->resolve([['subtree' => 'cfg']])[0]));
    }

    #[Test]
    public function itWalksARealDirectoryOnceWhateverNumberOfNamesLeadToIt(): void
    {
        $this->write('vendor/a/qmx.yaml', '');
        symlink($this->directory . '/vendor/a', $this->directory . '/vendor/b');
        symlink($this->directory . '/cfg', $this->directory . '/cfg/loop');
        $this->write('cfg/x.yaml', '');

        self::assertSame(['vendor/a/qmx.yaml'], self::locators($this->resolve([['subtree' => 'vendor']])[0]));
        self::assertSame(['cfg/x.yaml'], self::locators($this->resolve([['subtree' => 'cfg']])[0]));
    }

    #[Test]
    public function itRefusesAMatchThatIsNotARegularFile(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('Named pipes need ext-posix.');
        }
        posix_mkfifo($this->directory . '/cfg/pipe.yaml', 0o644);

        $refusal = $this->refusal([['subtree' => 'cfg']]);

        self::assertSame('Configuration file "cfg/pipe.yaml" (imported by configuration file "qmx.yaml") is not a regular file.', $refusal->summary());
    }

    #[Test]
    public function itRefusesABrokenLinkItMatchesButNotOneItDoesNot(): void
    {
        symlink($this->directory . '/nowhere', $this->directory . '/cfg/dead.yaml');

        self::assertSame(
            'Configuration file "cfg/dead.yaml" (imported by configuration file "qmx.yaml") was not found or is not accessible.',
            $this->refusal([['subtree' => 'cfg']])->summary(),
        );
        self::assertSame([], self::locators($this->resolve([['regex' => 'cfg/live\.yaml']])[0]));
    }

    #[Test]
    public function itRefusesARegexPcreCannotMatch(): void
    {
        $this->write('cfg/' . str_repeat('a', 40) . '.yaml', '');

        $refusal = $this->refusal([['regex' => 'cfg/(?:a+)+b\.yaml']]);

        self::assertStringContainsString('could not match', $refusal->summary());
    }

    /** @return iterable<string, array{list<mixed>, non-empty-string}> */
    public static function provideEntriesTheListCannotHold(): iterable
    {
        yield 'an exact selector' => [[['exact' => 'cfg/a.yaml']], 'Import {exact: cfg/a.yaml} (imports[0]) names one file; write a file path as a plain string.'];
        yield 'an unknown kind' => [[['glob' => 'cfg/*.yaml']], 'Unknown selector kind "glob"; expected exact, subtree, or regex.'];
        yield 'two kinds in one entry' => [[['subtree' => 'cfg', 'regex' => 'x']], 'Selector entries must be one-entry mappings: {exact: value}, {subtree: value}, or {regex: value}. Bare strings are not supported.'];
        yield 'a leading slash' => [[['subtree' => '/etc']], 'Path selector "subtree:/etc" must not have leading, trailing, or empty path segments'];
        yield 'a trailing slash' => [[['subtree' => 'cfg/']], 'Path selector "subtree:cfg/" must not have leading, trailing, or empty path segments'];
        yield 'a parent segment' => [[['subtree' => '../shared']], 'Import {subtree: ../shared} (imports[0]) names a directory with a "." or ".." segment; a subtree is named below the importing file\'s directory.'];
        yield 'a dot segment' => [[['subtree' => 'cfg/./x']], 'Import {subtree: cfg/./x} (imports[0]) names a directory with a "." or ".." segment; a subtree is named below the importing file\'s directory.'];
        yield 'an invalid pattern' => [[['regex' => '(']], 'Selector "regex:(" is not valid PCRE'];
        yield 'an empty value' => [[['subtree' => '']], 'Selector "subtree" must have a non-empty string value.'];
        yield 'a number' => [[5], 'Import entry (imports[0]) is neither a file path nor a selector mapping.'];
        yield 'a list' => [[['a.yaml', 'b.yaml']], 'Import entry (imports[0]) is neither a file path nor a selector mapping.'];
        yield 'an empty string' => [[''], 'Import "" (imports[0]) is empty; name a file.'];
    }

    /**
     * @param list<mixed> $entries
     * @param non-empty-string $summary
     */
    #[Test]
    #[DataProvider('provideEntriesTheListCannotHold')]
    public function itRefusesAnEntryThatIsNeitherAPathNorAnExplicitSelector(array $entries, string $summary): void
    {
        self::assertStringStartsWith($summary, $this->refusal($entries)->summary());
    }

    /** @return iterable<string, array{list<string|array<string, string>>, list<list<string>>}> */
    public static function provideOverlappingEntries(): iterable
    {
        yield 'explicit after the selector' => [[['subtree' => 'cfg'], 'cfg/b.yaml'], [['cfg/a.yaml', 'cfg/c.yaml'], ['cfg/b.yaml']]];
        yield 'explicit before the selector' => [['cfg/b.yaml', ['subtree' => 'cfg']], [['cfg/b.yaml'], ['cfg/a.yaml', 'cfg/c.yaml']]];
        yield 'two selectors' => [[['regex' => 'cfg/[ab]\.yaml'], ['subtree' => 'cfg']], [['cfg/a.yaml', 'cfg/b.yaml'], ['cfg/c.yaml']]];
        yield 'the same selector twice' => [[['subtree' => 'cfg'], ['subtree' => 'cfg']], [['cfg/a.yaml', 'cfg/b.yaml', 'cfg/c.yaml'], []]];
    }

    /**
     * @param list<string|array<string, string>> $entries
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

        self::assertSame(['other.yaml'], self::locators($this->resolve([['regex' => '[^/]*\.yaml']])[0]));
    }

    #[Test]
    public function itJudgesEntriesInWrittenOrder(): void
    {
        $this->lock();

        self::assertStringStartsWith('Import "subtree:locked" (imports[0])', $this->refusal([['subtree' => 'locked'], 'missing.yaml'])->summary());
        self::assertStringStartsWith('Configuration file "missing.yaml"', $this->refusal(['missing.yaml', ['subtree' => 'locked']])->summary());
    }

    #[Test]
    public function itNamesMatchesFromTheDisplayDirectoryOfTheImportingFile(): void
    {
        $this->write('cfg/a.yaml', '');

        $entries = (new ImportEntries())->resolve(
            $this->values([['subtree' => 'cfg']]),
            ImportTarget::ofFile($this->directory . '/qmx.yaml', ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'sub/qmx.yaml')),
        );

        self::assertSame(['sub/cfg/a.yaml'], self::locators($entries[0]));
    }

    #[Test]
    public function itMarksASymlinkTarget(): void
    {
        $this->write('cfg/a.yaml', '');
        $this->write('other.yaml', '');
        symlink($this->directory . '/other.yaml', $this->directory . '/cfg/b.yaml');

        $targets = $this->resolve([['subtree' => 'cfg']])[0]->targets;

        self::assertSame([false, true], array_map(static fn(ImportTarget $target): bool => $target->isSymlink, $targets));
    }

    #[Test]
    public function itKeysABuiltInPresetInsideAPharByItsNameWithoutReadingIt(): void
    {
        $origin = ConfigurationOrigin::of(ConfigurationSource::Preset, 'strict');

        $target = ImportTarget::ofPreset('strict', 'phar:///opt/qmx.phar/src/Analysis/Configuration/Preset/strict.yaml', $origin);

        self::assertSame('preset:strict', $target->key);
        self::assertSame('preset:strict', ImportTarget::presetKeyIfRegular('strict', $target->physicalPath));
        self::assertSame('phar:///opt/qmx.phar/src/Analysis/Configuration/Preset', $target->realDirectory());
    }

    #[Test]
    public function itKeysAPresetOutsideAPharByTheIdentityOfItsFile(): void
    {
        $path = (string) (new PresetResolver())->builtInPath('strict');

        $target = ImportTarget::ofPreset('strict', $path, ConfigurationOrigin::of(ConfigurationSource::Preset, 'strict'));

        self::assertStringStartsWith('file:', $target->key);
        self::assertSame($target->key, ImportTarget::keyIfRegular($path));
        self::assertNull(ImportTarget::keyIfRegular($this->directory . '/cfg'));
    }

    /**
     * @param list<string|array<string, string>> $entries
     *
     * @return list<ResolvedImportEntry>
     */
    private function resolve(array $entries): array
    {
        $origin = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');

        return (new ImportEntries())->resolve(
            $this->values($entries),
            ImportTarget::ofFile($this->directory . '/qmx.yaml', $origin),
        );
    }

    /** @param list<mixed> $entries */
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
     * @param list<mixed> $entries
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

    private function lock(string $directory = 'locked', int $mode = 0o000): void
    {
        if (!is_dir($this->directory . '/' . $directory)) {
            mkdir($this->directory . '/' . $directory);
        }
        chmod($this->directory . '/' . $directory, $mode);
        if (is_executable($this->directory . '/' . $directory)) {
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
        if (is_link($path) || is_file($path) || @filetype($path) === 'fifo') {
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
