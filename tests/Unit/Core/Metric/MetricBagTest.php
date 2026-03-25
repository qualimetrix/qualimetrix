<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Metric;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;

#[CoversClass(MetricBag::class)]
final class MetricBagTest extends TestCase
{
    public function testWithAndGet(): void
    {
        $bag = (new MetricBag())
            ->with('complexity', 5)
            ->with('loc', 100.5);

        self::assertSame(5, $bag->get('complexity'));
        self::assertSame(100.5, $bag->get('loc'));
    }

    public function testWithReturnsNewInstance(): void
    {
        $original = new MetricBag();
        $modified = $original->with('complexity', 5);

        self::assertNotSame($original, $modified);
        self::assertNull($original->get('complexity'));
        self::assertSame(5, $modified->get('complexity'));
    }

    public function testGetReturnsNullForNonexistentMetric(): void
    {
        $bag = new MetricBag();

        self::assertNull($bag->get('nonexistent'));
    }

    public function testHas(): void
    {
        $bag = (new MetricBag())->with('complexity', 5);

        self::assertTrue($bag->has('complexity'));
        self::assertFalse($bag->has('nonexistent'));
    }

    public function testAll(): void
    {
        $bag = (new MetricBag())
            ->with('complexity', 5)
            ->with('loc', 100);

        self::assertSame(
            ['complexity' => 5, 'loc' => 100],
            $bag->all(),
        );
    }

    public function testMerge(): void
    {
        $bag1 = (new MetricBag())
            ->with('complexity', 5)
            ->with('loc', 100);

        $bag2 = (new MetricBag())
            ->with('npath', 10)
            ->with('loc', 200); // Override

        $merged = $bag1->merge($bag2);

        self::assertSame(5, $merged->get('complexity'));
        self::assertSame(200, $merged->get('loc')); // Value from $bag2
        self::assertSame(10, $merged->get('npath'));
    }

    public function testMergeDoesNotModifyOriginalBags(): void
    {
        $bag1 = (new MetricBag())->with('complexity', 5);
        $bag2 = (new MetricBag())->with('loc', 100);

        $bag1->merge($bag2);

        self::assertFalse($bag1->has('loc'));
        self::assertFalse($bag2->has('complexity'));
    }

    public function testWithPrefix(): void
    {
        $bag = (new MetricBag())
            ->with('complexity', 5)
            ->with('loc', 100);

        $prefixed = $bag->withPrefix('method.');

        self::assertSame(5, $prefixed->get('method.complexity'));
        self::assertSame(100, $prefixed->get('method.loc'));
        self::assertNull($prefixed->get('complexity'));
    }

    public function testSerializeAndUnserialize(): void
    {
        $bag = (new MetricBag())
            ->with('complexity', 5)
            ->with('loc', 100.5);

        $serialized = serialize($bag);
        /** @var MetricBag $unserialized */
        $unserialized = unserialize($serialized);

        self::assertSame(5, $unserialized->get('complexity'));
        self::assertSame(100.5, $unserialized->get('loc'));
    }

    public function testFromArray(): void
    {
        $bag = MetricBag::fromArray([
            'complexity' => 5,
            'loc' => 100.5,
        ]);

        self::assertSame(5, $bag->get('complexity'));
        self::assertSame(100.5, $bag->get('loc'));
        self::assertSame(
            ['complexity' => 5, 'loc' => 100.5],
            $bag->all(),
        );
    }

    public function testFromArrayWithEmptyArray(): void
    {
        $bag = MetricBag::fromArray([]);

        self::assertSame([], $bag->all());
    }
}
