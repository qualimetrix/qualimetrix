<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\FileTarget\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\FileTarget\DirectoryFacts;
use Qualimetrix\Core\FileTarget\EntryControl;
use Qualimetrix\Core\FileTarget\EntryFacts;
use Qualimetrix\Core\FileTarget\PrivateGroupMembership;

#[CoversClass(EntryControl::class)]
final class EntryControlTest extends TestCase
{
    #[Test]
    public function itDoesNotExposeALinkInTheOwnersPrivatePrimaryGroup(): void
    {
        $control = EntryControl::of(new DirectoryFacts(1000, 1000, 040775), new EntryFacts(1000, 0120000), 1000, self::membership(true));

        self::assertFalse($control->placeableByOthers);
        self::assertFalse($control->swappableByOthers);
        self::assertNull($control->forTrace('/srv/owner', 1000));
    }

    #[Test]
    public function itConservativelyExposesAnUnverifiedGroup(): void
    {
        $control = EntryControl::of(new DirectoryFacts(1000, 1000, 040775), new EntryFacts(1000, 0120000), 1000, self::membership(false));

        self::assertTrue($control->placeableByOthers);
        self::assertTrue($control->swappableByOthers);
        self::assertSame('group 1000', $control->changedBy);
    }

    #[Test]
    public function itPreservesWorldWriteForeignOwnerAndStickyJudgements(): void
    {
        $private = self::membership(true);
        $world = EntryControl::of(new DirectoryFacts(1000, 1000, 040777), new EntryFacts(1000, 0120000), 1000, $private);
        $foreign = EntryControl::of(new DirectoryFacts(2000, 1000, 040775), new EntryFacts(1000, 0120000), 1000, $private);
        $sticky = EntryControl::of(new DirectoryFacts(1000, 1000, 041777), new EntryFacts(1000, 0100000), 1000, $private);

        self::assertTrue($world->placeableByOthers);
        self::assertSame('others', $world->changedBy);
        self::assertTrue($foreign->placeableByOthers);
        self::assertSame('user 2000', $foreign->changedBy);
        self::assertTrue($sticky->placeableByOthers);
        self::assertFalse($sticky->swappableByOthers);
    }

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
        self::assertNull($foreign->forTrace('/foreign', 0));
    }

    #[Test]
    public function itLeavesLinksInOwnedAndRootOwnedClosedDirectoriesUnexposed(): void
    {
        $owned = EntryControl::of(new DirectoryFacts(1000, 1000, 040755), new EntryFacts(2000, 0120000), 1000);
        $root = EntryControl::of(new DirectoryFacts(0, 0, 040755), new EntryFacts(2000, 0120000), 1000);

        self::assertFalse($owned->placeableByOthers);
        self::assertFalse($root->placeableByOthers);
    }

    private static function membership(bool $private): PrivateGroupMembership
    {
        return new class ($private) implements PrivateGroupMembership {
            public function __construct(private readonly bool $private) {}

            public function isPrivatePrimaryGroup(int $effectiveUid, int $groupId): bool
            {
                return $this->private;
            }
        };
    }
}
