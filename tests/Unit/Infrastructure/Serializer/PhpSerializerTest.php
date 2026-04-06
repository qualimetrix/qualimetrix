<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Serializer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Serializer\PhpSerializer;
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
    public function itReturnsName(): void
    {
        self::assertSame('php', $this->serializer->getName());
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
    public function itDeserializesObjects(): void
    {
        // PhpSerializer allows objects (needed for AST cache with PhpParser nodes).
        $data = new stdClass();
        $data->name = 'test';
        $data->value = 42;

        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertInstanceOf(stdClass::class, $unserialized);
        self::assertSame('test', $unserialized->name);
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
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Failed to unserialize data');

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
    public function itHandlesComplexNestedScalarStructure(): void
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
            'items' => [
                ['id' => 1],
                ['id' => 2],
            ],
        ];

        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertSame($data, $unserialized);
    }

    #[Test]
    public function itDeserializesObjectsFullyByDefault(): void
    {
        // PhpSerializer allows all classes (needed for AST cache).
        $obj = new stdClass();
        $obj->name = 'test';
        $serialized = serialize($obj);

        $result = $this->serializer->unserialize($serialized);

        self::assertInstanceOf(stdClass::class, $result);
        self::assertSame('test', $result->name);
    }

    #[Test]
    public function itDeserializesScalarArraysCorrectly(): void
    {
        // Metrics are scalar arrays - should work fine with allowed_classes=false
        $metrics = ['ccn' => 5, 'loc' => 100, 'ratio' => 0.85, 'name' => 'test'];
        $serialized = serialize($metrics);

        $result = $this->serializer->unserialize($serialized);

        self::assertSame($metrics, $result);
    }
}
