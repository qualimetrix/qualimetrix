<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Git;

use AiMessDetector\Infrastructure\Git\ChangedFile;
use AiMessDetector\Infrastructure\Git\ChangeStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangedFile::class)]
#[CoversClass(ChangeStatus::class)]
final class ChangedFileTest extends TestCase
{
    public function testIsPhpReturnsTrueForPhpFiles(): void
    {
        $file = new ChangedFile('src/Test.php', ChangeStatus::Modified);

        $this->assertTrue($file->isPhp());
    }

    public function testIsPhpReturnsFalseForNonPhpFiles(): void
    {
        $file = new ChangedFile('README.md', ChangeStatus::Modified);

        $this->assertFalse($file->isPhp());
    }

    public function testIsDeletedReturnsTrueForDeletedFiles(): void
    {
        $file = new ChangedFile('src/Test.php', ChangeStatus::Deleted);

        $this->assertTrue($file->isDeleted());
    }

    public function testIsDeletedReturnsFalseForNonDeletedFiles(): void
    {
        $file = new ChangedFile('src/Test.php', ChangeStatus::Modified);

        $this->assertFalse($file->isDeleted());
    }

    public function testSupportsRenamedFilesWithOldPath(): void
    {
        $file = new ChangedFile('src/NewTest.php', ChangeStatus::Renamed, 'src/OldTest.php');

        $this->assertSame('src/NewTest.php', $file->path);
        $this->assertSame(ChangeStatus::Renamed, $file->status);
        $this->assertSame('src/OldTest.php', $file->oldPath);
    }

    public function testOldPathIsNullByDefault(): void
    {
        $file = new ChangedFile('src/Test.php', ChangeStatus::Added);

        $this->assertNull($file->oldPath);
    }
}
