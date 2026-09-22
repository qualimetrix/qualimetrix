<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Git\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Git\NameStatusRecord;
use RuntimeException;

/**
 * The walk over `git diff --name-status -z`.
 *
 * Its one hazard is arithmetic: a record's width is decided by its status
 * letter, so counting wrong once shifts every record after it. These cases put
 * a one-path and a two-path record on either side of each other and assert the
 * later one landed, which a miscount cannot survive.
 */
#[CoversClass(NameStatusRecord::class)]
final class NameStatusRecordTest extends TestCase
{
    #[Test]
    public function itReadsNothingFromAnEmptyStream(): void
    {
        self::assertSame([], NameStatusRecord::parseStream(''));
    }

    #[Test]
    public function itReadsOnePathForASingleFileStatus(): void
    {
        $records = NameStatusRecord::parseStream("M\0src/Thing.php\0");

        self::assertCount(1, $records);
        self::assertSame('M', $records[0]->status);
        self::assertSame('src/Thing.php', $records[0]->rawPath);
        self::assertNull($records[0]->rawOldPath);
    }

    #[Test]
    public function itReadsTwoPathsForARenameAndKeepsTheirOrder(): void
    {
        $records = NameStatusRecord::parseStream("R100\0src/Old.php\0src/New.php\0");

        self::assertCount(1, $records);
        self::assertSame('R', $records[0]->status);
        self::assertSame('src/New.php', $records[0]->rawPath);
        self::assertSame('src/Old.php', $records[0]->rawOldPath);
    }

    /**
     * The desync witness. A `T` (one path) and an `R` (two) sit in front of an
     * ordinary record; if either width were read wrong, the last record would
     * come back as a path read as a status, or as the wrong path.
     */
    #[Test]
    public function itKeepsFieldWidthsStraightAcrossMixedStatuses(): void
    {
        $records = NameStatusRecord::parseStream(
            "T\0src/Typed.php\0R100\0src/Old.php\0src/New.php\0C075\0src/Source.php\0src/Copy.php\0A\0src/Last.php\0",
        );

        self::assertCount(4, $records);
        self::assertSame(['T', 'R', 'C', 'A'], array_column($records, 'status'));
        self::assertSame(
            ['src/Typed.php', 'src/New.php', 'src/Copy.php', 'src/Last.php'],
            array_column($records, 'rawPath'),
        );
        self::assertSame([null, 'src/Old.php', 'src/Source.php', null], array_column($records, 'rawOldPath'));
    }

    /**
     * The whole point of `-z`: these bytes are the name, not a transport
     * encoding of it. The first case is what `core.quotePath` would have
     * turned into `"src/\320\242.php"`, and the second is what a decoder
     * applied to it would have turned into the two segments `a/057b.php`.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideNamesCarriedVerbatim(): iterable
    {
        yield 'non-ascii' => ['src/Тест.php'];
        yield 'space' => ['src/with space.php'];
        yield 'trailing space' => ['src/trailing .php'];
        yield 'double quote' => ['src/say "hi".php'];
        yield 'text that looks like an octal escape' => ['src/a\057b.php'];
        yield 'backslash' => ['src/back\\slash.php'];
        yield 'leading dash' => ['-dash.php'];
        yield 'newline' => ["src/two\nlines.php"];
        yield 'tab' => ["src/two\tcolumns.php"];
    }

    #[Test]
    #[DataProvider('provideNamesCarriedVerbatim')]
    public function itCarriesEveryNameByteVerbatim(string $name): void
    {
        $records = NameStatusRecord::parseStream("A\0" . $name . "\0");

        self::assertCount(1, $records);
        self::assertSame($name, $records[0]->rawPath);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnwalkableStreams(): iterable
    {
        yield 'a path where a status belongs' => ["A\0src/One.php\0src/Two.php\0"];
        yield 'a status with no path' => ["A\0"];
        yield 'a rename with only one path' => ["R100\0src/Old.php\0"];
        yield 'an empty field before the end' => ["A\0\0M\0src/Thing.php\0"];
        yield 'the old tab-separated format' => ["M\tsrc/Thing.php\n"];
    }

    /**
     * A stream this cannot walk is the tool's machine contract with git
     * breaking, not a user's bad input — so it stops loudly instead of
     * returning the records it managed to read.
     */
    #[Test]
    #[DataProvider('provideUnwalkableStreams')]
    public function itRefusesAStreamItCannotWalk(string $stream): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/git diff --name-status -z/');

        NameStatusRecord::parseStream($stream);
    }
}
