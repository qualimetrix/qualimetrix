<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\FileTarget\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\FileTarget\DirectoryFacts;
use Qualimetrix\Core\FileTarget\EntryControl;
use Qualimetrix\Core\FileTarget\EntryFacts;

#[CoversClass(EntryControl::class)]
final class EntryControlTest extends TestCase
{
    #[Test]
    public function itTreatsStickyAsProtectionAgainstReplacementButNotPlacement(): void
    {
        $control = EntryControl::of(new DirectoryFacts(1000, 1000, 041777), new EntryFacts(1000, 0100000), 1000);

        self::assertTrue($control->placeableByOthers);
        self::assertFalse($control->swappableByOthers);
        self::assertSame('others', $control->changedBy);
    }

    #[Test]
    public function itTreatsDirectoriesAsUnplaceableWhenStickyProtectsThem(): void
    {
        $control = EntryControl::of(new DirectoryFacts(1000, 1000, 041777), new EntryFacts(1000, 0040000), 1000);

        self::assertFalse($control->placeableByOthers);
        self::assertFalse($control->swappableByOthers);
    }

    #[Test]
    public function itExposesLinksInGroupWritableAndForeignDirectoriesEvenForRoot(): void
    {
        $group = EntryControl::of(new DirectoryFacts(0, 2000, 042775), new EntryFacts(0, 0120000), 1000);
        $foreign = EntryControl::of(new DirectoryFacts(2000, 2000, 040755), new EntryFacts(0, 0120000), 0);

        self::assertTrue($group->placeableByOthers);
        self::assertTrue($foreign->placeableByOthers);
        self::assertNull($foreign->forTrace('/foreign', true));
    }

    #[Test]
    public function itLeavesLinksInOwnedAndRootOwnedClosedDirectoriesUnexposed(): void
    {
        $owned = EntryControl::of(new DirectoryFacts(1000, 1000, 040755), new EntryFacts(2000, 0120000), 1000);
        $root = EntryControl::of(new DirectoryFacts(0, 0, 040755), new EntryFacts(2000, 0120000), 1000);

        self::assertFalse($owned->placeableByOthers);
        self::assertFalse($root->placeableByOthers);
    }
}
