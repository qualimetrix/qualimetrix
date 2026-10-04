<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit\Normalization;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenStream;

#[CoversClass(TokenStream::class)]
final class TokenStreamTest extends TestCase
{
    /**
     * @param array{values: list<string>, startLines: list<int>, endLines: list<int>, coveredPrefix: list<int>, dataMask: string, startBytes: list<int>, endBytes: list<int>} $columns
     */
    #[Test]
    #[DataProvider('misalignedColumns')]
    public function itRefusesMisalignedTokenCoordinates(array $columns): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Token stream columns must have the same length');

        new TokenStream(...$columns);
    }

    /**
     * @return iterable<string, array{array{values: list<string>, startLines: list<int>, endLines: list<int>, coveredPrefix: list<int>, dataMask: string, startBytes: list<int>, endBytes: list<int>}}>
     */
    public static function misalignedColumns(): iterable
    {
        $aligned = [
            'values' => ['echo'],
            'startLines' => [2],
            'endLines' => [2],
            'coveredPrefix' => [1],
            'dataMask' => '0',
            'startBytes' => [6],
            'endBytes' => [10],
        ];

        foreach (['startLines', 'endLines', 'coveredPrefix', 'startBytes', 'endBytes'] as $column) {
            $columns = $aligned;
            $columns[$column] = [];

            yield $column => [$columns];
        }

        $aligned['dataMask'] = '';

        yield 'dataMask' => [$aligned];
    }
}
