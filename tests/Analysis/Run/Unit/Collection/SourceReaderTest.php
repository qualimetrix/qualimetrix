<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Collection\SourceReader;
use Qualimetrix\Analysis\Run\Collection\UnreadableSource;
use SplFileInfo;

#[CoversClass(SourceReader::class)]
#[CoversClass(UnreadableSource::class)]
final class SourceReaderTest extends TestCase
{
    #[Test]
    public function itReturnsTheExactSourceBytesIncludingAnEmptyFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-source-reader-');
        self::assertIsString($path);

        try {
            $source = "<?php\r\n// control\n";
            file_put_contents($path, $source);
            self::assertSame($source, (new SourceReader())->read(new SplFileInfo($path)));

            file_put_contents($path, '');
            self::assertSame('', (new SourceReader())->read(new SplFileInfo($path)));
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function itRefusesMissingAndDirectoryEntriesBeforeParsing(): void
    {
        $reader = new SourceReader();
        $missing = $reader->read(new SplFileInfo(sys_get_temp_dir() . '/qmx-missing-' . bin2hex(random_bytes(6))));
        $directory = $reader->read(new SplFileInfo(sys_get_temp_dir()));

        self::assertInstanceOf(UnreadableSource::class, $missing);
        self::assertSame('File does not exist or is not a regular file', $missing->reason);
        self::assertInstanceOf(UnreadableSource::class, $directory);
        self::assertSame('File does not exist or is not a regular file', $directory->reason);
    }
}
