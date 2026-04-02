<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Metric;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\CollectorConfigHolder;

#[CoversClass(CollectorConfigHolder::class)]
final class CollectorConfigHolderTest extends TestCase
{
    protected function tearDown(): void
    {
        CollectorConfigHolder::reset();
    }

    #[Test]
    public function testSetAndGet(): void
    {
        CollectorConfigHolder::set('test.key', ['value1', 'value2']);

        self::assertSame(['value1', 'value2'], CollectorConfigHolder::get('test.key'));
    }

    #[Test]
    public function testGetWithDefault(): void
    {
        self::assertNull(CollectorConfigHolder::get('nonexistent'));
        self::assertSame('fallback', CollectorConfigHolder::get('nonexistent', 'fallback'));
        self::assertSame([], CollectorConfigHolder::get('nonexistent', []));
    }

    #[Test]
    public function testReset(): void
    {
        CollectorConfigHolder::set('test.key', 'value');
        self::assertSame('value', CollectorConfigHolder::get('test.key'));

        CollectorConfigHolder::reset();

        self::assertNull(CollectorConfigHolder::get('test.key'));
    }

    #[Test]
    public function testAll(): void
    {
        CollectorConfigHolder::set('key1', 'value1');
        CollectorConfigHolder::set('key2', ['a', 'b']);

        $all = CollectorConfigHolder::all();

        self::assertSame([
            'key1' => 'value1',
            'key2' => ['a', 'b'],
        ], $all);
    }
}
