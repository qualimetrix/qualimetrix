<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Baseline;

use AiMessDetector\Baseline\Baseline;
use AiMessDetector\Baseline\BaselineEntry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Baseline::class)]
final class BaselineTest extends TestCase
{
    public function testContainsReturnsTrueForExistingViolation(): void
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

    public function testContainsReturnsFalseForNonExistingViolation(): void
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

    public function testContainsReturnsFalseForNonExistingFile(): void
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

    public function testCountReturnsCorrectTotal(): void
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

    public function testCountReturnsZeroForEmptyBaseline(): void
    {
        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [],
        );

        self::assertSame(0, $baseline->count());
    }

    public function testDiffReturnsResolvedViolations(): void
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

    public function testDiffReturnsEmptyWhenNothingResolved(): void
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

    public function testGetStaleKeysReturnsNonExistentKeys(): void
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

    public function testGetStaleKeysReturnsEmptyWhenAllKeysExist(): void
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
