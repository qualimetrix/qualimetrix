<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use RuntimeException;

#[CoversClass(BaselineLoader::class)]
final class BaselineLoaderTest extends TestCase
{
    private BaselineLoader $loader;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->loader = new BaselineLoader(
            new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults()),
        );
        $this->tempDir = sys_get_temp_dir() . '/qmx_baseline_test_' . uniqid();
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
                "version": 11,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": ["src", "tests"],
                "entries": {
                    "callable:App\\OrderService::calculate": [
                        {
                            "channel": "complexity.cyclomatic#complexity.cyclomatic.callable",
                            "magnitudes": [25],
                            "count": 1
                        }
                    ],
                    "file:src/Legacy/bootstrap.php": [
                        { "channel": "code-smell.goto#code-smell.goto", "count": 3 }
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
            {"version": 11, "generated": "2026-08-05T12:00:00+03:00", "scope": [], "entries": {}}
            JSON;

        $baseline = $this->loadJson($json);

        self::assertSame(hash('sha256', $json), $baseline->sourceContentHash);
    }

    #[Test]
    public function itRejectsVersionTenBeforeReadingEntriesAndGivesManualV11Guidance(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Baseline version 10 cannot be converted automatically because declaration identity cannot be inferred '
            . 'from a logical symbol key. Run a fresh analysis, deliberately map or split accepted entries, then '
            . 'write a new version 11 baseline (or regenerate and review the accepted state).',
        );

        $this->loadJson('{"version": 10, "entries": "never parsed"}');
    }

    #[Test]
    public function itRejectsVersionFiveAsHistoricalAndRequiresManualV11Review(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'This baseline is version 5, a historical format that cannot be loaded or converted to version 11 '
            . 'because declaration identity cannot be inferred from a logical symbol key. Run a fresh analysis, '
            . 'deliberately map or split accepted entries, review every mapping, then write a new version 11 '
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
            {"version": 11, "generated": "2026-01-01T00:00:00+00:00", "entries": {}}
            JSON);
    }

    #[Test]
    public function itRejectsAnInvalidGeneratedTimestamp(): void
    {
        $this->expectException(RuntimeException::class);

        $this->loadJson(<<<'JSON'
            {"version": 11, "generated": "not-a-date", "scope": [], "entries": {}}
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
            '{"version": 11, "generated": %s, "scope": [], "entries": {}}',
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
            '{"version": 11, "generated": %s, "scope": [], "entries": {}}',
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
                "version": 11,
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
            'version' => 11,
            'generated' => '2026-08-05T12:00:00+03:00',
            'scope' => [],
            'entries' => [
                // The separator inside the symbol key itself.
                "class:App\u{1F}Foo" => [
                    ['channel' => 'code-smell.goto#code-smell.goto', 'count' => 1],
                ],
                'class:App\\Bar' => [
                    // Inside the channel.
                    ['channel' => "code-smell.goto#code-smell\u{1F}.goto", 'count' => 1],
                    // Inside the edge target.
                    [
                        'channel' => 'architecture.layer-violation#architecture.layer-violation',
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
                "version": 11,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": [],
                "entries": {
                    "class:App\\Web\\Controller": [
                        {
                            "channel": "architecture.layer-violation#architecture.layer-violation",
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
                "version": 11,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": ["src"],
                "entries": {
                    "callable:App\\Good::method": [
                        {
                            "channel": "complexity.cyclomatic#complexity.cyclomatic.callable",
                            "magnitudes": [25],
                            "count": 1
                        }
                    ],
                    "callable:App\\Bad::method": [
                        { "channel": "nobody.declares#this.channel", "count": 1 },
                        { "channel": "code-smell.goto#code-smell.goto", "count": 1, "mode": "whatever" }
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
                "version": 11,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": ["src"],
                "entries": { "callable:App\\Foo::bar": { "channel": "code-smell.goto#code-smell.goto" } }
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
                "version": 11,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": ["src"],
                "entries": {
                    "callable:App\\Foo::bar": [
                        { "channel": "code-smell.goto#code-smell.goto", "count": 1 },
                        { "channel": "code-smell.goto#code-smell.goto", "count": 5 }
                    ]
                }
            }
            JSON);

        self::assertSame(0, $baseline->count());
        self::assertCount(2, $baseline->inertEntries);
        self::assertSame(InertEntryReason::DuplicateIdentity, $baseline->inertEntries[0]->reason);
    }

    #[Test]
    public function itKeepsTwoEntriesUnderOneSymbolThatDifferOnlyByEdge(): void
    {
        $baseline = $this->loadJson(<<<'JSON'
            {
                "version": 11,
                "generated": "2026-08-05T12:00:00+03:00",
                "scope": ["src"],
                "entries": {
                    "class:App\\Web\\Controller": [
                        {
                            "channel": "architecture.layer-violation#architecture.layer-violation",
                            "edge": { "target": "class:App\\Db\\Connection", "type": "new" },
                            "count": 1
                        },
                        {
                            "channel": "architecture.layer-violation#architecture.layer-violation",
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

    private function loadJson(string $json): \Qualimetrix\Analysis\Policy\Baseline\Baseline
    {
        $path = $this->tempDir . '/baseline.json';
        file_put_contents($path, $json);

        return $this->loader->load($path);
    }
}
