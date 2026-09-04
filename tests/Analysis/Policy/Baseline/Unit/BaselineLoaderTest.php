<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\CanonicalBaselineReader;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use RuntimeException;

#[CoversClass(BaselineLoader::class)]
#[CoversClass(CanonicalBaselineReader::class)]
final class BaselineLoaderTest extends TestCase
{
    private BaselineLoader $loader;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->loader = new BaselineLoader(
            new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults()),
        );
        $this->tempDir = sys_get_temp_dir() . '/qmx_baseline_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function itLoadsTheContractOfTheCurrentVersion(): void
    {
        $baseline = $this->loadJson(<<<'JSON'
            {
                "version": 13,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": ["src", "tests"],
                "entries": {
                    "callable:App\\OrderService::calculate": [
                        {
                            "channel": "complexity.cyclomatic",
                            "magnitudes": [25]
                        }
                    ],
                    "file:src/Legacy/bootstrap.php": [
                        { "channel": "code-smell.goto", "count": 3 }
                    ]
                }
            }
            JSON);

        self::assertSame(2, $baseline->count());
        self::assertSame(['src', 'tests'], $baseline->scope);
        self::assertSame('2026-08-05T12:00:00+03:00', $baseline->generated->format('c'));
        self::assertSame([], $baseline->inertEntries);
    }

    #[Test]
    public function itRecordsTheContentHashOfTheFileItRead(): void
    {
        $json = <<<'JSON'
            {"version": 13, "generated": "2026-08-05T12:00:00+03:00", "scope": [], "entries": {}}
            JSON;

        $baseline = $this->loadJson($json);

        self::assertSame(hash('sha256', $json), $baseline->sourceContentHash);
    }

    #[Test]
    public function itRejectsVersionTenBeforeReadingEntriesAndGivesManualV13Guidance(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Baseline version 10 cannot be converted automatically because declaration identity cannot be inferred '
            . 'from a logical symbol key. Run a fresh analysis, deliberately map or split accepted entries, then '
            . 'write a new version 13 baseline (or regenerate and review the accepted state).',
        );

        $this->loadJson('{"version": 10, "entries": "never parsed"}');
    }

    /**
     * Version 11 is the immediate predecessor, not a historical format: it
     * has exact declaration subjects already. It is still refused rather than
     * converted, because P1 has no converter for the "count" and
     * occurrence-key changes either — the plan states this baseline
     * compatibility is not maintained.
     */
    #[Test]
    public function itRejectsVersionElevenAndRequiresARegeneratedV13Baseline(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Baseline version 11 cannot be converted automatically: version 13 drops the redundant "count" field '
            . 'and shortens the occurrence key, and there is no converter for either change. Run a fresh analysis '
            . 'and write a new version 13 baseline (or regenerate and review the accepted state).',
        );

        $this->loadJson('{"version": 11, "entries": "never parsed"}');
    }

    #[Test]
    public function itRejectsVersionFiveAsHistoricalAndRequiresManualV13Review(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'This baseline is version 5, a historical format that cannot be loaded or converted to version 13 '
            . 'because declaration identity cannot be inferred from a logical symbol key. Run a fresh analysis, '
            . 'deliberately map or split accepted entries, review every mapping, then write a new version 13 '
            . 'baseline (or regenerate and review the accepted state).',
        );

        $this->loadJson(<<<'JSON'
            {"version": 5, "generated": "2026-01-01T00:00:00+00:00", "violations": {}}
            JSON);
    }

    #[Test]
    public function itRejectsAnyOtherVersion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unsupported baseline version: 9/');

        $this->loadJson(<<<'JSON'
            {"version": 9, "generated": "2026-01-01T00:00:00+00:00", "scope": [], "entries": {}}
            JSON);
    }

    #[Test]
    public function itRejectsAMissingScope(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/scope/');

        $this->loadJson(<<<'JSON'
            {"version": 13, "generated": "2026-01-01T00:00:00+00:00", "entries": {}}
            JSON);
    }

    #[Test]
    public function itRejectsAnInvalidGeneratedTimestamp(): void
    {
        $this->expectException(RuntimeException::class);

        $this->loadJson(<<<'JSON'
            {"version": 13, "generated": "not-a-date", "scope": [], "entries": {}}
            JSON);
    }

    /**
     * ADR 0017 says ISO 8601, and `new DateTimeImmutable($s)` accepts far more than
     * that: a relative expression parses into a perfectly valid timestamp
     * that has nothing to do with when the file was written.
     *
     * @param string $generated a spelling ADR 0017 does not allow
     */
    #[Test]
    #[TestWith(['tomorrow'])]
    #[TestWith(['now'])]
    #[TestWith(['+1 week'])]
    #[TestWith(['2026-08-05 12:00:00+03:00'])]
    #[TestWith(['2026-08-05T12:00:00'])]
    #[TestWith(['2026-13-45T12:00:00+03:00'])]
    public function itRejectsAGeneratedTimestampThatIsNotIso8601(string $generated): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ISO 8601/');

        $this->loadJson(\sprintf(
            '{"version": 13, "generated": %s, "scope": [], "entries": {}}',
            json_encode($generated, \JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * @param string $generated a spelling ADR 0017 does allow
     * @param string $expectedUtc the instant it names, in UTC
     */
    #[Test]
    #[TestWith(['2026-08-05T12:00:00+03:00', '2026-08-05T09:00:00+00:00'])]
    #[TestWith(['2026-08-05T12:00:00Z', '2026-08-05T12:00:00+00:00'])]
    #[TestWith(['2026-08-05T12:00:00.123456+03:00', '2026-08-05T09:00:00+00:00'])]
    public function itAcceptsEveryIso8601SpellingTheContractNames(string $generated, string $expectedUtc): void
    {
        $baseline = $this->loadJson(\sprintf(
            '{"version": 13, "generated": %s, "scope": [], "entries": {}}',
            json_encode($generated, \JSON_THROW_ON_ERROR),
        ));

        self::assertSame(
            $expectedUtc,
            $baseline->generated->setTimezone(new DateTimeZone('UTC'))->format('c'),
        );
    }

    /**
     * ADR 0017 calls the scope normalised, so a hand-written one becomes normalised
     * on the way in rather than being carried into a comparison as spelled.
     */
    #[Test]
    public function itNormalizesAScopeTheFileSpelledLoosely(): void
    {
        $baseline = $this->loadJson(<<<'JSON'
            {
                "version": 13,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": ["tests/", "src", "src/", "src"],
                "entries": {}
            }
            JSON);

        self::assertSame(['src', 'tests'], $baseline->scope);
    }

    /**
     * The key separator cannot occur in a finding a rule emitted, but a
     * baseline file is arbitrary JSON and may spell any code point. A
     * component carrying it could shift the boundaries of the identity key,
     * so the entry is refused rather than trusted.
     */
    #[Test]
    public function itTurnsAnEntryWhoseComponentsCarryTheKeySeparatorInert(): void
    {
        $baseline = $this->loadJson((string) json_encode([
            'version' => 13,
            'generated' => '2026-08-05T12:00:00+03:00',
            'scope' => [],
            'entries' => [
                // The separator inside the symbol key itself.
                "class:App\u{1F}Foo" => [
                    ['channel' => 'code-smell.goto', 'count' => 1],
                ],
                'class:App\\Bar' => [
                    // Inside the channel.
                    ['channel' => "code-smell\u{1F}.goto", 'count' => 1],
                    // Inside the edge target.
                    [
                        'channel' => 'architecture.layer-violation',
                        'edge' => ['target' => "class:App\u{1F}Db"],
                        'count' => 1,
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(0, $baseline->count(), 'None of the three may become an applicable entry.');
        self::assertCount(3, $baseline->inertEntries);

        foreach ($baseline->inertEntries as $inert) {
            self::assertSame(InertEntryReason::Malformed, $inert->reason);
            self::assertStringContainsString('separator', $inert->detail);
        }
    }

    /**
     * The other end of the same invariant: a channel, symbol and edge from a
     * real finding are always accepted, so the check cannot cost a user a
     * suppression they legitimately recorded.
     */
    #[Test]
    public function itAcceptsTheComponentsARuleActuallyEmits(): void
    {
        $baseline = $this->loadJson(<<<'JSON'
            {
                "version": 13,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": [],
                "entries": {
                    "class:App\\Web\\Controller": [
                        {
                            "channel": "architecture.layer-violation",
                            "edge": { "target": "class:App\\Db\\Connection", "type": "new" },
                            "count": 1
                        }
                    ]
                }
            }
            JSON);

        self::assertSame(1, $baseline->count());
        self::assertSame([], $baseline->inertEntries);
    }

    #[Test]
    public function itRejectsInvalidJson(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid JSON/');

        $this->loadJson('{ not json');
    }

    #[Test]
    public function itRejectsAMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $this->loader->load($this->tempDir . '/absent.json');
    }

    /**
     * One bad line must not cost a user the other thousand.
     */
    #[Test]
    public function itKeepsLoadingWhenOneEntryIsUnusable(): void
    {
        $baseline = $this->loadJson(<<<'JSON'
            {
                "version": 13,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": ["src"],
                "entries": {
                    "callable:App\\Good::method": [
                        {
                            "channel": "complexity.cyclomatic",
                            "magnitudes": [25]
                        }
                    ],
                    "callable:App\\Bad::method": [
                        { "channel": "this.channel", "count": 1 },
                        { "channel": "code-smell.goto", "count": 1, "mode": "whatever" }
                    ]
                }
            }
            JSON);

        self::assertSame(1, $baseline->count());
        self::assertCount(2, $baseline->inertEntries);
        self::assertEqualsCanonicalizing(
            [InertEntryReason::UndeclaredChannel, InertEntryReason::UnrecognizedMode],
            array_map(static fn($entry) => $entry->reason, $baseline->inertEntries),
        );
    }

    #[Test]
    public function itTurnsAMalformedSymbolBucketInert(): void
    {
        $baseline = $this->loadJson(<<<'JSON'
            {
                "version": 13,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": ["src"],
                "entries": { "callable:App\\Foo::bar": { "channel": "code-smell.goto" } }
            }
            JSON);

        self::assertSame(0, $baseline->count());
        self::assertCount(1, $baseline->inertEntries);
        self::assertSame(InertEntryReason::Malformed, $baseline->inertEntries[0]->reason);
    }

    /**
     * With nothing in the file to say which of two entries for one identity
     * was meant, applying either would be a guess — and the guess suppresses.
     */
    #[Test]
    public function itDemotesEveryEntryOfARepeatedIdentityNotOnlyTheRepeats(): void
    {
        $baseline = $this->loadJson(<<<'JSON'
            {
                "version": 13,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": ["src"],
                "entries": {
                    "callable:App\\Foo::bar": [
                        { "channel": "code-smell.goto", "count": 1 },
                        { "channel": "code-smell.goto", "count": 5 }
                    ]
                }
            }
            JSON);

        self::assertSame(0, $baseline->count());
        self::assertCount(2, $baseline->inertEntries);
        self::assertSame(InertEntryReason::DuplicateIdentity, $baseline->inertEntries[0]->reason);
    }

    /**
     * A line the parser rejected still claims its identity, so the line beside
     * it stops applying — otherwise which of the two survives is decided by
     * which one happened to parse.
     */
    #[Test]
    public function itDemotesAnApplicableEntryWhoseIdentityAnInertLineAlsoClaims(): void
    {
        $baseline = $this->loadJson(<<<'JSON'
            {
                "version": 13,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": ["src"],
                "entries": {
                    "callable:App\\Foo::bar": [
                        { "channel": "code-smell.goto", "count": 1 },
                        { "channel": "code-smell.goto", "magnitudes": [5] }
                    ]
                }
            }
            JSON);

        self::assertSame(0, $baseline->count());
        self::assertCount(2, $baseline->inertEntries);
        self::assertSame(
            [InertEntryReason::ShapeMismatch, InertEntryReason::DuplicateIdentity],
            array_map(
                static fn($entry): InertEntryReason => $entry->reason,
                $baseline->inertEntries,
            ),
        );
    }

    #[Test]
    public function itKeepsTwoEntriesUnderOneSymbolThatDifferOnlyByEdge(): void
    {
        $baseline = $this->loadJson(<<<'JSON'
            {
                "version": 13,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": ["src"],
                "entries": {
                    "class:App\\Web\\Controller": [
                        {
                            "channel": "architecture.layer-violation",
                            "edge": { "target": "class:App\\Db\\Connection", "type": "new" },
                            "count": 1
                        },
                        {
                            "channel": "architecture.layer-violation",
                            "edge": { "target": "class:App\\Db\\Statement", "type": "new" },
                            "count": 1
                        }
                    ]
                }
            }
            JSON);

        self::assertSame(2, $baseline->count());
        self::assertSame([], $baseline->inertEntries);
    }

    /**
     * Pins which route each file takes. Without this, both fast-path
     * assertions would still pass with the fast path deleted, because the
     * fallback answers everything.
     */
    #[Test]
    public function itRecognisesTheCanonicalLayoutAndDeclinesAnyOther(): void
    {
        $reader = new CanonicalBaselineReader(
            new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults()),
        );

        $canonical = self::canonicalDocument();
        $reflowed = (string) json_encode(
            json_decode($canonical, true, 512, \JSON_THROW_ON_ERROR),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
        );

        self::assertNotNull($reader->read($this->put($canonical, 'canonical.json')));
        self::assertNull($reader->read($this->put($reflowed, 'reflowed.json')));
        self::assertNull($reader->read($this->tempDir . '/absent.json'));
    }

    private function put(string $json, string $name): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $json);

        return $path;
    }

    /**
     * The canonical layout and the same document reformatted are read by two
     * different routes; a user who reflows the file by hand must still get the
     * baseline the writer meant.
     */
    #[Test]
    public function itReadsACanonicalFileAndAReflowedCopyOfItIdentically(): void
    {
        $canonical = self::canonicalDocument();

        $fast = $this->loadJson($canonical, 'canonical.json');
        $reflowed = $this->loadJson(
            (string) json_encode(
                json_decode($canonical, true, 512, \JSON_THROW_ON_ERROR),
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
            ),
            'reflowed.json',
        );

        self::assertSame(
            self::describe($fast),
            self::describe($reflowed),
            'the reflowed copy took the fallback route and must still mean the same baseline',
        );
    }

    #[Test]
    public function itHashesTheFileTheSameWayOnEitherRoute(): void
    {
        $canonical = self::canonicalDocument();

        $loaded = $this->loadJson($canonical, 'canonical.json');

        self::assertSame(hash('sha256', $canonical), $loaded->sourceContentHash);
    }

    #[Test]
    public function itReadsACanonicalFileWithNoEntries(): void
    {
        $baseline = $this->loadJson(
            "{\n  \"version\": 13,\n  \"generated\": \"2026-08-05T12:00:00+03:00\",\n"
            . "  \"scope\": [\"src\"],\n  \"entries\": {}\n}\n",
            'empty.json',
        );

        self::assertSame([], $baseline->entries);
        self::assertSame([], $baseline->inertEntries);
        self::assertSame(['src'], $baseline->scope);
    }

    /**
     * A canonical envelope is still checked: recognising the layout must not
     * become a way past the version gate.
     */
    #[Test]
    public function itRejectsAnUnsupportedVersionWrittenInTheCanonicalLayout(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported baseline version: 4');

        $this->loadJson(
            "{\n  \"version\": 4,\n  \"generated\": \"2026-08-05T12:00:00+03:00\",\n"
            . "  \"scope\": [\"src\"],\n  \"entries\": {}\n}\n",
            'v4.json',
        );
    }

    /**
     * A comma between two subject blocks is mandatory, and `json_decode`
     * rejects the document without it. Reading it anyway would apply ceilings
     * out of a file the loader is supposed to refuse outright.
     */
    #[Test]
    public function itDeclinesASubjectBlockClosedWithoutACommaBeforeTheNext(): void
    {
        $broken = str_replace("    ],\n", "    ]\n", self::canonicalDocument());

        self::assertNull(json_decode($broken, true), 'the fixture must be invalid JSON, or it proves nothing');
        self::assertNull($this->reader()->read($this->put($broken, 'no-comma.json')));
    }

    /**
     * The mirror case, and the everyday one: deleting the last subject block
     * by hand leaves the block before it closed with a comma and nothing after
     * it to separate.
     */
    #[Test]
    public function itDeclinesATrailingCommaAfterTheLastSubjectBlock(): void
    {
        $broken = self::replaceLast("    ]\n", "    ],\n", self::canonicalDocument());

        self::assertNull(json_decode($broken, true), 'the fixture must be invalid JSON, or it proves nothing');
        self::assertNull($this->reader()->read($this->put($broken, 'extra-comma.json')));
    }

    /**
     * A subject block has to end on its own closing line. Accepting whatever
     * line happens to follow the entries would swallow a truncated block.
     */
    #[Test]
    public function itDeclinesASubjectBlockClosedByAnythingElse(): void
    {
        $broken = str_replace("    ],\n", "    } ,\n", self::canonicalDocument());

        self::assertNull($this->reader()->read($this->put($broken, 'bad-closer.json')));
    }

    /**
     * Bytes after the closing brace belong to a different document. Accepting
     * them would mean answering about a file only half of which was read.
     */
    #[Test]
    public function itDeclinesBytesAfterTheClosingBrace(): void
    {
        foreach (['trailing-line.json' => "{}\n", 'trailing-fragment.json' => 'junk'] as $name => $suffix) {
            $broken = self::canonicalDocument() . $suffix;

            self::assertNull(json_decode($broken, true), 'the fixture must be invalid JSON, or it proves nothing');
            self::assertNull($this->reader()->read($this->put($broken, $name)), $name);
        }
    }

    /**
     * The divergence the guard exists for: `json_decode` keeps the last of two
     * identical keys and yields one entry, while a streaming reader that kept
     * both would yield two — and the second one suppresses.
     */
    #[Test]
    public function itDeclinesARepeatedSubjectKeyTheWholeDocumentPathWouldCollapse(): void
    {
        $entry = '{"channel":"complexity.cyclomatic","magnitudes":[%d]}';

        $repeated = "{\n"
            . "  \"version\": 13,\n"
            . "  \"generated\": \"2026-08-05T12:00:00+03:00\",\n"
            . "  \"scope\": [\"src\"],\n"
            . "  \"entries\": {\n"
            . "    \"callable:App\\\\OrderService::calculate\": [\n"
            . '      ' . \sprintf($entry, 70) . "\n"
            . "    ],\n"
            . "    \"callable:App\\\\OrderService::calculate\": [\n"
            . '      ' . \sprintf($entry, 90) . "\n"
            . "    ]\n"
            . "  }\n"
            . "}\n";

        $path = $this->put($repeated, 'repeated-subject.json');

        self::assertNull($this->reader()->read($path));

        $loaded = $this->loader->load($path);

        self::assertCount(1, $loaded->entries, 'the whole-document path keeps one of the two');
        self::assertSame(
            [90.0],
            $loaded->entries[0]->toArray()['magnitudes'] ?? null,
            'and it keeps the last',
        );
    }

    /**
     * Unlike a repeated subject key, a repeated envelope field would not make
     * the two paths disagree — both would keep the last. The guard is here so
     * the reader never has to be the one picking a winner, and it is pinned so
     * that intent cannot be dropped silently.
     */
    #[Test]
    public function itDeclinesARepeatedEnvelopeField(): void
    {
        $repeated = str_replace(
            "  \"scope\": [\"src\",\"tests\"],\n",
            "  \"scope\": [\"src\"],\n  \"scope\": [\"src\",\"tests\"],\n",
            self::canonicalDocument(),
        );

        $path = $this->put($repeated, 'repeated-envelope.json');

        self::assertNull($this->reader()->read($path));
        self::assertSame(['src', 'tests'], $this->loader->load($path)->scope);
    }

    /**
     * An entry decoded on its own starts counting nesting from zero, while the
     * same entry inside the document is already three containers deep. Equal
     * budgets there would mean one path reading what the other refuses.
     */
    #[Test]
    public function itSpendsTheSameNestingBudgetOnAnEntryAsTheWholeDocumentPath(): void
    {
        foreach ([509 => 'accepted', 510 => 'declined'] as $nesting => $expected) {
            $document = self::deepEntryDocument($nesting);
            $path = $this->put($document, 'deep-' . $nesting . '.json');

            $wholeDocument = json_decode($document, true) !== null;
            $fastPath = $this->reader()->read($path) !== null;

            self::assertSame($expected === 'accepted', $wholeDocument, "whole document, nesting {$nesting}");
            self::assertSame($wholeDocument, $fastPath, "the two paths must agree at nesting {$nesting}");
        }
    }

    /**
     * Every remaining shape the recogniser must decline. Each case breaks one
     * distinct expectation, so a guard that stops holding takes its case down
     * with it.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideUnrecognisedShapes(): iterable
    {
        $canonical = self::canonicalDocument();

        yield 'document not opened on its own line' => ['{"version": 13}'];
        yield 'document opened with a bracket' => ["[\n" . substr($canonical, 2)];
        yield 'envelope field indented four spaces' => [str_replace("  \"version\"", "    \"version\"", $canonical)];
        yield 'envelope field without its comma' => [str_replace("  \"version\": 13,\n", "  \"version\": 12\n", $canonical)];
        yield 'envelope value that is not JSON' => [str_replace('"scope": ["src","tests"]', '"scope": [src]', $canonical)];
        yield 'subject key indented two spaces' => [str_replace("    \"class:", "  \"class:", $canonical)];
        yield 'subject key that is not a JSON string' => [str_replace("    \"class:App\\\\Legacy\\\\Report\":", '    class:App\Legacy\Report:', $canonical)];
        yield 'entry indented four spaces' => [str_replace("      {\"channel\":\"complexity.wmc", "    {\"channel\":\"complexity.wmc", $canonical)];
        // Tabs are legal JSON whitespace, so this one stays a valid document
        // and is declined purely for not being the layout.
        yield 'entry indented with tabs' => [str_replace("      {\"channel\":\"complexity.wmc", "\t\t\t\t\t\t{\"channel\":\"complexity.wmc", $canonical)];
        yield 'entry line that is not JSON' => [str_replace('{"channel":"complexity.wmc","magnitudes":[70]}', '{"channel": unquoted}', $canonical)];
        yield 'entries object never closed' => [str_replace("  }\n}\n", "}\n", $canonical)];
        yield 'last line without its newline' => [rtrim($canonical, "\n")];
    }

    #[Test]
    #[DataProvider('provideUnrecognisedShapes')]
    public function itDeclinesEveryShapeItDoesNotRecognise(string $document): void
    {
        self::assertNull($this->reader()->read($this->put($document, 'shape.json')));
    }

    private function reader(): CanonicalBaselineReader
    {
        return new CanonicalBaselineReader(
            new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults()),
        );
    }

    /**
     * An entry whose own nesting is exactly $nesting containers deep, alone in
     * an otherwise minimal canonical document.
     */
    private static function deepEntryDocument(int $nesting): string
    {
        $magnitudes = '1';
        for ($level = 0; $level < $nesting - 2; $level++) {
            $magnitudes = '[' . $magnitudes . ']';
        }

        return "{\n"
            . "  \"version\": 13,\n"
            . "  \"generated\": \"2026-08-05T12:00:00+03:00\",\n"
            . "  \"scope\": [\"src\"],\n"
            . "  \"entries\": {\n"
            . "    \"class:App\\\\Deep\": [\n"
            . "      {\"channel\":\"complexity.wmc\",\"magnitudes\":" . $magnitudes . "}\n"
            . "    ]\n"
            . "  }\n"
            . "}\n";
    }

    private static function replaceLast(string $search, string $replace, string $subject): string
    {
        $at = strrpos($subject, $search);

        return $at === false ? $subject : substr_replace($subject, $replace, $at, \strlen($search));
    }

    /**
     * Two subjects, several entries under one of them, and a line the parser
     * cannot make an entry of — the shapes a subject block can take.
     */
    private static function canonicalDocument(): string
    {
        return "{\n"
            . "  \"version\": 13,\n"
            . "  \"generated\": \"2026-08-05T12:00:00+03:00\",\n"
            . "  \"scope\": [\"src\",\"tests\"],\n"
            . "  \"entries\": {\n"
            . "    \"callable:App\\\\OrderService::calculate\": [\n"
            . "      {\"channel\":\"complexity.cognitive\",\"magnitudes\":[18]},\n"
            . "      {\"channel\":\"complexity.cyclomatic\",\"magnitudes\":[25]},\n"
            . "      {\"channel\":\"nonsense.not-a-channel\",\"count\":1}\n"
            . "    ],\n"
            . "    \"class:App\\\\Legacy\\\\Report\": [\n"
            . "      {\"channel\":\"complexity.wmc\",\"magnitudes\":[70]}\n"
            . "    ]\n"
            . "  }\n"
            . "}\n";
    }

    /**
     * Observable state rather than counts: two paths agreeing on how many
     * entries there are says nothing about them being the same entries.
     *
     * @return list<string>
     */
    private static function describe(\Qualimetrix\Analysis\Policy\Baseline\Baseline $baseline): array
    {
        $described = [
            'generated=' . $baseline->generated->format('c'),
            'scope=' . implode(',', $baseline->scope),
        ];

        foreach ($baseline->entries as $entry) {
            $described[] = 'entry ' . $entry->identity->key()
                . ' ' . (string) json_encode($entry->toArray(), \JSON_THROW_ON_ERROR);
        }

        foreach ($baseline->inertEntries as $entry) {
            $described[] = 'inert ' . $entry->subjectKey . ' ' . $entry->reason->name
                . ' ' . $entry->detail
                . ' ' . (string) json_encode($entry->raw, \JSON_THROW_ON_ERROR);
        }

        return $described;
    }

    private function loadJson(
        string $json,
        string $name = 'baseline.json',
    ): \Qualimetrix\Analysis\Policy\Baseline\Baseline {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $json);

        return $this->loader->load($path);
    }
}
