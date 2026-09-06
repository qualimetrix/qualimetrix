<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineChannelRenamer;
use Qualimetrix\Analysis\Policy\Baseline\BaselineConflictException;
use Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentWriter;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEdge;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameMap;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameRefusal;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameReport;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;
use RuntimeException;

#[CoversClass(BaselineChannelRenamer::class)]
final class BaselineChannelRenamerTest extends TestCase
{
    private string $tempDir;

    private BaselineChannelRenamer $renamer;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-channel-carry-');
        $this->renamer = new BaselineChannelRenamer(new BaselineDocumentWriter());
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->tempDir);
    }

    /**
     * The load-bearing witness. A non-empty carry, compared character by
     * character: the only bytes that may differ between the file before and
     * the file after are the channel values of the entries the map named.
     *
     * The map is chosen so the new name sorts where the old one did, which is
     * what lets the whole document be compared as text; the reordering a
     * different name causes has its own case below. An empty map proves far
     * less on its own — an implementation that simply skipped the write would
     * pass it — so it is the supporting check, not this one.
     */
    #[Test]
    public function itChangesNothingButTheChannelValuesItWasToldTo(): void
    {
        $path = $this->fixture();
        $before = (string) file_get_contents($path);

        $report = $this->renamer->carry($path, $this->map("mid.two\tmid.renamed"));

        $after = (string) file_get_contents($path);

        self::assertNotSame($before, $after, 'A carry that wrote nothing proves nothing.');
        self::assertSame(
            $before,
            str_replace('"channel":"mid.renamed"', '"channel":"mid.two"', $after),
            'Bytes outside the carried channel values moved.',
        );
        self::assertSame(2, $report->renamedEntries);
        self::assertSame(5, $report->totalEntries);
        self::assertTrue($report->written);
        self::assertSame([], $report->idleRows());
    }

    #[Test]
    public function itLeavesTheFileByteIdenticalUnderAnEmptyMap(): void
    {
        $path = $this->fixture();
        $before = (string) file_get_contents($path);

        $report = $this->renamer->carry($path, $this->map());

        self::assertSame($before, (string) file_get_contents($path));
        self::assertFalse($report->written);
        self::assertSame(0, $report->renamedEntries);
    }

    /**
     * A declared row that matched nothing is reported, not refused: a map is
     * written for a vocabulary and applied to one file, and the two need not
     * agree.
     */
    #[Test]
    public function itReportsADeclaredRenameThatMatchedNothing(): void
    {
        $path = $this->fixture();
        $before = (string) file_get_contents($path);

        $report = $this->renamer->carry($path, $this->map("nothing.here\tsomething.else"));

        self::assertSame(['nothing.here'], $report->idleRows());
        self::assertFalse($report->written);
        self::assertSame($before, (string) file_get_contents($path));
    }

    /**
     * A rename that moves an entry past its siblings leaves the file in the
     * canonical order the product writes, rather than in the order the raw
     * document happened to have.
     */
    #[Test]
    public function itRestoresTheCanonicalOrderAfterARenameThatMovesAnEntry(): void
    {
        $path = $this->fixture();

        $this->renamer->carry($path, $this->map("zeta.three\tbeta.three"));

        $expected = $this->writeBaseline(
            $this->entries(['alpha.one', 'mid.two', 'beta.three']),
            'expected.json',
        );

        self::assertSame(
            (string) file_get_contents($expected),
            (string) file_get_contents($path),
            'The carried file must be the file the product itself would have written.',
        );
    }

    /**
     * The claim {@see BaselineEntryPayload} is built on, checked rather than
     * argued: a carried file is the file the product itself would write.
     *
     * The fixture is deliberately made of the lines where a second reading of
     * the parser's rules would have drifted — a channel that is the empty
     * string, one carrying `:`, one carrying the retired `#`, an edge with an
     * unknown dependency type, an occurrence carrying the identity key's
     * separator, and a line with no `channel` key at all. Each sorts by a
     * different branch, and an ordering that predicted the parser instead of
     * asking it would put at least one of them somewhere else.
     */
    #[Test]
    public function itLeavesTheFileTheProductWouldHaveWritten(): void
    {
        $path = $this->rawFixture([
            'class:App\Foo' => [
                ['channel' => 'z.valid', 'occurrence' => 'occ', 'edge' => ['target' => 'class:App\Baz', 'type' => 'extends'], 'count' => 2],
                ['channel' => 'a.rename', 'count' => 1],
                ['count' => 1],
                ['channel' => '', 'count' => 1],
                ['channel' => 'bad:name', 'occurrence' => 'occ', 'count' => 1],
                ['channel' => 'has#pair', 'occurrence' => 'occ', 'count' => 1],
                ['channel' => 'm.two', 'occurrence' => 'zzz', 'edge' => ['target' => 'class:App\Baz', 'type' => 'no-such-type'], 'count' => 1],
                ['channel' => 'm.two', 'occurrence' => 'mmm', 'count' => 1],
                ['channel' => 'n.two', 'occurrence' => "\x1Fseparator", 'count' => 1],
            ],
        ]);

        $this->renamer->carry($path, $this->map("a.rename\tb.renamed"));
        $carried = (string) file_get_contents($path);

        $loader = new BaselineLoader(new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults()));
        (new BaselineWriter())->write($loader->load($path), $path, AbsolutePath::fromString($this->tempDir));

        self::assertSame($carried, (string) file_get_contents($path));
        self::assertStringContainsString('"channel":"b.renamed"', $carried);
    }

    #[Test]
    public function itCarriesALineThisBuildCannotReadInsteadOfDroppingIt(): void
    {
        $path = $this->rawFixture([
            'class:App\Foo' => [
                ['channel' => 'mid.two', 'count' => 1],
                ['channel' => 'gone.channel', 'count' => 'not a number'],
                ['no channel at all' => true],
                ['channel' => 'odd.occurrence', 'occurrence' => 17, 'count' => 1],
                ['channel' => 'odd.edge', 'edge' => ['target' => 'class:App\Baz', 'type' => 'no-such-type'], 'count' => 1],
            ],
        ]);

        $report = $this->renamer->carry($path, $this->map("mid.two\tmid.renamed"));

        $carried = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(5, $carried['entries']['class:App\Foo']);
        self::assertSame(1, $report->renamedEntries);
        self::assertSame(
            [
                ChannelRenameReport::UNREADABLE_NO_CHANNEL => 1,
                ChannelRenameReport::UNREADABLE_MALFORMED_IDENTITY => 2,
            ],
            $report->unreadable,
        );
    }

    /**
     * The refusal the raw path exists to still have: the writer's own guard
     * against two entries of one identity is bypassed, so the carry has to
     * check the result before it writes it.
     */
    #[Test]
    public function itRefusesACarryThatWouldGiveTwoEntriesOneIdentity(): void
    {
        $path = $this->fixture();
        $before = (string) file_get_contents($path);

        $this->expectRefusal($path, $before, "mid.two\talpha.one");
    }

    /**
     * A file that already holds a duplicate is not this command's to repair,
     * and refusing on it would let one such pair block the rename of
     * everything else in the file.
     */
    #[Test]
    public function itCarriesAFileThatAlreadyHeldADuplicateIdentity(): void
    {
        $path = $this->rawFixture([
            'class:App\Foo' => [
                ['channel' => 'twin.channel', 'count' => 1],
                ['channel' => 'twin.channel', 'count' => 2],
                ['channel' => 'mid.two', 'count' => 3],
            ],
        ]);

        $report = $this->renamer->carry($path, $this->map("mid.two\tmid.renamed"));

        self::assertTrue($report->written);
        self::assertSame(2, array_sum($report->unreadable));
        self::assertStringContainsString('"channel":"twin.channel"', (string) file_get_contents($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMapDefects(): iterable
    {
        yield 'two rows for one old name' => ["a.b\tc.d\twhy\na.b\te.f\twhy"];
        yield 'two rows onto one new name' => ["a.b\tc.d\twhy\ne.f\tc.d\twhy"];
        yield 'a chain' => ["a.b\tc.d\twhy\nc.d\te.f\twhy"];
        yield 'both sides equal' => ["a.b\ta.b\twhy"];
        yield 'a wrong header' => ['HEADER'];
    }

    /**
     * Each map defect is its own case, and each leaves the baseline
     * byte-identical.
     *
     * The file is untouched for a reason worth stating rather than inferring:
     * the map is read before `carry()` is entered at all, so a defective map
     * refuses one step before the baseline is even opened.
     */
    #[Test]
    #[DataProvider('provideMapDefects')]
    public function itRefusesADefectiveMapWithoutTouchingTheBaseline(string $rows): void
    {
        $path = $this->fixture();
        $before = (string) file_get_contents($path);
        $contents = $rows === 'HEADER' ? "from\tto\treason\n" : "old\tnew\treason\n" . $rows . "\n";

        try {
            $this->renamer->carry($path, ChannelRenameMap::fromString($contents, 'defective.tsv'));
            self::fail('Expected the map to be refused.');
        } catch (ChannelRenameRefusal $e) {
            self::assertNotSame('', $e->getMessage());
        }

        self::assertSame($before, (string) file_get_contents($path));
    }

    #[Test]
    public function itRefusesAFileOfAnotherVersionAndNamesBoth(): void
    {
        $path = $this->tempDir . '/legacy.json';
        $contents = (string) json_encode(['version' => 5, 'entries' => []], \JSON_THROW_ON_ERROR);
        file_put_contents($path, $contents);

        try {
            $this->renamer->carry($path, $this->map("mid.two\tmid.renamed"));
            self::fail('Expected the carry to be refused.');
        } catch (ChannelRenameRefusal $e) {
            self::assertStringContainsString('version 5', $e->getMessage());
            self::assertStringContainsString('version ' . Baseline::VERSION, $e->getMessage());
        }

        self::assertSame($contents, (string) file_get_contents($path));
    }

    #[Test]
    public function itRefusesAFileThatIsNotJson(): void
    {
        $path = $this->tempDir . '/broken.json';
        file_put_contents($path, '{not json');

        $this->expectRefusal($path, '{not json', "mid.two\tmid.renamed");
    }

    #[Test]
    public function itRefusesADocumentWhoseRootIsNotAnObject(): void
    {
        $path = $this->tempDir . '/list.json';
        file_put_contents($path, '[1, 2, 3]');

        $this->expectRefusal($path, '[1, 2, 3]', "mid.two\tmid.renamed");
    }

    #[Test]
    public function itRefusesADocumentWithoutAnEntriesObject(): void
    {
        $path = $this->tempDir . '/no-entries.json';
        $contents = (string) json_encode(['version' => Baseline::VERSION], \JSON_THROW_ON_ERROR);
        file_put_contents($path, $contents);

        $this->expectRefusal($path, $contents, "mid.two\tmid.renamed");
    }

    /**
     * A subject block that is not a JSON array has no entry lines to carry
     * one by one, and the two alternatives — rendering it as the writer never
     * would, or reshaping it — both decide something about a line the user
     * wrote. The refusal leaves the file alone.
     */
    #[Test]
    public function itRefusesASubjectBlockThatIsNotAnArray(): void
    {
        $path = $this->tempDir . '/odd-block.json';
        $contents = (string) json_encode([
            'version' => Baseline::VERSION,
            'entries' => ['class:App\Foo' => ['channel' => 'mid.two']],
        ], \JSON_THROW_ON_ERROR);
        file_put_contents($path, $contents);

        $this->expectRefusal($path, $contents, "mid.two\tmid.renamed");
    }

    /**
     * The compare-and-swap, through the carry rather than through the writer
     * it borrows the guard from: a second writer landing between this one's
     * read and its write is not silently discarded.
     *
     * The interleaving is real and not simulated. A child process reads the
     * file and then blocks on the sibling lock this test holds; the test
     * rewrites the file and releases the lock; the child resumes, finds the
     * bytes it read are gone and refuses. If the synchronisation ever failed
     * the child would read the *new* content and succeed, so a race shows up
     * as a failure rather than as a pass.
     */
    #[Test]
    public function itRefusesToWriteOverAFileThatChangedSinceItWasRead(): void
    {
        $path = $this->fixture();
        $mapPath = $this->tempDir . '/map.tsv';
        file_put_contents($mapPath, "old\tnew\treason\nmid.two\tmid.renamed\twhy\n");
        $marker = $this->tempDir . '/child-started';
        $this->writeChildScript();

        $rival = fopen($path . '.lock', 'c');
        self::assertIsResource($rival);
        self::assertTrue(flock($rival, \LOCK_EX));

        $child = proc_open(
            [\PHP_BINARY, $this->tempDir . '/carry.php', $path, $mapPath, $marker],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($child);

        try {
            $deadline = microtime(true) + 10.0;
            while (!file_exists($marker) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFileExists($marker, 'The child never reached the carry.');

            // The child is now past its read and blocked on the lock this test
            // holds; nothing it does next can observe the rewrite below as its
            // own reading.
            usleep(1_000_000);
            file_put_contents($path, (string) file_get_contents($path) . "\n");
            $rewritten = (string) file_get_contents($path);
        } finally {
            flock($rival, \LOCK_UN);
            fclose($rival);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(1, proc_close($child), 'The child must have refused: ' . $stdout);
        self::assertStringContainsString('changed since it was read', $stdout);
        self::assertSame($rewritten, (string) file_get_contents($path));
    }

    /**
     * The lock itself, observed from outside: the guard is held across the
     * check and the rename, so a rival holder stops the carry rather than
     * letting it interleave.
     */
    #[Test]
    public function itWaitsForTheLockAndGivesUpNamingIt(): void
    {
        $path = $this->fixture();
        $before = (string) file_get_contents($path);

        $rival = fopen($path . '.lock', 'c');
        self::assertIsResource($rival);
        self::assertTrue(flock($rival, \LOCK_EX));

        try {
            $impatient = new BaselineChannelRenamer(new BaselineDocumentWriter(0.2));

            try {
                $impatient->carry($path, $this->map("mid.two\tmid.renamed"));
                self::fail('The carry must not proceed while another holder has the lock.');
            } catch (RuntimeException $e) {
                self::assertStringContainsString($path . '.lock', $e->getMessage());
            }

            self::assertSame($before, (string) file_get_contents($path));
        } finally {
            flock($rival, \LOCK_UN);
            fclose($rival);
        }
    }

    /**
     * The other half of the same guard: the carry replaces the file whose
     * bytes it read, not whatever a name currently points at.
     */
    #[Test]
    public function itRefusesToReplaceASymbolicLink(): void
    {
        $referent = $this->fixture('referent.json');
        $contents = (string) file_get_contents($referent);
        $path = $this->tempDir . '/link.json';
        symlink($referent, $path);

        try {
            $this->renamer->carry($path, $this->map("mid.two\tmid.renamed"));
            self::fail('Expected the carry to be refused.');
        } catch (BaselineConflictException $e) {
            self::assertStringContainsString('symbolic link', $e->getMessage());
        }

        self::assertTrue(is_link($path));
        self::assertSame($contents, (string) file_get_contents($referent));
    }

    private function map(string ...$rows): ChannelRenameMap
    {
        $lines = "old\tnew\treason\n";

        foreach ($rows as $row) {
            $lines .= $row . "\twhy\n";
        }

        return ChannelRenameMap::fromString($lines, 'test-map.tsv');
    }

    private function expectRefusal(string $path, string $before, string $row): void
    {
        try {
            $this->renamer->carry($path, $this->map($row));
            self::fail('Expected the carry to be refused.');
        } catch (ChannelRenameRefusal $e) {
            self::assertNotSame('', $e->getMessage());
        }

        self::assertSame($before, (string) file_get_contents($path), 'A refused carry must not touch the file.');
    }

    /**
     * Five entries over two subjects, written by the product itself so the
     * comparison is against a canonical file rather than against a hand-typed
     * one. Two of them are on `mid.two`, which is the channel the carrying
     * cases name.
     */
    private function fixture(string $name = 'baseline.json'): string
    {
        return $this->writeBaseline($this->entries(['alpha.one', 'mid.two', 'zeta.three']), $name);
    }

    /**
     * @param list<string> $channels
     *
     * @return list<BaselineEntry>
     */
    private function entries(array $channels): array
    {
        [$first, $second, $third] = $channels;

        return [
            new BaselineEntry(new BaselineIdentity('class:App\Foo', new FindingChannel($first)), null, 1),
            new BaselineEntry(new BaselineIdentity('class:App\Foo', new FindingChannel($second)), [12.5], 1),
            new BaselineEntry(new BaselineIdentity('class:App\Foo', new FindingChannel($third)), null, 2),
            new BaselineEntry(
                new BaselineIdentity(
                    'class:App\Bar',
                    new FindingChannel($second),
                    'occurrence-key',
                    new BaselineEdge('class:App\Baz', DependencyType::Extends),
                ),
                null,
                1,
            ),
            new BaselineEntry(new BaselineIdentity('class:App\Bar', new FindingChannel($third)), [3.0, 4.0], 2),
        ];
    }

    /**
     * @param list<BaselineEntry> $entries
     */
    private function writeBaseline(array $entries, string $name): string
    {
        $path = $this->tempDir . '/' . $name;

        (new BaselineWriter())->write(
            new Baseline(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), ['src'], $entries),
            $path,
            AbsolutePath::fromString($this->tempDir),
        );

        return $path;
    }

    /**
     * @param array<string, mixed> $entries
     */
    private function rawFixture(array $entries): string
    {
        $path = $this->tempDir . '/raw.json';
        file_put_contents($path, (string) json_encode([
            'version' => Baseline::VERSION,
            'generated' => '2026-01-01T00:00:00+00:00',
            'scope' => ['src'],
            'entries' => $entries,
        ], \JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * The child of the compare-and-swap case: it reads, blocks, and reports
     * what it was told.
     */
    private function writeChildScript(): void
    {
        $autoload = \dirname(__DIR__, 5) . '/vendor/autoload.php';

        file_put_contents($this->tempDir . '/carry.php', <<<PHP
            <?php
            require '{$autoload}';
            [\$script, \$baseline, \$map, \$marker] = \$argv;
            touch(\$marker);
            try {
                (new Qualimetrix\Analysis\Policy\Baseline\BaselineChannelRenamer(
                    new Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentWriter(),
                ))->carry(\$baseline, Qualimetrix\Analysis\Policy\Baseline\ChannelRenameMap::fromFile(\$map));
            } catch (Throwable \$e) {
                echo \$e->getMessage();
                exit(1);
            }
            exit(0);
            PHP);
    }
}
