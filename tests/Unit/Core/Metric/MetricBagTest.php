<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Metric;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\DataBag;
use Qualimetrix\Core\Metric\MetricBag;
use RuntimeException;

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

    public function testRequireReturnsExistingMetric(): void
    {
        $bag = (new MetricBag())->with('ccn', 7);

        self::assertSame(7, $bag->require('ccn'));
    }

    public function testRequireThrowsForMissingMetric(): void
    {
        $bag = (new MetricBag())->with('loc', 100);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required metric "ccn" not found');

        $bag->require('ccn');
    }

    public function testRequireThrowsOnEmptyBag(): void
    {
        $bag = new MetricBag();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('(empty)');

        $bag->require('anything');
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

    // --- withEntry / entries / entryCount / dataBag ---

    public function testWithEntryAndEntries(): void
    {
        $bag = (new MetricBag())
            ->withEntry('findings', ['line' => 10, 'type' => 'error'])
            ->withEntry('findings', ['line' => 20, 'type' => 'warning']);

        $entries = $bag->entries('findings');

        self::assertCount(2, $entries);
        self::assertSame(10, $entries[0]['line']);
        self::assertSame(20, $entries[1]['line']);
    }

    public function testWithEntryReturnsNewInstance(): void
    {
        $original = new MetricBag();
        $modified = $original->withEntry('key', ['line' => 1]);

        self::assertNotSame($original, $modified);
        self::assertSame([], $original->entries('key'));
        self::assertCount(1, $modified->entries('key'));
    }

    public function testEntryCount(): void
    {
        $bag = (new MetricBag())
            ->withEntry('findings', ['line' => 10])
            ->withEntry('findings', ['line' => 20])
            ->withEntry('other', ['line' => 30]);

        self::assertSame(2, $bag->entryCount('findings'));
        self::assertSame(1, $bag->entryCount('other'));
        self::assertSame(0, $bag->entryCount('nonexistent'));
    }

    public function testEntriesReturnsEmptyForNonexistentKey(): void
    {
        $bag = new MetricBag();

        self::assertSame([], $bag->entries('nonexistent'));
    }

    public function testDataBagReturnsDataBagInstance(): void
    {
        $bag = (new MetricBag())
            ->withEntry('key', ['line' => 1]);

        $dataBag = $bag->dataBag();

        self::assertInstanceOf(DataBag::class, $dataBag);
        self::assertSame(1, $dataBag->count('key'));
    }

    public function testDataBagIsEmptyForNewBag(): void
    {
        $bag = new MetricBag();

        self::assertTrue($bag->dataBag()->isEmpty());
    }

    // --- withEntry preserves metrics ---

    public function testWithEntryPreservesExistingMetrics(): void
    {
        $bag = (new MetricBag())
            ->with('complexity', 5)
            ->withEntry('findings', ['line' => 10]);

        self::assertSame(5, $bag->get('complexity'));
        self::assertCount(1, $bag->entries('findings'));
    }

    // --- merge preserves entries ---

    public function testMergePreservesEntries(): void
    {
        $bag1 = (new MetricBag())
            ->with('a', 1)
            ->withEntry('findings', ['line' => 10]);

        $bag2 = (new MetricBag())
            ->with('b', 2)
            ->withEntry('findings', ['line' => 20]);

        $merged = $bag1->merge($bag2);

        self::assertSame(1, $merged->get('a'));
        self::assertSame(2, $merged->get('b'));
        self::assertSame(2, $merged->entryCount('findings'));
    }

    // --- serialize/unserialize with entries ---

    public function testSerializeAndUnserializeWithEntries(): void
    {
        $bag = (new MetricBag())
            ->with('complexity', 5)
            ->withEntry('findings', ['line' => 10, 'type' => 'error']);

        $serialized = serialize($bag);
        /** @var MetricBag $unserialized */
        $unserialized = unserialize($serialized);

        self::assertSame(5, $unserialized->get('complexity'));
        self::assertSame(1, $unserialized->entryCount('findings'));
        self::assertSame(10, $unserialized->entries('findings')[0]['line']);
    }
}
