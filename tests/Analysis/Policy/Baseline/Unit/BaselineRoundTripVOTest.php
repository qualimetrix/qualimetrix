<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

/**
 * ADR 0015 Phase 4 regression pin: BaselineWriter relies on
 * PathFactory::tryProjectRelative() for canonical key relativization. This
 * test asserts the writer→loader contract still round-trips identically:
 * the same canonical keys are restored, and out-of-tree absolute file: keys
 * are preserved verbatim instead of being silently dropped.
 */
#[CoversClass(BaselineWriter::class)]
#[CoversClass(BaselineLoader::class)]
final class BaselineRoundTripVOTest extends TestCase
{
    private BaselineWriter $writer;
    private BaselineLoader $loader;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->writer = new BaselineWriter();
        $this->loader = new BaselineLoader(
            new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults()),
        );
        $this->tempDir = sys_get_temp_dir() . '/qmx_baseline_vo_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach ((array) glob($this->tempDir . '/*') as $file) {
                if (\is_string($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempDir);
        }
    }

    #[Test]
    public function itRoundTripsRelativeFileKeys(): void
    {
        $reloaded = $this->roundTrip(
            'file:src/Service/UserService.php',
            'class:App\Service\UserService',
        );

        self::assertEqualsCanonicalizing(
            ['file:src/Service/UserService.php', 'class:App\Service\UserService'],
            $reloaded->subjectKeys(),
        );
    }

    #[Test]
    public function itRoundTripsAbsoluteFileKeysAfterRelativization(): void
    {
        $reloaded = $this->roundTrip('file:/home/user/project/src/Foo.php');

        self::assertSame(['file:src/Foo.php'], $reloaded->subjectKeys());
    }

    #[Test]
    public function itPreservesOutOfTreeAbsoluteFileKeys(): void
    {
        $reloaded = $this->roundTrip(
            'file:/external/vendor/src/Bar.php',
            'file:/home/user/project/src/InTree.php',
        );

        self::assertEqualsCanonicalizing(
            ['file:/external/vendor/src/Bar.php', 'file:src/InTree.php'],
            $reloaded->subjectKeys(),
            'Out-of-tree absolute paths must be preserved verbatim',
        );
    }

    #[Test]
    public function itKeepsSeveralChannelsUnderOneSymbolApart(): void
    {
        $symbol = 'file:src/Service/UserService.php';

        $original = new Baseline(
            generated: new DateTimeImmutable('2026-05-19T12:00:00+00:00'),
            scope: ['src'],
            entries: [
                new BaselineEntry(
                    new BaselineIdentity($symbol, new FindingChannel('code-smell.goto', 'code-smell.goto')),
                    null,
                    1,
                ),
                new BaselineEntry(
                    new BaselineIdentity(
                        $symbol,
                        new FindingChannel('duplication.code-duplication', 'duplication.code-duplication'),
                    ),
                    [12.0],
                    1,
                ),
            ],
        );

        $reloaded = $this->writeAndLoad($original);

        self::assertSame(2, $reloaded->count());
        self::assertSame([$symbol], $reloaded->subjectKeys());
    }

    /**
     * A read-then-write cycle is what `baseline:cleanup` and `baseline:update`
     * do to a file nobody asked to change. It has to be a no-op on the bytes,
     * or every such command produces a diff of its own.
     */
    #[Test]
    public function itRewritesACanonicalFileByteForByte(): void
    {
        $path = $this->tempDir . '/baseline.json';
        $root = AbsolutePath::fromString('/home/user/project');

        $this->writer->write($this->severalSubjects(), $path, $root);
        $first = (string) file_get_contents($path);

        $this->writer->write($this->loader->load($path), $path, $root);

        self::assertSame($first, (string) file_get_contents($path));
    }

    private function severalSubjects(): Baseline
    {
        return new Baseline(
            generated: new DateTimeImmutable('2026-05-19T12:00:00+00:00'),
            scope: ['src'],
            entries: [
                new BaselineEntry(
                    new BaselineIdentity(
                        'callable:App\Foo::bar',
                        new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable'),
                    ),
                    [25],
                    1,
                ),
                new BaselineEntry(
                    new BaselineIdentity(
                        'callable:App\Foo::bar',
                        new FindingChannel('complexity.cognitive', 'complexity.cognitive.callable'),
                    ),
                    [18],
                    1,
                ),
                new BaselineEntry(
                    new BaselineIdentity(
                        'class:App\Legacy\Report',
                        new FindingChannel('code-smell.goto', 'code-smell.goto'),
                    ),
                    null,
                    2,
                ),
            ],
        );
    }

    private function roundTrip(string ...$symbolKeys): Baseline
    {
        $entries = [];
        foreach ($symbolKeys as $symbolKey) {
            $entries[] = new BaselineEntry(
                new BaselineIdentity($symbolKey, new FindingChannel('code-smell.goto', 'code-smell.goto')),
                null,
                1,
            );
        }

        return $this->writeAndLoad(new Baseline(
            generated: new DateTimeImmutable('2026-05-19T12:00:00+00:00'),
            scope: ['src'],
            entries: $entries,
        ));
    }

    private function writeAndLoad(Baseline $baseline): Baseline
    {
        $path = $this->tempDir . '/baseline.json';
        $this->writer->write($baseline, $path, AbsolutePath::fromString('/home/user/project'));

        return $this->loader->load($path);
    }
}
