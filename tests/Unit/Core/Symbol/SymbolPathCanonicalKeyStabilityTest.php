<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Pins the SymbolPath canonical-key string format across the ADR 0015 migration.
 *
 * Baselines persist canonical-key strings to disk; any change to the format
 * silently invalidates user baselines on upgrade. This test asserts the format
 * is byte-stable using hard-coded `assertSame` pairs (round-2 reviewer note F8:
 * avoid golden-file complexity for a small fixed-shape surface).
 *
 * `forFile()` is the migration target — its argument changed from `string` to
 * `RelativePath` in Phase 1c. The canonical-key format `"file:" . $path->value()`
 * must remain identical to the pre-migration `"file:" . $stringPath` output.
 */
#[CoversClass(SymbolPath::class)]
final class SymbolPathCanonicalKeyStabilityTest extends TestCase
{
    #[Test]
    public function itEmitsStableCanonicalKeyForFileSymbols(): void
    {
        self::assertSame(
            'file:src/Foo.php',
            SymbolPath::forFile(RelativePath::fromString('src/Foo.php'))->toCanonical(),
        );

        self::assertSame(
            'file:src/Service/UserService.php',
            SymbolPath::forFile(RelativePath::fromString('src/Service/UserService.php'))->toCanonical(),
        );

        // Deeply nested
        self::assertSame(
            'file:src/Foo/Bar/Baz/Qux/Quux.php',
            SymbolPath::forFile(RelativePath::fromString('src/Foo/Bar/Baz/Qux/Quux.php'))->toCanonical(),
        );

        // Single-segment (no directory)
        self::assertSame(
            'file:bootstrap.php',
            SymbolPath::forFile(RelativePath::fromString('bootstrap.php'))->toCanonical(),
        );

        // Dotfile basename
        self::assertSame(
            'file:.phpunit.cache',
            SymbolPath::forFile(RelativePath::fromString('.phpunit.cache'))->toCanonical(),
        );
    }

    #[Test]
    public function itPreservesPathSegmentSeparatorInCanonicalKey(): void
    {
        // RelativePath normalizes `./` away, but the canonical key reflects the normalized form
        self::assertSame(
            'file:src/Foo.php',
            SymbolPath::forFile(RelativePath::fromString('./src/Foo.php'))->toCanonical(),
        );

        // Windows-style separators are normalized to '/' inside RelativePath; canonical key shows '/'
        self::assertSame(
            'file:src/Foo.php',
            SymbolPath::forFile(RelativePath::fromString('src\\Foo.php'))->toCanonical(),
        );
    }

    #[Test]
    public function itStillEmitsStableCanonicalKeysForOtherSymbolTypes(): void
    {
        // Class
        self::assertSame(
            'class:App\\Service\\UserService',
            SymbolPath::forClass('App\\Service', 'UserService')->toCanonical(),
        );

        // Class in global namespace
        self::assertSame(
            'class:GlobalClass',
            SymbolPath::forClass('', 'GlobalClass')->toCanonical(),
        );

        // Method
        self::assertSame(
            'method:App\\Service\\UserService::calculate',
            SymbolPath::forMethod('App\\Service', 'UserService', 'calculate')->toCanonical(),
        );

        // Namespace
        self::assertSame(
            'ns:App\\Service',
            SymbolPath::forNamespace('App\\Service')->toCanonical(),
        );

        // Empty (global) namespace
        self::assertSame(
            'ns:',
            SymbolPath::forNamespace('')->toCanonical(),
        );

        // Function
        self::assertSame(
            'func:App\\Util::helper',
            SymbolPath::forGlobalFunction('App\\Util', 'helper')->toCanonical(),
        );

        // Function in global namespace
        self::assertSame(
            'func::globalFunction',
            SymbolPath::forGlobalFunction('', 'globalFunction')->toCanonical(),
        );

        // Project
        self::assertSame(
            'project:',
            SymbolPath::forProject()->toCanonical(),
        );
    }
}
