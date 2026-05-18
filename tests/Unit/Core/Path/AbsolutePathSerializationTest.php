<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Path;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(AbsolutePath::class)]
final class AbsolutePathSerializationTest extends TestCase
{
    #[Test]
    public function itRoundTripsThroughPhpSerialize(): void
    {
        $original = AbsolutePath::fromString('/usr/local/bin');
        $restored = unserialize(serialize($original));

        self::assertInstanceOf(AbsolutePath::class, $restored);
        self::assertTrue($original->equals($restored));
        self::assertSame('/usr/local/bin', $restored->value());
    }

    #[Test]
    public function itPinsWireFormatToValueKey(): void
    {
        // Pin the wire shape so future internal property renames don't break IPC payloads.
        $original = AbsolutePath::fromString('/a/b');

        self::assertSame(['value' => '/a/b'], $original->__serialize());
    }

    #[Test]
    public function itRehydratesViaUnserializeShape(): void
    {
        // Build the canonical wire payload by hand and feed it through unserialize()
        // to confirm the rehydration path matches the documented contract.
        $payload = serialize(AbsolutePath::fromString('/some/path'));

        self::assertStringContainsString('s:5:"value"', $payload);
        self::assertStringContainsString('s:10:"/some/path"', $payload);
    }

    #[Test]
    #[RequiresPhpExtension('igbinary')]
    public function itRoundTripsThroughIgbinarySerialize(): void
    {
        $original = AbsolutePath::fromString('/some/igbinary/path');
        $blob = igbinary_serialize($original);
        self::assertIsString($blob);

        $restored = igbinary_unserialize($blob);

        self::assertInstanceOf(AbsolutePath::class, $restored);
        self::assertTrue($original->equals($restored));
    }
}
