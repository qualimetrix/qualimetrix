<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineConflictException;
use Qualimetrix\Baseline\BaselineEdge;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineEntryParser;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Baseline\EntrySelector;
use Qualimetrix\Baseline\InertBaselineEntry;
use Qualimetrix\Baseline\InertEntryReason;
use Qualimetrix\Baseline\RunScope;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;
use RuntimeException;

#[CoversClass(BaselineWriter::class)]
#[CoversClass(BaselineConflictException::class)]
final class BaselineWriterTest extends TestCase
{
    private BaselineWriter $writer;
    private BaselineLoader $loader;
    private string $tempDir;
    private AbsolutePath $projectRoot;

    protected function setUp(): void
    {
        $this->writer = new BaselineWriter();
        $this->loader = new BaselineLoader(
            new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults()),
        );
        $this->tempDir = sys_get_temp_dir() . '/qmx_baseline_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->projectRoot = AbsolutePath::fromString($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
    }

    #[Test]
    public function itWritesTheEnvelopeOfTheContract(): void
    {
        $path = $this->write($this->baseline());

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(['version', 'generated', 'scope', 'entries'], array_keys($data));
        self::assertSame(11, $data['version']);
        self::assertSame('2026-08-05T12:00:00+03:00', $data['generated']);
        self::assertSame(['src'], $data['scope']);
    }

    #[Test]
    public function itGroupsEntriesUnderTheirSymbolKeys(): void
    {
        $path = $this->write($this->baseline());

        /** @var array{entries: array<string, list<array<string, mixed>>>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('callable:App\Foo::bar', $data['entries']);
        self::assertSame(
            'complexity.cyclomatic#complexity.cyclomatic.callable',
            $data['entries']['callable:App\Foo::bar'][0]['channel'],
        );
    }

    #[Test]
    public function itWritesAnEmptyBaselineAsAnObjectNotAnArray(): void
    {
        $path = $this->write(new Baseline(
            generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            scope: [],
            entries: [],
        ));

        self::assertStringContainsString('"entries": {}', (string) file_get_contents($path));
    }

    /**
     * Byte stability under a fixed clock: two writes of the same analysis
     * produce the same file, whatever order the entries were assembled in.
     */
    #[Test]
    public function itProducesTheSameBytesForTheSameAnalysis(): void
    {
        $first = $this->write($this->baseline(), 'first.json');
        $second = $this->write($this->baseline(reversed: true), 'second.json');

        self::assertSame(file_get_contents($first), file_get_contents($second));
    }

    #[Test]
    public function itRoundTripsEdgesAndMultiElementMagnitudes(): void
    {
        $path = $this->write($this->baseline());
        $reloaded = $this->loader->load($path);

        self::assertSame(3, $reloaded->count());
        self::assertSame(
            file_get_contents($path),
            file_get_contents($this->write($reloaded->detached(), 'again.json')),
        );

        $duplication = $reloaded->findByIdentity(new BaselineIdentity(
            'file:src/Legacy/dup.php',
            new ViolationChannel('duplication.code-duplication', 'duplication.code-duplication'),
        ));
        self::assertNotNull($duplication);
        self::assertSame([40.0, 100.0], $duplication->magnitudes);

        $edge = $reloaded->findByIdentity(new BaselineIdentity(
            'class:App\Web\Controller',
            new ViolationChannel('architecture.layer-violation', 'architecture.layer-violation'),
            null,
            new BaselineEdge('class:App\Db\Connection', DependencyType::New_),
        ));
        self::assertNotNull($edge);
    }

    /**
     * ADR 0017 normalisation earns its zero tolerance only if the written text
     * is the same at any `serialize_precision`. A single-value test runs
     * under the developer's own ini and proves nothing for users.
     */
    #[Test]
    public function itRoundTripsMagnitudesExactlyAtEverySerializePrecision(): void
    {
        $original = \ini_get('serialize_precision');

        try {
            $written = [];

            foreach (['15', '17', '-1'] as $precision) {
                ini_set('serialize_precision', $precision);

                $path = $this->write($this->magnitudeBaseline(), 'precision-' . $precision . '.json');
                $written[$precision] = (string) file_get_contents($path);

                self::assertSame(
                    [0.1, 1.234568, 40.0, 1234.567891],
                    $this->loader->load($path)->entries[0]->magnitudes,
                    'serialize_precision=' . $precision,
                );
            }

            self::assertSame($written['15'], $written['17']);
            self::assertSame($written['15'], $written['-1']);
        } finally {
            ini_set('serialize_precision', $original === false ? '-1' : $original);
        }
    }

    #[Test]
    public function itCleansUpTheTemporaryFileWhenTheRenameFails(): void
    {
        // A non-empty directory cannot be replaced by rename().
        $path = $this->tempDir . '/occupied';
        mkdir($path, 0755, true);
        file_put_contents($path . '/keep-me', 'x');

        try {
            $this->writer->write($this->baseline(), $path, $this->projectRoot);
            self::fail('Expected the write to fail.');
        } catch (RuntimeException) {
            // expected
        }

        $leftovers = glob($this->tempDir . '/occupied.tmp.*');
        self::assertSame([], $leftovers === false ? [] : $leftovers);
    }

    #[Test]
    public function itLeavesNoTemporaryFileBehindOnSuccess(): void
    {
        $this->write($this->baseline());

        $leftovers = glob($this->tempDir . '/baseline.json.tmp.*');
        self::assertSame([], $leftovers === false ? [] : $leftovers);
    }

    /**
     * The compare-and-swap: a second writer holding a stale reading of the
     * file is refused rather than silently discarding the first writer's
     * work.
     */
    #[Test]
    public function itRefusesToOverwriteAFileThatChangedSinceItWasRead(): void
    {
        $path = $this->write($this->baseline());

        $readByBoth = $this->loader->load($path);

        // Writer A lands.
        $this->writer->write($readByBoth, $path, $this->projectRoot);
        file_put_contents($path, (string) file_get_contents($path) . "\n");

        // Writer B still holds the reading from before A.
        $this->expectException(BaselineConflictException::class);
        $this->writer->write($readByBoth, $path, $this->projectRoot);
    }

    #[Test]
    public function itRefusesToReplaceASymlinkEvenWhenItsReferentMatchesTheExpectedHash(): void
    {
        $referent = $this->tempDir . '/referent.json';
        $contents = '{"owned": "by another process"}';
        file_put_contents($referent, $contents);

        $path = $this->tempDir . '/baseline-link.json';
        symlink($referent, $path);

        try {
            $this->writer->write(
                $this->baseline()->withSourceContentHash(hash('sha256', $contents)),
                $path,
                $this->projectRoot,
            );
            self::fail('Expected the write to be refused.');
        } catch (BaselineConflictException $e) {
            self::assertStringContainsString('is a symbolic link', $e->getMessage());
        }

        self::assertTrue(is_link($path));
        self::assertSame($referent, readlink($path));
        self::assertSame($contents, file_get_contents($referent));
    }

    #[Test]
    public function itWritesWhenTheTargetIsStillExpectedToBeAbsent(): void
    {
        $path = $this->tempDir . '/expected-absent.json';

        $this->writer->write(
            $this->baseline()->withExpectedSourceAbsence(),
            $path,
            $this->projectRoot,
        );

        self::assertFileExists($path);
    }

    #[Test]
    public function itRefusesWhenAFileAppearedSinceItWasExpectedAbsent(): void
    {
        $path = $this->tempDir . '/expected-absent.json';
        $created = '{"created": "by another process"}';
        file_put_contents($path, $created);

        try {
            $this->writer->write(
                $this->baseline()->withExpectedSourceAbsence(),
                $path,
                $this->projectRoot,
            );
            self::fail('Expected the write to be refused.');
        } catch (BaselineConflictException $e) {
            self::assertStringContainsString('appeared since it was read as absent', $e->getMessage());
        }

        self::assertSame($created, file_get_contents($path));
    }

    /**
     * The release half: a lock never released deadlocks the next writer.
     * On its own this proves nothing about exclusion — every assertion here
     * runs after `write()` returned and unlocked — which is what
     * {@see itRefusesToWriteWhileAnotherProcessHoldsTheLock()} is for.
     */
    #[Test]
    public function itHoldsAndReleasesASiblingLockFile(): void
    {
        $path = $this->write($this->baseline());

        self::assertFileExists($path . '.lock');

        $handle = fopen($path . '.lock', 'c');
        self::assertIsResource($handle);
        self::assertTrue(
            flock($handle, \LOCK_EX | \LOCK_NB),
            'The writer must not still be holding the lock after it returned.',
        );
        flock($handle, \LOCK_UN);
        fclose($handle);
    }

    /**
     * The exclusion half of the CAS guard, observed from outside: a
     * concurrent writer is held off rather than allowed to interleave with
     * the check-and-rename.
     *
     * The lock is held through a separate open file description, which
     * `flock` treats as a different holder even inside one process, so
     * removing the writer's own `flock` makes this write succeed and the
     * test fail.
     */
    #[Test]
    public function itRefusesToWriteWhileAnotherProcessHoldsTheLock(): void
    {
        $path = $this->tempDir . '/contended.json';

        $rival = fopen($path . '.lock', 'c');
        self::assertIsResource($rival);
        self::assertTrue(flock($rival, \LOCK_EX));

        try {
            $impatient = new BaselineWriter(lockTimeoutSeconds: 0.2);

            try {
                $impatient->write($this->baseline(), $path, $this->projectRoot);
                self::fail('The write must not proceed while another holder has the lock.');
            } catch (RuntimeException $e) {
                self::assertStringContainsString($path . '.lock', $e->getMessage());
            }

            self::assertFileDoesNotExist($path, 'A refused write must not have touched the target.');
        } finally {
            flock($rival, \LOCK_UN);
            fclose($rival);
        }
    }

    /**
     * A vanished target is a different fact from one someone else rewrote,
     * and the remedy differs: re-running picks up a changed file but fails
     * one step earlier — in the loader — for a file that is gone.
     */
    #[Test]
    public function itRefusesWhenTheFileVanishedSinceItWasRead(): void
    {
        $path = $this->write($this->baseline());
        $loaded = $this->loader->load($path);
        unlink($path);

        try {
            $this->writer->write($loaded, $path, $this->projectRoot);
            self::fail('Expected the write to be refused.');
        } catch (BaselineConflictException $e) {
            self::assertStringContainsString('no longer exists', $e->getMessage());
            self::assertStringNotContainsString('Re-run the command', $e->getMessage());
        }
    }

    /**
     * The token `write()` returns has to have a way home, or a caller that
     * writes one instance twice — every read-modify-write — is refused by
     * its own first write.
     */
    #[Test]
    public function itAcceptsASecondWriteOfABaselineCarryingTheTokenOfTheFirst(): void
    {
        $path = $this->write($this->baseline());
        $loaded = $this->loader->load($path);

        // Each step drops an entry, so each write really changes the file and
        // the previous reading genuinely goes out of date.
        $token = $this->writer->write($this->without($loaded, 2), $path, $this->projectRoot);
        $this->writer->write(
            $this->without($loaded, 1)->withSourceContentHash($token),
            $path,
            $this->projectRoot,
        );

        $current = (string) hash_file('sha256', $path);

        self::assertSame($current, $this->writer->write(
            $this->without($loaded, 1)->withSourceContentHash($current),
            $path,
            $this->projectRoot,
        ));
    }

    /**
     * The same baseline holding only its first `$keep` entries, carrying the
     * reading it came from.
     */
    private function without(Baseline $baseline, int $keep): Baseline
    {
        return new Baseline(
            generated: $baseline->generated,
            scope: $baseline->scope,
            entries: \array_slice($baseline->entries, 0, $keep),
            inertEntries: $baseline->inertEntries,
            sourceContentHash: $baseline->sourceContentHash,
        );
    }

    /**
     * Every entry read is an entry written. Both keys the writer used to
     * group by are shared by construction: all entries of a duplicated
     * identity carry one selector, and so do two byte-identical unreadable
     * lines.
     */
    #[Test]
    public function itWritesAsManyEntriesUnderASymbolAsItRead(): void
    {
        $undeclared = ['channel' => 'nobody.declares#this.channel', 'count' => 1];
        $duplicated = 'complexity.cyclomatic#complexity.cyclomatic.callable';

        $path = $this->tempDir . '/hand-written.json';
        file_put_contents($path, json_encode([
            'version' => 11,
            'generated' => '2026-08-05T12:00:00+03:00',
            'scope' => ['src'],
            'entries' => [
                'callable:App\Foo::bar' => [
                    ['channel' => $duplicated, 'magnitudes' => [3], 'count' => 1],
                    ['channel' => $duplicated, 'magnitudes' => [9], 'count' => 1],
                    $undeclared,
                    $undeclared,
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loaded = $this->loader->load($path);
        self::assertCount(4, $loaded->inertEntries, 'Both duplicates and both unreadable lines are inert.');

        $this->writer->write($loaded, $path, $this->projectRoot);

        /** @var array{entries: array<string, list<array<string, mixed>>>} $rewritten */
        $rewritten = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(4, $rewritten['entries']['callable:App\Foo::bar']);
        self::assertSame(
            [3, 9],
            array_values(array_map(
                static fn(array $entry): mixed => $entry['magnitudes'][0] ?? null,
                array_filter(
                    $rewritten['entries']['callable:App\Foo::bar'],
                    static fn(array $entry): bool => ($entry['channel'] ?? null) === $duplicated,
                ),
            )),
            'Neither ceiling of the duplicated identity may be dropped.',
        );
    }

    /**
     * Two identities that are distinct in memory become one symbol key once
     * relativized. Resolving that by overwriting would silently discard an
     * accepted ceiling, and which one survived would depend on assembly
     * order, so the write is refused instead.
     */
    #[Test]
    public function itRefusesToWriteTwoIdentitiesThatCollapseOnRelativization(): void
    {
        $channel = new ViolationChannel('code-smell.goto', 'code-smell.goto');

        $baseline = new Baseline(
            generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            scope: ['src'],
            entries: [
                new BaselineEntry(new BaselineIdentity('file:' . $this->tempDir . '/src/Foo.php', $channel), null, 7),
                new BaselineEntry(new BaselineIdentity('file:src/Foo.php', $channel), null, 3),
            ],
        );

        self::assertSame(2, $baseline->count(), 'The two are distinct identities in memory.');

        $this->expectException(InvalidArgumentException::class);
        $this->write($baseline, 'collapsing.json');
    }

    /**
     * The order of a symbol's entries must not depend on which command wrote
     * the file: `baseline:cleanup` declares far fewer channels than `check`,
     * so an entry that loads inert there would otherwise move on every
     * cleanup.
     */
    #[Test]
    public function itOrdersEntriesByChannelWhetherOrNotTheyAreApplicable(): void
    {
        $path = $this->tempDir . '/mixed.json';
        file_put_contents($path, json_encode([
            'version' => 11,
            'generated' => '2026-08-05T12:00:00+03:00',
            'scope' => ['src'],
            'entries' => [
                'class:App\Foo' => [
                    ['channel' => 'zzz.undeclared#zzz.undeclared', 'count' => 1],
                    ['channel' => 'aaa.undeclared#aaa.undeclared', 'count' => 1],
                    ['channel' => 'maintainability.index#maintainability.index.class', 'magnitudes' => [42], 'count' => 1],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $this->writer->write($this->loader->load($path), $path, $this->projectRoot);

        /** @var array{entries: array<string, list<array<string, mixed>>>} $rewritten */
        $rewritten = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(
            [
                'aaa.undeclared#aaa.undeclared',
                'maintainability.index#maintainability.index.class',
                'zzz.undeclared#zzz.undeclared',
            ],
            array_column($rewritten['entries']['class:App\Foo'], 'channel'),
        );
    }

    #[Test]
    public function itAcceptsAWriteWhenTheFileStillHoldsWhatWasRead(): void
    {
        $path = $this->write($this->baseline());
        $loaded = $this->loader->load($path);

        $hash = $this->writer->write($loaded, $path, $this->projectRoot);

        self::assertSame(hash_file('sha256', $path), $hash);
    }

    #[Test]
    public function itWritesAFreshBaselineOverAnyExistingFile(): void
    {
        $path = $this->tempDir . '/baseline.json';
        file_put_contents($path, 'whatever was here before');

        // No source hash: nothing was read, so there is nothing to conflict with.
        $this->writer->write($this->baseline(), $path, $this->projectRoot);

        self::assertStringContainsString('"version": 11', (string) file_get_contents($path));
    }

    /**
     * `cleanup` never removes an entry on its own — and neither may a
     * rewrite, least of all for a line it could not understand.
     */
    #[Test]
    public function itPreservesInertEntriesVerbatim(): void
    {
        $raw = ['channel' => 'nobody.declares#this.channel', 'count' => 4];

        $path = $this->write(new Baseline(
            generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            scope: ['src'],
            entries: [],
            inertEntries: [new InertBaselineEntry(
                subjectKey: 'callable:App\Foo::bar',
                channelKey: 'nobody.declares#this.channel',
                identity: null,
                selector: EntrySelector::forKey('x'),
                reason: InertEntryReason::UndeclaredChannel,
                detail: 'no rule declares it',
                raw: $raw,
            )],
        ));

        /** @var array{entries: array<string, list<mixed>>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame([$raw], $data['entries']['callable:App\Foo::bar']);
    }

    #[Test]
    public function itRelativizesAbsoluteFileKeysAgainstTheProjectRoot(): void
    {
        $path = $this->write(new Baseline(
            generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            scope: ['src'],
            entries: [new BaselineEntry(
                new BaselineIdentity(
                    'file:' . $this->tempDir . '/src/Foo.php',
                    new ViolationChannel('code-smell.goto', 'code-smell.goto'),
                ),
                null,
                1,
            )],
        ));

        /** @var array{entries: array<string, list<mixed>>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('file:src/Foo.php', $data['entries']);
    }

    /**
     * **A baseline is a tracked file, so nothing in it may name a developer's
     * home directory** (CLAUDE.md §10, enforced by
     * `scripts/check-private-leaks.sh`). The scope is the one field that used
     * to leak one: a run over the project root has no project-relative form,
     * so it was written verbatim. Here the run is `bin/qmx baseline:generate
     * baseline.json .` — the project root itself — and the bytes are checked
     * for the absolute form rather than the object, because the file is what
     * gets committed.
     */
    #[Test]
    public function itWritesNoAbsoluteMachinePathForARunOverTheProjectRoot(): void
    {
        $projectRoot = AbsolutePath::fromString('/Users/dev/projects/app');
        $path = $this->tempDir . '/baseline.json';

        $this->writer->write(
            new Baseline(
                generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
                scope: RunScope::record([$projectRoot], $projectRoot)->paths(),
                entries: [],
            ),
            $path,
            $projectRoot,
        );

        $content = (string) file_get_contents($path);

        self::assertStringNotContainsString('/Users', $content);
        self::assertStringNotContainsString('/home', $content);

        /** @var array{scope: list<string>} $data */
        $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(['.'], $data['scope']);
    }

    private function write(Baseline $baseline, string $name = 'baseline.json'): string
    {
        $path = $this->tempDir . '/' . $name;
        $this->writer->write($baseline, $path, $this->projectRoot);

        return $path;
    }

    /**
     * Three entries covering the shapes ADR 0017 names: a single magnitude, a
     * multi-element magnitude list, and an edge-bearing occurrence entry.
     */
    private function baseline(bool $reversed = false): Baseline
    {
        $entries = [
            new BaselineEntry(
                new BaselineIdentity(
                    'callable:App\Foo::bar',
                    new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable'),
                ),
                [25],
                1,
            ),
            new BaselineEntry(
                new BaselineIdentity(
                    'file:src/Legacy/dup.php',
                    new ViolationChannel('duplication.code-duplication', 'duplication.code-duplication'),
                ),
                [100, 40],
                2,
            ),
            new BaselineEntry(
                new BaselineIdentity(
                    'class:App\Web\Controller',
                    new ViolationChannel('architecture.layer-violation', 'architecture.layer-violation'),
                    null,
                    new BaselineEdge('class:App\Db\Connection', DependencyType::New_),
                ),
                null,
                1,
            ),
        ];

        return new Baseline(
            generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            scope: ['src'],
            entries: $reversed ? array_reverse($entries) : $entries,
        );
    }

    private function magnitudeBaseline(): Baseline
    {
        return new Baseline(
            generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            scope: ['src'],
            entries: [new BaselineEntry(
                new BaselineIdentity(
                    'callable:App\Foo::bar',
                    new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable'),
                ),
                [0.1, 1.2345678, 40.0, 1234.5678912],
                4,
            )],
        );
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_dir($file)) {
                    $this->recursiveDelete($file);
                } else {
                    unlink($file);
                }
            }
        }

        rmdir($dir);
    }
}
