<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Integration;

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
use Qualimetrix\Analysis\Policy\Baseline\BaselineFormatVersion;
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
     * The part of {@see BaselineEntryPayload}'s claim this fixture can prove:
     * every line lands where the product's own writer would put it, and a
     * line this build cannot read comes through byte for byte.
     *
     * The fixture is deliberately made of the lines where a second reading of
     * the parser's rules would have drifted — a channel that is the empty
     * string, one carrying `:`, one carrying the retired `#`, an edge with an
     * unknown dependency type, an occurrence carrying the identity key's
     * separator, and a line with no `channel` key at all. Each sorts by a
     * different branch, and an ordering that predicted the parser instead of
     * asking it would put at least one of them somewhere else.
     *
     * It proves *placement and byte-preservation*, and deliberately not more:
     * none of these channels is declared by the stub registry, so the loader
     * demotes every line to inert and the writer echoes an inert line from its
     * raw value — which makes the payload halves of this comparison equal by
     * construction. The line whose channel this build *does* declare is
     * {@see self::itPlacesADeclaredEntryWhereTheWriterWouldWithoutRerenderingIt()},
     * where the two sides can and do differ.
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

    /**
     * A line this build cannot read is counted, not skipped: the last entry
     * below names the renamed channel *and* carries a malformed occurrence,
     * and it is renamed like any other. Leaving it behind on a retired name
     * because one of its other fields is broken would strand exactly the line
     * a migration most needs to move.
     */
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
                ['channel' => 'mid.two', 'occurrence' => 17, 'count' => 2],
            ],
        ]);

        $report = $this->renamer->carry($path, $this->map("mid.two\tmid.renamed"));

        $carried = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(6, $carried['entries']['class:App\Foo']);
        self::assertSame(2, $report->renamedEntries);
        self::assertSame(
            [
                ChannelRenameReport::UNREADABLE_NO_CHANNEL => 1,
                ChannelRenameReport::UNREADABLE_MALFORMED_IDENTITY => 3,
            ],
            $report->unreadable,
        );
        self::assertStringNotContainsString('"channel":"mid.two"', (string) file_get_contents($path));
    }

    /**
     * The other half of {@see self::itLeavesTheFileTheProductWouldHaveWritten()},
     * on channels this build *does* declare — where the two sides can differ,
     * and where the split of ownership is therefore visible.
     *
     * The writer owns where a line goes; the file owns what a line says. So
     * the carried document places its lines exactly where a load-and-rewrite
     * places them, while each line keeps the field order and the magnitude
     * list the file was written with. The last assertion is the accepted
     * divergence stated outright rather than left to be discovered: the two
     * files are not byte-identical, and a later command that rewrites this
     * baseline re-renders those two lines in place without moving them.
     *
     * The fixture is deliberately out of canonical order, so the rename has to
     * move a line for the placements to agree: a carry that skipped sorting
     * fails the first assertion rather than passing it by accident.
     */
    #[Test]
    public function itPlacesADeclaredEntryWhereTheWriterWouldWithoutRerenderingIt(): void
    {
        $path = $this->rawFixture([
            'class:App\Foo' => [
                ['magnitudes' => [3.0, 1.0, 2.0], 'channel' => 'complexity.ccn'],
                ['count' => 2, 'channel' => 'code-smell.goto', 'occurrence' => 'occ1'],
            ],
        ]);

        $this->renamer->carry($path, $this->map("complexity.ccn\tmaintainability.index.class"));
        $carried = (string) file_get_contents($path);

        $loader = new BaselineLoader(new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults()));
        (new BaselineWriter())->write($loader->load($path), $path, AbsolutePath::fromString($this->tempDir));
        $rewritten = (string) file_get_contents($path);

        self::assertSame(
            self::placements($carried),
            self::placements($rewritten),
            'A carried line must sit where the writer would have put it.',
        );
        self::assertStringContainsString(
            '{"count":2,"channel":"code-smell.goto"',
            $carried,
            'A carried line keeps the field order the file spelled it in.',
        );
        self::assertStringContainsString('"magnitudes":[3,1,2]', $carried);
        self::assertNotSame($rewritten, $carried, 'The divergence this case exists to name is gone.');
    }

    /**
     * Subject key and channel per line, in file order — the placement both
     * producers must agree on, with the per-line rendering they need not.
     *
     * @return list<string>
     */
    private static function placements(string $document): array
    {
        /** @var array{entries: array<string, list<array<string, mixed>>>} $decoded */
        $decoded = json_decode($document, true, 512, \JSON_THROW_ON_ERROR);
        $places = [];

        foreach ($decoded['entries'] as $subjectKey => $lines) {
            foreach ($lines as $line) {
                $places[] = $subjectKey . ' ' . json_encode($line['channel'] ?? null, \JSON_THROW_ON_ERROR)
                    . ' ' . json_encode($line['occurrence'] ?? null, \JSON_THROW_ON_ERROR);
            }
        }

        return $places;
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
     * The same pre-existing duplicate, now standing on the channel being
     * renamed — the case counting cannot tell from a collision, because a
     * rename moves the identity key itself and the pair arrives at a key
     * nothing held before. Refusing here would block the whole file over a
     * duplicate the carry did not make, and would advise deleting one of two
     * lines on a false premise.
     */
    #[Test]
    public function itCarriesADuplicateThatStandsOnTheRenamedChannel(): void
    {
        $path = $this->rawFixture([
            'class:App\Foo' => [
                ['channel' => 'twin.channel', 'count' => 1],
                ['channel' => 'twin.channel', 'count' => 2],
            ],
        ]);

        $report = $this->renamer->carry($path, $this->map("twin.channel\ttwin.renamed"));

        self::assertTrue($report->written);
        self::assertSame(2, $report->renamedEntries);
        self::assertSame(
            [ChannelRenameReport::UNREADABLE_ALREADY_DUPLICATE => 2],
            $report->unreadable,
        );
        self::assertStringNotContainsString('"channel":"twin.channel"', (string) file_get_contents($path));
    }

    /**
     * The control for the case above: a fix that simply stopped refusing on a
     * duplicated key would pass it. Two lines already share an identity and a
     * third, distinct one is renamed onto theirs — three lines, two
     * pre-images, so this collision *is* the carry's making.
     */
    #[Test]
    public function itRefusesWhenADistinctEntryJoinsAnExistingDuplicate(): void
    {
        $path = $this->rawFixture([
            'class:App\Foo' => [
                ['channel' => 'twin.channel', 'count' => 1],
                ['channel' => 'twin.channel', 'count' => 2],
                ['channel' => 'mid.two', 'count' => 3],
            ],
        ]);
        $before = (string) file_get_contents($path);

        $this->expectRefusal($path, $before, "mid.two\ttwin.channel");
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
            self::assertStringContainsString('version ' . BaselineFormatVersion::CURRENT, $e->getMessage());
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

    /**
     * The envelope is otherwise complete, so this refuses on the missing
     * `entries` and not on a field that happened to be absent too.
     */
    #[Test]
    public function itRefusesADocumentWithoutAnEntriesObject(): void
    {
        $path = $this->tempDir . '/no-entries.json';
        $contents = (string) json_encode([
            'version' => BaselineFormatVersion::CURRENT,
            'generated' => '2026-01-01T00:00:00+00:00',
            'scope' => ['src'],
        ], \JSON_THROW_ON_ERROR);
        file_put_contents($path, $contents);

        $this->expectRefusal($path, $contents, "mid.two\tmid.renamed");
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideEnvelopeDefects(): iterable
    {
        yield 'no generated' => [['scope' => ['src']], 'Baseline "generated" must be a string'];
        yield 'unparseable generated' => [
            ['generated' => 'tomorrow', 'scope' => ['src']],
            'Baseline "generated" must be an ISO 8601 datetime',
        ];
        yield 'no scope' => [['generated' => '2026-01-01T00:00:00+00:00'], 'Baseline "scope" must be an array'];
        yield 'scope of non-strings' => [
            ['generated' => '2026-01-01T00:00:00+00:00', 'scope' => [17]],
            'Baseline "scope" must hold strings',
        ];
    }

    /**
     * An envelope the loader would refuse is refused here too, in the
     * loader's own words: a carry writes the envelope back as it found it, so
     * accepting one would produce a file this build's own `check` cannot
     * read.
     *
     * @param array<string, mixed> $envelope
     */
    #[Test]
    #[DataProvider('provideEnvelopeDefects')]
    public function itRefusesAnEnvelopeTheLoaderWouldRefuse(array $envelope, string $expected): void
    {
        $path = $this->tempDir . '/odd-envelope.json';
        $contents = (string) json_encode([
            'version' => BaselineFormatVersion::CURRENT,
            ...$envelope,
            'entries' => ['class:App\Foo' => [['channel' => 'mid.two', 'count' => 1]]],
        ], \JSON_THROW_ON_ERROR);
        file_put_contents($path, $contents);

        try {
            $this->renamer->carry($path, $this->map("mid.two\tmid.renamed"));
            self::fail('Expected the carry to be refused.');
        } catch (ChannelRenameRefusal $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }

        self::assertSame($contents, (string) file_get_contents($path));
    }

    /**
     * A subject block that is not a JSON array is not this command's opinion
     * to hold: the loader demotes exactly this block to one inert line and
     * the writer puts that line back as a one-element list, so the carry does
     * the same. Nothing inside it is renamed — the loader reads no channel
     * there either — and the resulting file is the one the product would
     * write, which for an inert line means byte-for-byte the same block.
     */
    #[Test]
    public function itCarriesASubjectBlockThatIsNotAnArray(): void
    {
        $path = $this->rawFixture([
            'class:App\Foo' => ['channel' => 'mid.two', 'count' => 1],
            'class:App\Bar' => [['channel' => 'mid.two', 'count' => 1]],
        ]);

        $report = $this->renamer->carry($path, $this->map("mid.two\tmid.renamed"));
        $carried = (string) file_get_contents($path);

        self::assertSame(1, $report->renamedEntries);
        self::assertSame(
            [ChannelRenameReport::UNREADABLE_BLOCK_NOT_AN_ARRAY => 1],
            $report->unreadable,
        );
        self::assertStringContainsString('"class:App\\\\Foo": [' . "\n", $carried);
        self::assertStringContainsString('{"channel":"mid.two","count":1}', $carried);

        $loader = new BaselineLoader(new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults()));
        (new BaselineWriter())->write($loader->load($path), $path, AbsolutePath::fromString($this->tempDir));

        self::assertSame($carried, (string) file_get_contents($path));
    }

    /**
     * A subject block with no lines is a shape the writer never produces —
     * {@see BaselineWriter::serializeEntries()} only opens a subject key
     * alongside at least one payload — so a carry that read one from a
     * hand-edited file drops it rather than rendering `"subject": []`
     * (or the malformed multi-line form the naive renderer used to print),
     * a form `load()` + `write()` would erase on its own next pass anyway.
     */
    #[Test]
    public function itDropsAnEmptySubjectBlock(): void
    {
        $path = $this->rawFixture([
            'class:App\Empty' => [],
            'class:App\Bar' => [['channel' => 'mid.two', 'count' => 1]],
        ]);

        $report = $this->renamer->carry($path, $this->map("mid.two\tmid.renamed"));
        $carried = (string) file_get_contents($path);

        self::assertSame(1, $report->renamedEntries);
        self::assertStringNotContainsString('App\\\\Empty', $carried);

        $loader = new BaselineLoader(new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults()));
        (new BaselineWriter())->write($loader->load($path), $path, AbsolutePath::fromString($this->tempDir));

        self::assertSame($carried, (string) file_get_contents($path));
    }

    /**
     * An envelope field the build does not know is carried through, and a
     * numeric name for one still comes back a quoted JSON key: `json_decode`
     * turns `"0"` into an `int` array key, and encoding it as it stands would
     * spell a document nothing can read back.
     */
    #[Test]
    public function itQuotesANumericEnvelopeFieldName(): void
    {
        $path = $this->tempDir . '/numeric-field.json';
        file_put_contents($path, (string) json_encode([
            'version' => BaselineFormatVersion::CURRENT,
            'generated' => '2026-01-01T00:00:00+00:00',
            'scope' => ['src'],
            '0' => 'a field from another build',
            'entries' => ['class:App\Foo' => [['channel' => 'mid.two', 'count' => 1]]],
        ], \JSON_THROW_ON_ERROR));

        $this->renamer->carry($path, $this->map("mid.two\tmid.renamed"));

        $carried = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('a field from another build', $carried[0]);
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
            'version' => BaselineFormatVersion::CURRENT,
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
