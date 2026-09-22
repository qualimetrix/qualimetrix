<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionAddress;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Exclusion\ConfiguredSuppression;
use Qualimetrix\Core\Symbol\SymbolLevel;

#[CoversClass(RuleOptionSurface::class)]
#[CoversClass(RuleOptionAddress::class)]
#[CoversClass(FrameworkOptionKeys::class)]
final class RuleOptionSurfaceTest extends TestCase
{
    #[Test]
    public function itHasNoLevelsForARuleThatIsNotHierarchical(): void
    {
        $surface = RuleOptionSurface::of(FlatOptionsStub::class);

        self::assertSame([], $surface->levels());
        self::assertNull($surface->keySetAtLevel('class'));
        self::assertSame([], $surface->writableAt('class'));
    }

    #[Test]
    public function itNamesTheLevelSlotsInDeclarationOrder(): void
    {
        self::assertSame(['callable', 'class'], RuleOptionSurface::of(HierarchicalOptionsStub::class)->levels());
    }

    /**
     * The refusal walk asks this of every key a user wrote, so it has to fold
     * both sides: the walk used to compare a normalized written key against the
     * declared names with a raw `isset()`, which folds one side only.
     */
    #[Test]
    public function itAnswersToASlotNameUnderAnySpelling(): void
    {
        $surface = RuleOptionSurface::of(HierarchicalOptionsStub::class);

        self::assertSame('callable', $surface->levelNamed('callable'));
        self::assertNull($surface->levelNamed('namespace'));
        self::assertNotNull($surface->keySetAtLevel('class'));
        self::assertNull($surface->keySetAtLevel('namespace'));
    }

    /**
     * The slot name is written exactly where an option is, so it is legal at
     * depth 1 and the refusal prints it among the allowed keys. A listing that
     * gives each slot a line of its own subtracts it there; that subtraction is
     * presentation and deliberately does not live here.
     */
    #[Test]
    public function itCountsASlotNameAmongTheKeysWritableAtTheRulesOwnDepth(): void
    {
        $writable = RuleOptionSurface::of(HierarchicalOptionsStub::class)->writableAt(null);

        self::assertContains('callable', $writable);
        self::assertContains('class', $writable);
    }

    #[Test]
    public function itAddsTheFrameworkKeysAtTheRulesOwnDepthAndNeverInsideASlot(): void
    {
        $surface = RuleOptionSurface::of(HierarchicalOptionsStub::class);

        foreach (FrameworkOptionKeys::all() as $key) {
            self::assertContains($key, $surface->writableAt(null));
            self::assertNotContains($key, $surface->writableAt('class'));
        }
    }

    #[Test]
    public function itSortsWhatIsWritableAtEachDepth(): void
    {
        $surface = RuleOptionSurface::of(HierarchicalOptionsStub::class);

        foreach ([null, 'callable', 'class'] as $level) {
            $writable = $surface->writableAt($level);
            $sorted = $writable;
            sort($sorted);

            self::assertSame($sorted, $writable);
        }
    }

    /**
     * A key the class recognises only in order to answer about it in its own
     * words is neither writable nor locatable: naming it as allowed would
     * promise an answer this object does not give.
     */
    #[Test]
    public function itLeavesAKeyTheClassAnswersAboutItselfOutOfBothAnswers(): void
    {
        $surface = RuleOptionSurface::of(FlatOptionsStub::class);

        self::assertNotContains('enabled', $surface->writableAt(null));
        self::assertNull($surface->locate('enabled'));
    }

    #[Test]
    public function itPublishesAClassValidatedWritableKeyWithoutInventingAGenericShape(): void
    {
        $surface = RuleOptionSurface::of(FlatOptionsStub::class);

        self::assertContains('selector', $surface->writableAt(null));
        self::assertSame('selector', $surface->locate('selector')?->key);
        self::assertNull($surface->ownKeySet()->shapeOf('selector'));
    }

    #[Test]
    public function itLocatesABareTargetAtTheRulesOwnDepth(): void
    {
        $address = RuleOptionSurface::of(FlatOptionsStub::class)->locate('minLines');

        self::assertNotNull($address);
        self::assertNull($address->level);
        self::assertSame('min-lines', $address->key);
    }

    #[Test]
    public function itLocatesADottedTargetInsideItsSlot(): void
    {
        $address = RuleOptionSurface::of(HierarchicalOptionsStub::class)->locate('class.max_warning');

        self::assertNotNull($address);
        self::assertSame('class', $address->level);
        self::assertSame('max-warning', $address->key);
    }

    /**
     * The half of the alias join that used to be open-coded by the caller: an
     * alias target is written by hand in an attribute, so kebab, snake and
     * camel all reach this method and must land on the one declared spelling.
     */
    #[Test]
    public function itLocatesEverySpellingOfOneTargetAtOneAddress(): void
    {
        $surface = RuleOptionSurface::of(HierarchicalOptionsStub::class);

        foreach (['class.max-warning', 'class.max_warning', 'class.maxWarning'] as $spelling) {
            $address = $surface->locate($spelling);

            self::assertNotNull($address, $spelling);
            self::assertSame('class', $address->level);
            self::assertSame('max-warning', $address->key);
        }
    }

    #[Test]
    public function itLocatesNothingForATargetNobodyAccepts(): void
    {
        $surface = RuleOptionSurface::of(HierarchicalOptionsStub::class);

        self::assertNull($surface->locate('zzNotAnOption'));
        self::assertNull($surface->locate('class.zzNotAnOption'));
        self::assertNull($surface->locate('zzNotALevel.threshold'));
    }

    /**
     * A dotted target whose first segment names no slot is one key, not a
     * missing level: nothing in the grammar reserves a dot for depth, and
     * reading it as one would refuse a legal key on the strength of its
     * spelling.
     */
    #[Test]
    public function itReadsADotThatNamesNoSlotAsPartOfTheKey(): void
    {
        self::assertNull(RuleOptionSurface::of(FlatOptionsStub::class)->locate('min.lines'));
    }

    /**
     * The guard in `ConfiguredSuppression` has no production caller by design —
     * it states an invariant between two files rather than doing work — so this
     * is what executes it.
     */
    #[Test]
    public function itKeepsTheAuthoredSuppressionSpellingsInStepWithTheOwner(): void
    {
        ConfiguredSuppression::assertSpellingsMatchTheOwner();

        $this->expectNotToPerformAssertions();
    }
}

final class FlatOptionsStub implements RuleOptionsInterface
{
    public static function fromArray(array $config): self
    {
        return new self();
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return null;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'min-lines' => RuleOptionShape::text()->orNull(),
            'threshold' => RuleOptionShape::text()->orNull(),
        ])
            ->alsoAcceptedAndValidatedByTheClass('selector')
            ->alsoAnsweredByTheClass('enabled');
    }
}

final class HierarchicalOptionsStub implements HierarchicalRuleOptionsInterface
{
    public static function fromArray(array $config): self
    {
        return new self();
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return null;
    }

    public function forLevel(SymbolLevel $level): LevelOptionsInterface
    {
        return new ClassLevelOptionsStub();
    }

    public function isLevelEnabled(SymbolLevel $level): bool
    {
        return true;
    }

    public function getSupportedLevels(): array
    {
        return [SymbolLevel::Callable, SymbolLevel::Class_];
    }

    public static function levelOptionsClasses(): array
    {
        return ['callable' => CallableLevelOptionsStub::class, 'class' => ClassLevelOptionsStub::class];
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of(['threshold' => RuleOptionShape::text()->orNull()])
            ->withLevelSlots(self::levelOptionsClasses());
    }
}

final class CallableLevelOptionsStub implements LevelOptionsInterface
{
    public static function fromArray(array $config): self
    {
        return new self();
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return null;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'error' => RuleOptionShape::text()->orNull(),
            'warning' => RuleOptionShape::text()->orNull(),
        ]);
    }
}

final class ClassLevelOptionsStub implements LevelOptionsInterface
{
    public static function fromArray(array $config): self
    {
        return new self();
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return null;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'max-error' => RuleOptionShape::text()->orNull(),
            'max-warning' => RuleOptionShape::text()->orNull(),
        ]);
    }
}
