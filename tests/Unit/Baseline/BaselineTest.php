<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;

#[CoversClass(Baseline::class)]
final class BaselineTest extends TestCase
{
    #[Test]
    public function itContainsReturnsTrueForExistingViolation(): void
    {
        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', 'a1b2c3d4'),
                ],
            ],
        );

        self::assertTrue($baseline->contains('method:App\Foo::bar', 'a1b2c3d4'));
    }

    #[Test]
    public function itContainsReturnsFalseForNonExistingViolation(): void
    {
        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', 'a1b2c3d4'),
                ],
            ],
        );

        self::assertFalse($baseline->contains('method:App\Foo::bar', 'different'));
    }

    #[Test]
    public function itContainsReturnsFalseForNonExistingFile(): void
    {
        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', 'a1b2c3d4'),
                ],
            ],
        );

        self::assertFalse($baseline->contains('method:App\Bar::baz', 'a1b2c3d4'));
    }

    #[Test]
    public function itCountReturnsCorrectTotal(): void
    {
        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', 'a1b2c3d4'),
                ],
                'class:App\Foo' => [
                    new BaselineEntry('size', 'e5f6g7h8'),
                ],
                'class:App\Bar' => [
                    new BaselineEntry('coupling', 'i9j0k1l2'),
                ],
            ],
        );

        self::assertSame(3, $baseline->count());
    }

    #[Test]
    public function itCountReturnsZeroForEmptyBaseline(): void
    {
        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [],
        );

        self::assertSame(0, $baseline->count());
    }

    #[Test]
    public function itDiffReturnsResolvedViolations(): void
    {
        $entry1 = new BaselineEntry('complexity', 'a1b2c3d4');
        $entry2 = new BaselineEntry('size', 'e5f6g7h8');
        $entry3 = new BaselineEntry('coupling', 'i9j0k1l2');

        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [$entry1],
                'class:App\Foo' => [$entry2],
                'class:App\Bar' => [$entry3],
            ],
        );

        // Current run has only entry1 and entry3 (entry2 was fixed)
        $currentHashes = [
            'method:App\Foo::bar' => ['a1b2c3d4'],
            'class:App\Bar' => ['i9j0k1l2'],
        ];

        $resolved = $baseline->diff($currentHashes);

        self::assertArrayHasKey('class:App\Foo', $resolved);
        self::assertCount(1, $resolved['class:App\Foo']);
        self::assertSame($entry2, $resolved['class:App\Foo'][0]);
    }

    #[Test]
    public function itDiffReturnsEmptyWhenNothingResolved(): void
    {
        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', 'a1b2c3d4'),
                ],
            ],
        );

        $currentHashes = [
            'method:App\Foo::bar' => ['a1b2c3d4'],
        ];

        $resolved = $baseline->diff($currentHashes);

        self::assertEmpty($resolved);
    }

    #[Test]
    public function itGetStaleKeysReturnsNonExistentKeys(): void
    {
        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', 'a1b2c3d4'),
                ],
                'class:App\Bar' => [
                    new BaselineEntry('size', 'e5f6g7h8'),
                ],
                'class:App\Deleted' => [
                    new BaselineEntry('coupling', 'i9j0k1l2'),
                ],
            ],
        );

        $existingKeys = ['method:App\Foo::bar', 'class:App\Bar'];

        $staleKeys = $baseline->getStaleKeys($existingKeys);

        self::assertSame(['class:App\Deleted'], $staleKeys);
    }

    #[Test]
    public function itGetStaleKeysReturnsEmptyWhenAllKeysExist(): void
    {
        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', 'a1b2c3d4'),
                ],
            ],
        );

        $existingKeys = ['method:App\Foo::bar'];

        $staleKeys = $baseline->getStaleKeys($existingKeys);

        self::assertEmpty($staleKeys);
    }
}
