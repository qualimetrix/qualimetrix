<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Git;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Git\ChangedFile;
use Qualimetrix\Infrastructure\Git\ChangeStatus;

#[CoversClass(ChangedFile::class)]
#[CoversClass(ChangeStatus::class)]
final class ChangedFileTest extends TestCase
{
    #[Test]
    public function itReturnsIsPhpTrueForPhpFiles(): void
    {
        $file = new ChangedFile('src/Test.php', ChangeStatus::Modified);

        self::assertTrue($file->isPhp());
    }

    #[Test]
    public function itReturnsIsPhpFalseForNonPhpFiles(): void
    {
        $file = new ChangedFile('README.md', ChangeStatus::Modified);

        self::assertFalse($file->isPhp());
    }

    #[Test]
    public function itReturnsIsDeletedTrueForDeletedFiles(): void
    {
        $file = new ChangedFile('src/Test.php', ChangeStatus::Deleted);

        self::assertTrue($file->isDeleted());
    }

    #[Test]
    public function itReturnsIsDeletedFalseForNonDeletedFiles(): void
    {
        $file = new ChangedFile('src/Test.php', ChangeStatus::Modified);

        self::assertFalse($file->isDeleted());
    }

    #[Test]
    public function itSupportsRenamedFilesWithOldPath(): void
    {
        $file = new ChangedFile('src/NewTest.php', ChangeStatus::Renamed, 'src/OldTest.php');

        self::assertSame('src/NewTest.php', $file->path);
        self::assertSame(ChangeStatus::Renamed, $file->status);
        self::assertSame('src/OldTest.php', $file->oldPath);
    }

    #[Test]
    public function itHasNullOldPathByDefault(): void
    {
        $file = new ChangedFile('src/Test.php', ChangeStatus::Added);

        self::assertNull($file->oldPath);
    }
}
