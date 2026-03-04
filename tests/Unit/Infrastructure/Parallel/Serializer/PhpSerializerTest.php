<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Parallel\Serializer;

use AiMessDetector\Infrastructure\Parallel\Serializer\PhpSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(PhpSerializer::class)]
final class PhpSerializerTest extends TestCase
{
    private PhpSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new PhpSerializer();
    }

    #[Test]
    public function itIsAlwaysAvailable(): void
    {
        self::assertTrue($this->serializer->isAvailable());
    }

    #[Test]
    public function itHasLowPriority(): void
    {
        self::assertSame(0, $this->serializer->getPriority());
    }

    #[Test]
    public function itSerializesAndUnserializesString(): void
    {
        $data = 'hello world';

        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertSame($data, $unserialized);
    }

    #[Test]
    public function itSerializesAndUnserializesArray(): void
    {
        $data = [
            'foo' => 'bar',
            'nested' => ['a' => 1, 'b' => 2],
            'numbers' => [1, 2, 3],
        ];

        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertSame($data, $unserialized);
    }

    #[Test]
    public function itSerializesAndUnserializesObject(): void
    {
        $data = new stdClass();
        $data->name = 'test';
        $data->value = 42;

        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertInstanceOf(stdClass::class, $unserialized);
        self::assertSame('test', $unserialized->name);
        self::assertSame(42, $unserialized->value);
    }

    #[Test]
    public function itSerializesAndUnserializesNull(): void
    {
        $data = null;

        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertNull($unserialized);
    }

    #[Test]
    public function itSerializesAndUnserializesFalse(): void
    {
        $data = false;

        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertFalse($unserialized);
    }

    #[Test]
    public function itSerializesAndUnserializesTrue(): void
    {
        $data = true;

        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertTrue($unserialized);
    }

    #[Test]
    public function itSerializesAndUnserializesInteger(): void
    {
        $data = 42;

        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertSame($data, $unserialized);
    }

    #[Test]
    public function itSerializesAndUnserializesFloat(): void
    {
        $data = 3.14159;

        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertSame($data, $unserialized);
    }

    #[Test]
    public function itThrowsOnInvalidSerializedData(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to unserialize data');

        $this->serializer->unserialize('invalid serialized data');
    }

    #[Test]
    public function itHandlesEmptyArray(): void
    {
        $data = [];

        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertSame($data, $unserialized);
    }

    #[Test]
    public function itHandlesComplexNestedStructure(): void
    {
        $data = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'value' => 'deep',
                        'number' => 123,
                    ],
                ],
            ],
            'objects' => [
                (object) ['id' => 1],
                (object) ['id' => 2],
            ],
        ];

        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertEquals($data, $unserialized);
    }
}
