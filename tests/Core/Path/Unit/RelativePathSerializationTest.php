<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Path;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;

#[CoversClass(RelativePath::class)]
final class RelativePathSerializationTest extends TestCase
{
    #[Test]
    public function itRoundTripsThroughPhpSerialize(): void
    {
        $original = RelativePath::fromString('src/Foo.php');
        $restored = unserialize(serialize($original));

        self::assertInstanceOf(RelativePath::class, $restored);
        self::assertTrue($original->equals($restored));
        self::assertSame('src/Foo.php', $restored->value());
    }

    #[Test]
    public function itPinsWireFormatToValueKey(): void
    {
        $original = RelativePath::fromString('a/b');

        self::assertSame(['value' => 'a/b'], $original->__serialize());
    }

    #[Test]
    public function itEmitsValueKeyInSerializedPayload(): void
    {
        $payload = serialize(RelativePath::fromString('src/Foo.php'));

        self::assertStringContainsString('s:5:"value"', $payload);
        self::assertStringContainsString('s:11:"src/Foo.php"', $payload);
    }

    #[Test]
    #[RequiresPhpExtension('igbinary')]
    public function itRoundTripsThroughIgbinarySerialize(): void
    {
        $original = RelativePath::fromString('src/Foo.php');
        $blob = igbinary_serialize($original);
        self::assertIsString($blob);

        $restored = igbinary_unserialize($blob);

        self::assertInstanceOf(RelativePath::class, $restored);
        self::assertTrue($original->equals($restored));
    }
}
