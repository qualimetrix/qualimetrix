<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationResolver;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

/**
 * A `cache.dir` of the wrong shape was dropped by an `is_string` guard, and the
 * run then wrote into `.qmx-cache` while reporting as though it had honoured
 * the configured path.
 */
final class CacheDirectoryShapeRefusalTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function provideUnexecutableValues(): iterable
    {
        yield 'boolean' => [true];
        yield 'integer' => [7331];
        yield 'float' => [7331.9];
        yield 'list' => [['probe-cache']];
        yield 'map' => [['a' => 'probe-cache']];
        yield 'empty string' => [''];
    }

    #[Test]
    #[DataProvider('provideUnexecutableValues')]
    public function itRefusesADirectoryTheRunCannotUse(mixed $value): void
    {
        try {
            self::resolve($value);
            self::fail('An unusable cache directory must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertCount(1, $refusal->sources());
            self::assertSame(ConfigurationSource::CommandLine, $refusal->sources()[0]->source());
            self::assertNull($refusal->position());
        }
    }

    #[Test]
    public function itStillHonoursAWritableDirectory(): void
    {
        $directory = sys_get_temp_dir() . '/qmx-cache-shape-' . bin2hex(random_bytes(6));

        try {
            self::assertSame($directory, self::resolve($directory));
        } finally {
            @rmdir($directory);
        }
    }

    #[Test]
    public function itStillDefaultsWhenNobodyNamedADirectory(): void
    {
        $document = LayeredDocument::of([], AbsolutePath::fromString(sys_get_temp_dir()));
        $resolved = (new CacheConfigurationResolver())->resolve($document, AbsolutePath::fromString(sys_get_temp_dir()));

        self::assertStringEndsWith('.qmx-cache', $resolved->directory->value());
        @rmdir($resolved->directory->value());
    }

    private static function resolve(mixed $value): string
    {
        $root = AbsolutePath::fromString(sys_get_temp_dir());
        $document = LayeredDocument::of(
            [['source' => 'cli', 'values' => ['cache.dir' => $value]]],
            $root,
        );

        return (new CacheConfigurationResolver())->resolve($document, $root)->directory->value();
    }
}
