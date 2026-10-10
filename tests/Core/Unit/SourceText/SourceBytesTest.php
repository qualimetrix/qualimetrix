<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Unit\SourceText;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\SourceText\SourceBytes;

#[CoversClass(SourceBytes::class)]
final class SourceBytesTest extends TestCase
{
    #[Test]
    #[DataProvider('provideValidStrings')]
    public function itPreservesValidStringsInPublicationAndFraming(string $value): void
    {
        self::assertTrue(SourceBytes::isUtf8($value));
        self::assertSame($value, SourceBytes::escapeInvalid($value));
        self::assertSame($value, SourceBytes::escapeInvalidBytes($value));
        self::assertSame($value, SourceBytes::framed($value));
        self::assertSame(str_replace('%', '%25', $value), SourceBytes::escape($value));
    }

    /** @return iterable<string, array{string}> */
    public static function provideValidStrings(): iterable
    {
        yield 'empty' => [''];
        yield 'ASCII controls' => ["\x00\t\n\r\x7F"];
        yield 'percent literal' => ['50% a%FF'];
        yield 'two-byte boundaries' => ["\u{80}\u{7FF}"];
        yield 'three-byte boundaries' => ["\u{800}\u{D7FF}\u{E000}\u{FFFF}"];
        yield 'four-byte boundaries' => ["\u{10000}\u{10FFFF}"];
        yield 'mixed language' => ['Café Ελληνικά 日本語 😀'];
    }

    #[Test]
    #[DataProvider('provideInvalidStrings')]
    public function itEscapesEachInvalidByteAndPreservesAdjacentValidText(string $value, string $expected): void
    {
        self::assertFalse(SourceBytes::isUtf8($value));
        self::assertSame($expected, SourceBytes::escapeInvalidBytes($value));
        self::assertTrue(mb_check_encoding($expected, 'UTF-8'));
        self::assertSame($value, rawurldecode(SourceBytes::escape($value)));
        self::assertSame(SourceBytes::escape($value), SourceBytes::escapeInvalid($value));
        self::assertSame(['%' => SourceBytes::escape($value)], SourceBytes::framed($value));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideInvalidStrings(): iterable
    {
        yield 'isolated high bytes' => ["K\xFF\xFE", 'K%FF%FE'];
        yield 'isolated continuation' => ["a\x80b", 'a%80b'];
        yield 'two-byte overlong' => ["\xC0\xAF", '%C0%AF'];
        yield 'three-byte overlong' => ["\xE0\x80\xAF", '%E0%80%AF'];
        yield 'four-byte overlong' => ["\xF0\x80\x80\xAF", '%F0%80%80%AF'];
        yield 'surrogate' => ["\xED\xA0\x80", '%ED%A0%80'];
        yield 'beyond Unicode' => ["\xF4\x90\x80\x80", '%F4%90%80%80'];
        yield 'forbidden lead' => ["\xF5\x80\x80\x80", '%F5%80%80%80'];
        yield 'truncated two-byte' => ["a\xC2", 'a%C2'];
        yield 'truncated three-byte' => ["a\xE2\x82", 'a%E2%82'];
        yield 'truncated four-byte' => ["a\xF0\x9F\x98", 'a%F0%9F%98'];
        yield 'lead before ASCII' => ["\xC2A", '%C2A'];
        yield 'interrupted three-byte' => ["\xE2\x82Z", '%E2%82Z'];
        yield 'interrupted four-byte' => ["\xF0\x9FZ\x98", '%F0%9FZ%98'];
        yield 'valid sequence after invalid lead' => ["\xC2\u{80}", '%C2' . "\u{80}"];
        yield 'mixed Unicode' => ["Café\xFF😀\x80\n", 'Café%FF😀%80' . "\n"];
    }

    #[Test]
    public function itDistinguishesInvalidBytesAndLiteralEscapeSequences(): void
    {
        self::assertSame('K%FF', SourceBytes::escape("K\xFF"));
        self::assertSame('K%FE', SourceBytes::escape("K\xFE"));
        self::assertSame('a%25FF', SourceBytes::escape('a%FF'));
        self::assertSame('a%FF', SourceBytes::escape("a\xFF"));
        self::assertSame('50% %FF', SourceBytes::escapeInvalidBytes("50% \xFF"));
        self::assertSame('50%25 %FF', SourceBytes::escapeInvalid("50% \xFF"));
        self::assertNotSame(SourceBytes::framed('a%FF'), SourceBytes::framed("a\xFF"));
        self::assertNotSame(SourceBytes::framed("a\xFF"), SourceBytes::framed("a\xFE"));
    }

    #[Test]
    public function itRoundTripsArbitraryByteStrings(): void
    {
        for ($sample = 0; $sample < 128; ++$sample) {
            $value = hash('sha256', (string) $sample, true) . "%\x00";
            $escaped = SourceBytes::escape($value);
            self::assertSame($value, rawurldecode($escaped));
            self::assertTrue(mb_check_encoding($escaped, 'UTF-8'));
        }
    }

    #[Test]
    public function itPreservesComponentBoundariesAcrossAsciiSeparators(): void
    {
        for ($byte = 0x80; $byte <= 0xFF; ++$byte) {
            $left = 'Café' . \chr($byte);
            $right = \chr($byte) . '😀';
            self::assertSame(
                'class:' . SourceBytes::escape($left) . '\\' . SourceBytes::escape($right),
                SourceBytes::escape('class:' . $left . '\\' . $right),
            );
        }
    }

    #[Test]
    public function itEscapesReservedAsciiSeparatorsWithoutConfusingOrdinalSuffixes(): void
    {
        self::assertSame('x.php%232', SourceBytes::escape('x.php#2', '#'));
        self::assertSame('x.php#2', rawurldecode(SourceBytes::escape('x.php#2', '#')));
        self::assertNotSame(SourceBytes::escape('x.php#2', '#'), SourceBytes::escape('x.php', '#') . '#2');
        self::assertSame('%25%23%40%23%FF', SourceBytes::escape("%#@#\xFF", '%#@#'));

        for ($byte = 0; $byte < 0x80; ++$byte) {
            $separator = \chr($byte);
            self::assertSame(\sprintf('%%%02X', $byte), SourceBytes::escape($separator, $separator));
            self::assertSame($separator, rawurldecode(SourceBytes::escape($separator, $separator)));
        }
    }

    #[Test]
    #[DataProvider('provideNonAsciiSeparators')]
    public function itRefusesNonAsciiReservedBytesEvenForEmptyValues(string $reserved): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Reserved source-byte separators must be ASCII.');
        SourceBytes::escape('', $reserved);
    }

    /** @return iterable<string, array{string}> */
    public static function provideNonAsciiSeparators(): iterable
    {
        yield 'invalid high byte' => ["#\xFF"];
        yield 'continuation byte' => ["\x80"];
        yield 'valid Unicode' => ['é'];
    }
}
