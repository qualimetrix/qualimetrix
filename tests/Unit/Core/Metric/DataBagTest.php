<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Metric;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\DataBag;

#[CoversClass(DataBag::class)]
final class DataBagTest extends TestCase
{
    public function testEmptyBag(): void
    {
        $bag = DataBag::empty();

        self::assertTrue($bag->isEmpty());
        self::assertSame([], $bag->all());
        self::assertSame([], $bag->get('nonexistent'));
        self::assertSame(0, $bag->count('nonexistent'));
        self::assertFalse($bag->has('nonexistent'));
    }

    public function testAddEntry(): void
    {
        $bag = DataBag::empty()
            ->add('findings', ['line' => 10, 'type' => 'error']);

        self::assertFalse($bag->isEmpty());
        self::assertTrue($bag->has('findings'));
        self::assertSame(1, $bag->count('findings'));
        self::assertSame([['line' => 10, 'type' => 'error']], $bag->get('findings'));
    }

    public function testAddMultipleEntriesSameKey(): void
    {
        $bag = DataBag::empty()
            ->add('findings', ['line' => 10])
            ->add('findings', ['line' => 20])
            ->add('findings', ['line' => 30]);

        self::assertSame(3, $bag->count('findings'));
    }

    public function testAddMultipleEntriesDifferentKeys(): void
    {
        $bag = DataBag::empty()
            ->add('errors', ['line' => 10])
            ->add('warnings', ['line' => 20]);

        self::assertTrue($bag->has('errors'));
        self::assertTrue($bag->has('warnings'));
        self::assertSame(1, $bag->count('errors'));
        self::assertSame(1, $bag->count('warnings'));
    }

    public function testImmutability(): void
    {
        $original = DataBag::empty();
        $modified = $original->add('key', ['line' => 1]);

        self::assertTrue($original->isEmpty());
        self::assertFalse($modified->isEmpty());
    }

    public function testFromArray(): void
    {
        $entries = [
            'findings' => [
                ['line' => 10],
                ['line' => 20],
            ],
            'warnings' => [
                ['line' => 30],
            ],
        ];

        $bag = DataBag::fromArray($entries);

        self::assertFalse($bag->isEmpty());
        self::assertSame(2, $bag->count('findings'));
        self::assertSame(1, $bag->count('warnings'));
        self::assertSame($entries, $bag->all());
    }

    // --- merge ---

    public function testMergeEmptyBags(): void
    {
        $bag1 = DataBag::empty();
        $bag2 = DataBag::empty();

        $merged = $bag1->merge($bag2);

        self::assertTrue($merged->isEmpty());
    }

    public function testMergeWithEmptyReturnsSelf(): void
    {
        $bag = DataBag::empty()->add('key', ['line' => 1]);
        $empty = DataBag::empty();

        self::assertSame($bag, $bag->merge($empty));
    }

    public function testMergeEmptyWithNonEmptyReturnsOther(): void
    {
        $empty = DataBag::empty();
        $bag = DataBag::empty()->add('key', ['line' => 1]);

        self::assertSame($bag, $empty->merge($bag));
    }

    public function testMergeDisjointKeys(): void
    {
        $bag1 = DataBag::empty()->add('a', ['line' => 1]);
        $bag2 = DataBag::empty()->add('b', ['line' => 2]);

        $merged = $bag1->merge($bag2);

        self::assertTrue($merged->has('a'));
        self::assertTrue($merged->has('b'));
    }

    public function testMergeOverlappingKeysConcatenates(): void
    {
        $bag1 = DataBag::empty()->add('findings', ['line' => 10]);
        $bag2 = DataBag::empty()->add('findings', ['line' => 20]);

        $merged = $bag1->merge($bag2);

        self::assertSame(2, $merged->count('findings'));
    }

    public function testMergeDoesNotModifyOriginals(): void
    {
        $bag1 = DataBag::empty()->add('a', ['line' => 1]);
        $bag2 = DataBag::empty()->add('a', ['line' => 2]);

        $bag1->merge($bag2);

        self::assertSame(1, $bag1->count('a'));
        self::assertSame(1, $bag2->count('a'));
    }

    // --- has edge case ---

    public function testHasReturnsFalseForEmptyList(): void
    {
        // fromArray with empty list for a key
        $bag = DataBag::fromArray(['key' => []]);

        self::assertFalse($bag->has('key'));
    }
}
