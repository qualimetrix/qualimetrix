<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Duplication;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Duplication\DuplicateBlock;
use Qualimetrix\Core\Duplication\DuplicateLocation;
use Qualimetrix\Core\Path\RelativePath;

#[CoversClass(DuplicateBlock::class)]
final class DuplicateBlockIdentityTest extends TestCase
{
    private const string CONTENT_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[Test]
    public function itKeepsContentIdentityWhenPresentationLocationsArePermuted(): void
    {
        $locations = [
            new DuplicateLocation(RelativePath::fromString('src/C.php'), 30, 45),
            new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 25),
            new DuplicateLocation(RelativePath::fromString('src/B.php'), 20, 35),
        ];

        $forward = new DuplicateBlock($locations, 16, 80, self::CONTENT_HASH);
        $reverse = new DuplicateBlock(array_reverse($locations), 16, 80, self::CONTENT_HASH);

        self::assertSame(self::CONTENT_HASH, $forward->contentHash);
        self::assertSame($forward->contentHash, $reverse->contentHash);
        self::assertSame('src/A.php', $forward->primaryLocation()->file->value());
        self::assertSame('src/A.php', $reverse->primaryLocation()->file->value());
    }

    #[Test]
    public function itRejectsAnythingOtherThanAFullSha256ContentDigest(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DuplicateBlock([
            new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 25),
            new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45),
        ], 16, 80, 'truncated');
    }
}
