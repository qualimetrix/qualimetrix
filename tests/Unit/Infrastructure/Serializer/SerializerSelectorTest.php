<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Serializer;

use AiMessDetector\Infrastructure\Serializer\IgbinarySerializer;
use AiMessDetector\Infrastructure\Serializer\PhpSerializer;
use AiMessDetector\Infrastructure\Serializer\SerializerInterface;
use AiMessDetector\Infrastructure\Serializer\SerializerSelector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SerializerSelector::class)]
final class SerializerSelectorTest extends TestCase
{
    #[Test]
    public function itSelectsHighestPriorityAvailable(): void
    {
        $lowPriority = $this->createMock(SerializerInterface::class);
        $lowPriority->method('isAvailable')->willReturn(true);
        $lowPriority->method('getPriority')->willReturn(10);

        $highPriority = $this->createMock(SerializerInterface::class);
        $highPriority->method('isAvailable')->willReturn(true);
        $highPriority->method('getPriority')->willReturn(100);

        $mediumPriority = $this->createMock(SerializerInterface::class);
        $mediumPriority->method('isAvailable')->willReturn(true);
        $mediumPriority->method('getPriority')->willReturn(50);

        $selector = new SerializerSelector([
            $lowPriority,
            $highPriority,
            $mediumPriority,
        ]);

        $selected = $selector->select();

        self::assertSame($highPriority, $selected);
    }

    #[Test]
    public function itSkipsUnavailableSerializers(): void
    {
        $unavailable = $this->createMock(SerializerInterface::class);
        $unavailable->method('isAvailable')->willReturn(false);
        $unavailable->method('getPriority')->willReturn(100);

        $available = $this->createMock(SerializerInterface::class);
        $available->method('isAvailable')->willReturn(true);
        $available->method('getPriority')->willReturn(50);

        $selector = new SerializerSelector([
            $unavailable,
            $available,
        ]);

        $selected = $selector->select();

        self::assertSame($available, $selected);
    }

    #[Test]
    public function itThrowsExceptionWhenNoSerializersAvailable(): void
    {
        $unavailable1 = $this->createMock(SerializerInterface::class);
        $unavailable1->method('isAvailable')->willReturn(false);

        $unavailable2 = $this->createMock(SerializerInterface::class);
        $unavailable2->method('isAvailable')->willReturn(false);

        $selector = new SerializerSelector([
            $unavailable1,
            $unavailable2,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No serializer available');

        $selector->select();
    }

    #[Test]
    public function itThrowsExceptionWhenEmptyArray(): void
    {
        $selector = new SerializerSelector([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No serializer available');

        $selector->select();
    }

    #[Test]
    public function itCreatesDefaultWithBothSerializers(): void
    {
        $selector = SerializerSelector::createDefault();
        $selected = $selector->select();

        self::assertInstanceOf(SerializerInterface::class, $selected);

        // If igbinary is available - it will be selected (priority 100)
        // Otherwise PhpSerializer will be selected (priority 0)
        if (\extension_loaded('igbinary')) {
            self::assertInstanceOf(IgbinarySerializer::class, $selected);
        } else {
            self::assertInstanceOf(PhpSerializer::class, $selected);
        }
    }

    #[Test]
    public function itAlwaysHasAtLeastPhpSerializerInDefault(): void
    {
        $selector = SerializerSelector::createDefault();

        // PhpSerializer is always available, so select() should not throw an exception
        $selected = $selector->select();

        self::assertTrue($selected->isAvailable());
    }

    #[Test]
    public function itHandlesEqualPriorities(): void
    {
        $serializer1 = $this->createMock(SerializerInterface::class);
        $serializer1->method('isAvailable')->willReturn(true);
        $serializer1->method('getPriority')->willReturn(50);

        $serializer2 = $this->createMock(SerializerInterface::class);
        $serializer2->method('isAvailable')->willReturn(true);
        $serializer2->method('getPriority')->willReturn(50);

        $selector = new SerializerSelector([
            $serializer1,
            $serializer2,
        ]);

        $selected = $selector->select();

        // With equal priority, one of them should be selected
        self::assertContains($selected, [$serializer1, $serializer2]);
    }
}
