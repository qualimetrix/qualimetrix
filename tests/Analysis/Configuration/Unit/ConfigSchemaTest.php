<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;

#[CoversClass(ConfigSchema::class)]
final class ConfigSchemaTest extends TestCase
{
    #[Test]
    public function itListsAllExpectedRootKeys(): void
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
        self::assertNotContains('namespace', $keys);
        self::assertNotContains('aggregation', $keys);
        self::assertContains('coupling', $keys);
        self::assertContains('parallel', $keys);

        // Owner document roots retain their canonical spelling; legacy leaves are camelCase.
        self::assertContains('computed_metrics', $keys);
        self::assertContains('memoryLimit', $keys);
        self::assertContains('exclude_health', $keys);
        self::assertContains('includeGenerated', $keys);
        self::assertContains(ConfigSchema::COUPLING, ConfigSchema::DOCUMENT_ROOTS);
    }

    #[Test]
    public function itIncludesDottedRootsAmongSectionKeys(): void
    {
        $sections = ConfigSchema::sectionKeys();

        self::assertContains('cache', $sections);
        self::assertNotContains('namespace', $sections);
        self::assertNotContains('aggregation', $sections);
        self::assertContains('coupling', $sections);
        self::assertContains('parallel', $sections);

        // These are NOT sections
        self::assertNotContains('rules', $sections);
        self::assertNotContains('paths', $sections);
        self::assertNotContains('format', $sections);
    }

    #[Test]
    public function itReturnsOnlyListTypeKeys(): void
    {
        $lists = ConfigSchema::listKeys();

        self::assertContains('paths', $lists);
        self::assertContains('exclude', $lists);
        self::assertContains('disabledRules', $lists);
        self::assertContains('onlyRules', $lists);
        self::assertContains('suppressPaths', $lists);
        self::assertContains('exclude_health', $lists);

        // These are NOT lists
        self::assertNotContains('rules', $lists);
        self::assertNotContains('cache', $lists);
        self::assertNotContains('format', $lists);
    }

}
