<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Domain\CoverageMode;

#[CoversClass(CoverageMode::class)]
final class CoverageModeTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: CoverageMode}>
     */
    public static function caseProvider(): iterable
    {
        yield 'ignore lowercase' => ['ignore', CoverageMode::Ignore];
        yield 'ignore uppercase' => ['IGNORE', CoverageMode::Ignore];
        yield 'ignore mixed case' => ['Ignore', CoverageMode::Ignore];
        yield 'warn lowercase' => ['warn', CoverageMode::Warn];
        yield 'warn uppercase' => ['WARN', CoverageMode::Warn];
        yield 'error lowercase' => ['error', CoverageMode::Error];
        yield 'error uppercase' => ['ERROR', CoverageMode::Error];
    }

    #[Test]
    #[DataProvider('caseProvider')]
    public function fromString_resolvesKnownValueCaseInsensitively(string $input, CoverageMode $expected): void
    {
        self::assertSame($expected, CoverageMode::fromString($input));
    }

    #[Test]
    public function fromString_throwsForUnknownValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown coverage mode "debug"');

        CoverageMode::fromString('debug');
    }

    #[Test]
    public function fromString_throwsForEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CoverageMode::fromString('');
    }

    #[Test]
    public function casesExposeStableStringValues(): void
    {
        self::assertSame('ignore', CoverageMode::Ignore->value);
        self::assertSame('warn', CoverageMode::Warn->value);
        self::assertSame('error', CoverageMode::Error->value);
    }
}
