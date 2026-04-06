<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Git;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Git\ChangedFile;
use Qualimetrix\Infrastructure\Git\ChangeStatus;

#[CoversClass(ChangedFile::class)]
#[CoversClass(ChangeStatus::class)]
final class ChangedFileTest extends TestCase
{
    public function testIsPhpReturnsTrueForPhpFiles(): void
    {
        $file = new ChangedFile('src/Test.php', ChangeStatus::Modified);

        self::assertTrue($file->isPhp());
    }

    public function testIsPhpReturnsFalseForNonPhpFiles(): void
    {
        $file = new ChangedFile('README.md', ChangeStatus::Modified);

        self::assertFalse($file->isPhp());
    }

    public function testIsDeletedReturnsTrueForDeletedFiles(): void
    {
        $file = new ChangedFile('src/Test.php', ChangeStatus::Deleted);

        self::assertTrue($file->isDeleted());
    }

    public function testIsDeletedReturnsFalseForNonDeletedFiles(): void
    {
        $file = new ChangedFile('src/Test.php', ChangeStatus::Modified);

        self::assertFalse($file->isDeleted());
    }

    public function testSupportsRenamedFilesWithOldPath(): void
    {
        $file = new ChangedFile('src/NewTest.php', ChangeStatus::Renamed, 'src/OldTest.php');

        self::assertSame('src/NewTest.php', $file->path);
        self::assertSame(ChangeStatus::Renamed, $file->status);
        self::assertSame('src/OldTest.php', $file->oldPath);
    }

    public function testOldPathIsNullByDefault(): void
    {
        $file = new ChangedFile('src/Test.php', ChangeStatus::Added);

        self::assertNull($file->oldPath);
    }
}
