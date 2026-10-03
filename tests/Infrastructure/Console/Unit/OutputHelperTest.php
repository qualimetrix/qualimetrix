<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Infrastructure\Console\OutputHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

#[CoversClass(OutputHelper::class)]
final class OutputHelperTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function provideSuppressedVerbosity(): iterable
    {
        yield 'silent' => [OutputInterface::VERBOSITY_SILENT];
        yield 'quiet' => [OutputInterface::VERBOSITY_QUIET];
    }

    #[Test]
    public function itRefusesAReadOnlyBorrowedStreamWithoutChangingOrClosingIt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-output-');
        self::assertIsString($path);
        self::assertSame(4, file_put_contents($path, 'KEEP'));

        $stream = fopen($path, 'rb');
        self::assertIsResource($stream);

        try {
            try {
                OutputHelper::write(new StreamOutput($stream), 'payload');
                self::fail('A read-only output stream must refuse the write.');
            } catch (FileTargetFailure $failure) {
                self::assertSame(FileTargetFailureKind::PartialWrite, $failure->kind);
                self::assertSame($path, $failure->spelling);
                self::assertSame('wrote 0 of 7 bytes', $failure->reason);
            }

            self::assertIsResource($stream);
            self::assertSame('KEEP', file_get_contents($path));
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    #[Test]
    #[DataProvider('provideSuppressedVerbosity')]
    public function itDoesNotWriteToASuppressedStream(int $verbosity): void
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);

        try {
            self::assertSame(4, fwrite($stream, 'KEEP'));
            OutputHelper::write(new StreamOutput($stream, $verbosity), 'payload');

            self::assertIsResource($stream);
            self::assertSame(4, ftell($stream));
            rewind($stream);
            self::assertSame('KEEP', stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }
}
