<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Serializer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Serializer\IgbinarySerializer;
use RuntimeException;
use stdClass;

#[CoversClass(IgbinarySerializer::class)]
#[RequiresPhpExtension('igbinary')]
final class IgbinarySerializerTest extends TestCase
{
    private IgbinarySerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new IgbinarySerializer();
    }

    #[Test]
    public function itReturnsName(): void
    {
        self::assertSame('igbinary', $this->serializer->getName());
    }

    #[Test]
    public function itIsAvailable(): void
    {
        // Test runs only if the extension is available
        self::assertTrue($this->serializer->isAvailable());
    }

    #[Test]
    public function itHasHighPriority(): void
    {
        // High priority - used by default
        self::assertSame(100, $this->serializer->getPriority());
    }

    #[Test]
    public function itSerializesAndUnserializesScalar(): void
    {
        $data = 42;
        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertSame($data, $unserialized);
    }

    #[Test]
    public function itSerializesAndUnserializesArray(): void
    {
        $data = ['foo' => 'bar', 'nested' => ['a' => 1, 'b' => 2]];
        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertSame($data, $unserialized);
    }

    #[Test]
    public function itSerializesAndUnserializesObject(): void
    {
        $data = new stdClass();
        $data->property = 'value';
        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertEquals($data, $unserialized);
    }

    #[Test]
    public function itHandlesComplexDataStructure(): void
    {
        $data = [
            'string' => 'test',
            'int' => 123,
            'float' => 45.67,
            'bool' => true,
            'null' => null,
            'array' => [1, 2, 3],
            'object' => (object) ['key' => 'value'],
        ];

        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertEquals($data, $unserialized);
    }

    #[Test]
    public function itProducesSmallerOutputThanPhpSerialize(): void
    {
        $data = array_fill(0, 100, 'test string value');

        $igbinarySerialized = $this->serializer->serialize($data);
        $phpSerialized = serialize($data);

        self::assertLessThan(
            \strlen($phpSerialized),
            \strlen($igbinarySerialized),
            'Igbinary serialization should produce smaller output than PHP serialize',
        );
    }

    #[Test]
    public function itThrowsOnInvalidData(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Igbinary unserialization failed');

        $this->serializer->unserialize('invalid igbinary data');
    }

    #[Test]
    public function itHandlesFalseValue(): void
    {
        $data = false;
        $serialized = $this->serializer->serialize($data);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertFalse($unserialized);
    }

    #[Test]
    public function itHandlesNullValue(): void
    {
        $serialized = $this->serializer->serialize(null);
        $unserialized = $this->serializer->unserialize($serialized);

        self::assertNull($unserialized);
    }
}
