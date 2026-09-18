<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Serializer\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Serializer\IgbinarySerializer;
use Qualimetrix\Infrastructure\Serializer\PhpSerializer;
use Qualimetrix\Infrastructure\Serializer\SerializerInterface;
use Qualimetrix\Infrastructure\Serializer\SerializerSelector;
use RuntimeException;

#[CoversClass(SerializerSelector::class)]
final class SerializerSelectorTest extends TestCase
{
    #[Test]
    public function itSelectsHighestPriorityAvailable(): void
    {
        $lowPriority = self::createStub(SerializerInterface::class);
        $lowPriority->method('isAvailable')->willReturn(true);
        $lowPriority->method('getPriority')->willReturn(10);

        $highPriority = self::createStub(SerializerInterface::class);
        $highPriority->method('isAvailable')->willReturn(true);
        $highPriority->method('getPriority')->willReturn(100);

        $mediumPriority = self::createStub(SerializerInterface::class);
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
        $unavailable = self::createStub(SerializerInterface::class);
        $unavailable->method('isAvailable')->willReturn(false);
        $unavailable->method('getPriority')->willReturn(100);

        $available = self::createStub(SerializerInterface::class);
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
        $unavailable1 = self::createStub(SerializerInterface::class);
        $unavailable1->method('isAvailable')->willReturn(false);

        $unavailable2 = self::createStub(SerializerInterface::class);
        $unavailable2->method('isAvailable')->willReturn(false);

        $selector = new SerializerSelector([
            $unavailable1,
            $unavailable2,
        ]);

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('No serializer available');

        $selector->select();
    }

    #[Test]
    public function itThrowsExceptionWhenEmptyArray(): void
    {
        $selector = new SerializerSelector([]);

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('No serializer available');

        $selector->select();
    }

    #[Test]
    public function itCreatesDefaultWithBothSerializers(): void
    {
        $selector = SerializerSelector::createDefault();
        $selected = $selector->select();

        self::assertInstanceOf(SerializerInterface::class, $selected); // @phpstan-ignore staticMethod.alreadyNarrowedType

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

    /**
     * Equal priorities are only about the tie-break, and asserting that one of
     * the two came back leaves exactly that unchecked. PHP's sort is stable,
     * so the tie goes to the earlier entry; the second half swaps the two so a
     * winner picked by identity rather than by position is caught.
     */
    #[Test]
    public function itBreaksAPriorityTieByRegistrationOrder(): void
    {
        $first = $this->availableSerializerWithPriority(50);
        $second = $this->availableSerializerWithPriority(50);

        self::assertSame($first, (new SerializerSelector([$first, $second]))->select());
        self::assertSame($second, (new SerializerSelector([$second, $first]))->select());
    }

    private function availableSerializerWithPriority(int $priority): SerializerInterface
    {
        $serializer = self::createStub(SerializerInterface::class);
        $serializer->method('isAvailable')->willReturn(true);
        $serializer->method('getPriority')->willReturn($priority);

        return $serializer;
    }
}
