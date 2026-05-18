<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Violation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Violation\Location;

/**
 * Pins the nullable-file behaviour introduced by ADR 0015 Phase 1a.
 *
 * Architecture-level violations (e.g. circular dependency, project-level
 * diagnostics) have no owning file. The pre-Phase-1a sentinel for "no file"
 * was the empty string `''`. After Phase 1a it is `Location::file === null`
 * with `isNone()` / `pathString() === ''` as the API surface.
 */
#[CoversClass(Location::class)]
final class LocationNullFileTest extends TestCase
{
    #[Test]
    public function itLocationNoneCarriesNullFile(): void
    {
        $location = Location::none();

        self::assertNull($location->file);
        self::assertTrue($location->isNone());
        self::assertSame('', $location->pathString());
        self::assertSame('', $location->toString());
    }

    #[Test]
    public function itPathStringReturnsEmptyStringForNullFile(): void
    {
        $location = new Location(null, line: null);

        self::assertSame('', $location->pathString());
    }

    #[Test]
    public function itPathStringMirrorsRelativePathValueForNonNullFile(): void
    {
        $location = new Location(RelativePath::fromString('src/Foo.php'), line: 10);

        self::assertSame('src/Foo.php', $location->pathString());
    }

    #[Test]
    public function itToStringOmitsFileWhenLocationIsNone(): void
    {
        // Pre-Phase-1a, Location::none()->toString() returned the empty file
        // string. Post-Phase-1a the carrier is null; toString() must still
        // surface "" so wire/output consumers don't break.
        $location = Location::none();

        self::assertSame('', $location->toString());
    }

    #[Test]
    public function itToStringOmitsLineWhenFileIsNullEvenIfLineSet(): void
    {
        // Defensive: line without a file has no meaningful printable form.
        $location = new Location(null, line: 42);

        self::assertSame('', $location->toString());
    }
}
