<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit\Normalization;

use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenStream;

#[CoversClass(TokenStream::class)]
final class TokenStreamTest extends TestCase
{
    /** @param array{values: list<string>, coordinates: string, dataMask: string} $columns */
    #[Test]
    #[DataProvider('misalignedColumns')]
    public function itRefusesMisalignedTokenCoordinates(array $columns): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Token stream columns must have the same length');

        new TokenStream(...$columns);
    }

    /** @return iterable<string, array{array{values: list<string>, coordinates: string, dataMask: string}} > */
    public static function misalignedColumns(): iterable
    {
        $record = pack('q5', 2, 2, 1, 6, 10);
        $aligned = ['values' => ['echo'], 'coordinates' => 'qqqqq' . $record, 'dataMask' => '0'];
        foreach (['missing record' => 'qqqqq', 'incomplete record' => 'qqqqq' . substr($record, 0, 39), 'extra record' => 'qqqqq' . $record . $record] as $name => $coordinates) {
            yield $name => [array_replace($aligned, ['coordinates' => $coordinates])];
        }
        yield 'short mask' => [array_replace($aligned, ['dataMask' => ''])];
        yield 'long mask' => [array_replace($aligned, ['dataMask' => '00'])];
        yield 'empty values with coordinates' => [array_replace($aligned, ['values' => [], 'dataMask' => ''])];
    }

    #[Test]
    public function itReadsEveryPackedCoordinateWithoutNarrowingTheIntegerRange(): void
    {
        $cases = [
            ['CCCCC', [0, 1, 2, 254, 255]],
            ['vvvvv', [255, 256, 257, 65534, 65535]],
            ['VVVVV', [65535, 65536, 65537, 4294967294, 4294967295]],
            ['qqqqq', [\PHP_INT_MIN, -1, 4294967296, 1099511627776, \PHP_INT_MAX]],
            ['CvVqq', [255, 65535, 4294967295, \PHP_INT_MAX, \PHP_INT_MIN]],
        ];
        foreach ($cases as [$format, $row]) {
            $stream = new TokenStream(['a', 'b'], $format . pack($format, ...$row) . pack($format, ...$row), '01');
            self::assertSame(2, $stream->count());
            foreach ([0, 1] as $index) {
                self::assertSame($row, [$stream->startLine($index), $stream->endLine($index), $stream->coveredPrefix($index), $stream->startByte($index), $stream->endByte($index)]);
            }
        }
        self::assertSame(0, (new TokenStream([], 'CCCCC', ''))->count());
        foreach (['', 'qqqq', 'qqqqx'] as $header) {
            try {
                new TokenStream([], $header, '');
                self::fail('A malformed coordinate header must be refused');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Token stream columns must have the same length', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function itRefusesOffsetsOutsideThePackedTokenStream(): void
    {
        $stream = new TokenStream(['echo'], 'CCCCC' . pack('C5', 2, 2, 1, 6, 10), '0');
        self::assertSame(2, $stream->startLine(0));
        foreach ([-1, 1, \PHP_INT_MAX] as $index) {
            foreach ([$stream->startLine(...), $stream->endLine(...), $stream->coveredPrefix(...), $stream->startByte(...), $stream->endByte(...)] as $read) {
                try {
                    $read($index);
                    self::fail('A coordinate outside the token stream must be refused');
                } catch (OutOfBoundsException $exception) {
                    self::assertSame('Token coordinate index is outside the stream.', $exception->getMessage());
                }
            }
        }
    }
}
