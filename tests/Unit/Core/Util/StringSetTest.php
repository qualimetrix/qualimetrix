<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Util\StringSet;

#[CoversClass(StringSet::class)]
final class StringSetTest extends TestCase
{
    #[Test]
    public function add_addsUniqueStrings(): void
    {
        $set = new StringSet();
        $set = $set->add('foo');
        $set = $set->add('bar');

        self::assertSame(2, $set->count());
        self::assertTrue($set->contains('foo'));
        self::assertTrue($set->contains('bar'));
    }

    #[Test]
    public function add_deduplicates(): void
    {
        $set = new StringSet();
        $set = $set->add('foo');
        $set = $set->add('foo');
        $set = $set->add('foo');

        self::assertSame(1, $set->count());
    }

    #[Test]
    public function add_returnsNewInstance(): void
    {
        $set1 = new StringSet();
        $set2 = $set1->add('foo');

        self::assertNotSame($set1, $set2);
        self::assertFalse($set1->contains('foo'));
        self::assertTrue($set2->contains('foo'));
    }

    #[Test]
    public function add_returnsSameInstanceWhenDuplicate(): void
    {
        $set1 = (new StringSet())->add('foo');
        $set2 = $set1->add('foo');

        self::assertSame($set1, $set2);
    }

    #[Test]
    public function addAll_addsMultipleStrings(): void
    {
        $set = (new StringSet())->addAll(['foo', 'bar', 'baz']);

        self::assertSame(3, $set->count());
        self::assertTrue($set->contains('foo'));
        self::assertTrue($set->contains('bar'));
        self::assertTrue($set->contains('baz'));
    }

    #[Test]
    public function contains_returnsFalseForMissing(): void
    {
        $set = (new StringSet())->add('foo');

        self::assertFalse($set->contains('bar'));
    }

    #[Test]
    public function isEmpty_returnsTrueForEmptySet(): void
    {
        $set = new StringSet();

        self::assertTrue($set->isEmpty());
    }

    #[Test]
    public function isEmpty_returnsFalseForNonEmptySet(): void
    {
        $set = (new StringSet())->add('foo');

        self::assertFalse($set->isEmpty());
    }

    #[Test]
    public function toArray_returnsStringsAsIndexedArray(): void
    {
        $set = (new StringSet())->addAll(['foo', 'bar']);
        $array = $set->toArray();

        self::assertCount(2, $array);
        self::assertContains('foo', $array);
        self::assertContains('bar', $array);
    }

    #[Test]
    public function filter_appliesPredicate(): void
    {
        $set = (new StringSet())->addAll(['App\\Foo', 'App\\Bar', 'Vendor\\Baz']);
        $filtered = $set->filter(fn(string $s) => str_starts_with($s, 'App\\'));

        self::assertSame(2, $filtered->count());
        self::assertTrue($filtered->contains('App\\Foo'));
        self::assertTrue($filtered->contains('App\\Bar'));
        self::assertFalse($filtered->contains('Vendor\\Baz'));
    }

    #[Test]
    public function union_combinesSets(): void
    {
        $set1 = (new StringSet())->addAll(['foo', 'bar']);
        $set2 = (new StringSet())->addAll(['bar', 'baz']);
        $union = $set1->union($set2);

        self::assertSame(3, $union->count());
        self::assertTrue($union->contains('foo'));
        self::assertTrue($union->contains('bar'));
        self::assertTrue($union->contains('baz'));
    }

    #[Test]
    public function intersect_returnsCommonElements(): void
    {
        $set1 = (new StringSet())->addAll(['foo', 'bar', 'baz']);
        $set2 = (new StringSet())->addAll(['bar', 'baz', 'qux']);
        $intersect = $set1->intersect($set2);

        self::assertSame(2, $intersect->count());
        self::assertTrue($intersect->contains('bar'));
        self::assertTrue($intersect->contains('baz'));
    }

    #[Test]
    public function diff_returnsUniqueElements(): void
    {
        $set1 = (new StringSet())->addAll(['foo', 'bar', 'baz']);
        $set2 = (new StringSet())->addAll(['bar', 'baz']);
        $diff = $set1->diff($set2);

        self::assertSame(1, $diff->count());
        self::assertTrue($diff->contains('foo'));
    }

    #[Test]
    public function getIterator_yieldsAllStrings(): void
    {
        $set = (new StringSet())->addAll(['foo', 'bar']);
        $items = [];

        foreach ($set as $item) {
            $items[] = $item;
        }

        self::assertCount(2, $items);
        self::assertContains('foo', $items);
        self::assertContains('bar', $items);
    }

    #[Test]
    public function fromArray_createsSetFromArray(): void
    {
        $set = StringSet::fromArray(['foo', 'bar', 'foo']);

        self::assertSame(2, $set->count());
        self::assertTrue($set->contains('foo'));
        self::assertTrue($set->contains('bar'));
    }
}
