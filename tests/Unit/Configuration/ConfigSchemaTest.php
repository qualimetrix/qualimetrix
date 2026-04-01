<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\ConfigSchema;
use ReflectionClass;

#[CoversClass(ConfigSchema::class)]
final class ConfigSchemaTest extends TestCase
{
    #[Test]
    public function allowedRootKeysContainsAllExpectedKeys(): void
    {
        $keys = ConfigSchema::allowedRootKeys();

        // Top-level direct keys
        self::assertContains('paths', $keys);
        self::assertContains('exclude', $keys);
        self::assertContains('format', $keys);
        self::assertContains('rules', $keys);
        self::assertContains('failOn', $keys);

        // Section keys (derived from dotted entries)
        self::assertContains('cache', $keys);
        self::assertContains('namespace', $keys);
        self::assertContains('aggregation', $keys);
        self::assertContains('coupling', $keys);
        self::assertContains('parallel', $keys);

        // Dual-naming alternatives
        self::assertContains('computedMetrics', $keys);
        self::assertContains('computed_metrics', $keys);
        self::assertContains('memoryLimit', $keys);
        self::assertContains('memory_limit', $keys);
    }

    #[Test]
    public function sectionKeysIncludesDottedRoots(): void
    {
        $sections = ConfigSchema::sectionKeys();

        self::assertContains('cache', $sections);
        self::assertContains('namespace', $sections);
        self::assertContains('aggregation', $sections);
        self::assertContains('coupling', $sections);
        self::assertContains('parallel', $sections);

        // These are NOT sections
        self::assertNotContains('rules', $sections);
        self::assertNotContains('paths', $sections);
        self::assertNotContains('format', $sections);
    }

    #[Test]
    public function listKeysReturnsOnlyLists(): void
    {
        $lists = ConfigSchema::listKeys();

        self::assertContains('paths', $lists);
        self::assertContains('exclude', $lists);
        self::assertContains('disabledRules', $lists);
        self::assertContains('onlyRules', $lists);
        self::assertContains('excludePaths', $lists);
        self::assertContains('excludeHealth', $lists);

        // These are NOT lists
        self::assertNotContains('rules', $lists);
        self::assertNotContains('cache', $lists);
        self::assertNotContains('format', $lists);
    }

    #[Test]
    public function sectionAndListKeysDoNotOverlap(): void
    {
        $sections = ConfigSchema::sectionKeys();
        $lists = ConfigSchema::listKeys();

        self::assertSame([], array_intersect($sections, $lists));
    }

    #[Test]
    public function allTypedKeysAreInAllowedRootKeys(): void
    {
        $allowed = ConfigSchema::allowedRootKeys();

        foreach (ConfigSchema::sectionKeys() as $section) {
            self::assertContains($section, $allowed, "Section key '{$section}' not in allowed root keys");
        }
        foreach (ConfigSchema::listKeys() as $list) {
            self::assertContains($list, $allowed, "List key '{$list}' not in allowed root keys");
        }
    }

    #[Test]
    public function everyEntryHasMatchingConstant(): void
    {
        $reflection = new ReflectionClass(ConfigSchema::class);
        $constantValues = array_values($reflection->getConstants());

        foreach (ConfigSchema::ENTRIES as [, $resultKey]) {
            self::assertContains(
                $resultKey,
                $constantValues,
                "Result key '{$resultKey}' has no matching constant in ConfigSchema",
            );
        }
    }
}
