<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\Channel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameMap;

/**
 * The tracked map of this repository is a live input of the carry, not
 * only of the gate: a row that lands in it has to be one the shipped
 * command can read.
 *
 * The other half of this subject —
 * {@see \Qualimetrix\Tests\Analysis\Policy\Baseline\Unit\ChannelRenameMapTest} —
 * tests `ChannelRenameMap` itself against a hand-built and a shared corpus
 * and stays a product test.
 */
#[CoversClass(ChannelRenameMap::class)]
final class ChannelRenameMapTest extends TestCase
{
    #[Test]
    public function itReadsTheRepositorysOwnDeclaredChannelMap(): void
    {
        $map = ChannelRenameMap::fromFile(\dirname(__DIR__, 2) . '/finding-gate/maps/channels.tsv');

        self::assertCount(\count($map->oldNames()), $map->renames);

        foreach ($map->renames as $old => $new) {
            self::assertNotSame('', $old);
            self::assertNotSame('', $new);
        }
    }
}
