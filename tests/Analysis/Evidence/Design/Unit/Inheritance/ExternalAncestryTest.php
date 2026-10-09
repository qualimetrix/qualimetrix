<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit\Inheritance;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract\ExternalParentSourceInterface;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract\ParentLookup;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\ExternalAncestry;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\ExternalChainOutcome;
use Qualimetrix\Tests\Analysis\Evidence\Design\Support\FixedParentSource;

/**
 * What counts as a depth, given what the sources say. Reading those sources is
 * the adapter's job and is covered where that lives.
 */
#[CoversClass(ExternalAncestry::class)]
final class ExternalAncestryTest extends TestCase
{
    #[Test]
    public function itCountsEveryStepToTheRoot(): void
    {
        $depth = $this->ancestry([
            'Vendor\\Leaf' => 'Vendor\\Middle',
            'Vendor\\Middle' => 'Vendor\\Base',
            'Vendor\\Base' => null,
        ])->depthOf('Vendor\\Leaf');

        self::assertSame(2, $depth->depth);
        self::assertSame(ExternalChainOutcome::ReachedRoot, $depth->outcome);
        self::assertTrue($depth->isComplete());
    }

    #[Test]
    public function itScoresAClassWithNoParentAsZero(): void
    {
        $depth = $this->ancestry(['Vendor\\Base' => null])->depthOf('Vendor\\Base');

        self::assertSame(0, $depth->depth);
        self::assertSame(ExternalChainOutcome::ReachedRoot, $depth->outcome);
    }

    #[Test]
    public function itFollowsEveryBuiltinParent(): void
    {
        $depth = $this->ancestry(['Vendor\\Failure' => 'RuntimeException'])->depthOf('Vendor\\Failure');

        self::assertSame(2, $depth->depth);
        self::assertSame(ExternalChainOutcome::ReachedRoot, $depth->outcome);
    }

    /**
     * The state the metric never had: part of the chain was followed and the
     * rest is unreadable. That is neither "no parent" nor "nothing to read",
     * and reporting all three as depth 0 is what hid it.
     */
    #[Test]
    public function itReportsAChainThatStopsBeingReadable(): void
    {
        $depth = $this->ancestry(['Vendor\\Child' => 'Absent\\Parental'])->depthOf('Vendor\\Child');

        self::assertSame(1, $depth->depth);
        self::assertSame(ExternalChainOutcome::BrokeAt, $depth->outcome);
        self::assertSame('Absent\\Parental', $depth->unresolved);
        self::assertFalse($depth->isComplete());
    }

    #[Test]
    public function itSeparatesHavingNoInstallFromHavingNoEntry(): void
    {
        $noInstall = (new ExternalAncestry(FixedParentSource::unconfigured()))->depthOf('Vendor\\Anything');
        $noEntry = $this->ancestry([])->depthOf('Vendor\\Anything');

        self::assertSame(ExternalChainOutcome::NoMapForIt, $noInstall->outcome);
        self::assertSame(ExternalChainOutcome::BrokeAt, $noEntry->outcome);
    }

    #[Test]
    public function itRefusesToCallACycleADepth(): void
    {
        $depth = $this->ancestry([
            'Vendor\\A' => 'Vendor\\B',
            'Vendor\\B' => 'Vendor\\A',
        ])->depthOf('Vendor\\A');

        self::assertSame(ExternalChainOutcome::Loop, $depth->outcome);
        self::assertNull($depth->depth);
    }

    #[Test]
    public function itAnswersTheSameWayTwice(): void
    {
        $ancestry = $this->ancestry(['Vendor\\Child' => 'Vendor\\Base', 'Vendor\\Base' => null]);

        self::assertEquals($ancestry->depthOf('Vendor\\Child'), $ancestry->depthOf('Vendor\\Child'));
    }

    #[Test]
    public function itFollowsBuiltinAncestryWithoutAnInstall(): void
    {
        $ancestry = new ExternalAncestry(FixedParentSource::unconfigured());
        self::assertSame(1, $ancestry->depthOf('runtimeexception')->depth);
        self::assertSame(2, $ancestry->depthOf('ArgumentCountError')->depth);
    }

    #[Test]
    public function itOmitsNumericDepthForACanonicalExternalLoop(): void
    {
        $depth = $this->ancestry(['Vendor\\A' => 'Vendor\\B', 'Vendor\\B' => 'vendor\\a'])->depthOf('Vendor\\A');
        self::assertNull($depth->depth);
    }

    #[Test]
    public function itBoundsStaticSourceWalksAtSixtyFourVisits(): void
    {
        $parents = [];
        for ($i = 0; $i < 80; ++$i) {
            $parents['Vendor\\C' . $i] = 'Vendor\\C' . ($i + 1);
        }
        $answer = $this->ancestry($parents)->depthOf('Vendor\\C0');
        self::assertSame(64, $answer->depth);
        self::assertSame(ExternalChainOutcome::BrokeAt, $answer->outcome);
        self::assertSame('Vendor\\C64', $answer->unresolved);
        self::assertNull($answer->reachesThrowable);
    }

    #[Test]
    public function itTreatsAnUnregisteredExtensionNameAsUnreadInsteadOfRuntimeEvidence(): void
    {
        $answer = $this->ancestry([])->depthOf('Probe\\PeclException');
        self::assertSame(0, $answer->depth);
        self::assertSame(ExternalChainOutcome::BrokeAt, $answer->outcome);
        self::assertNull($answer->reachesThrowable);
    }

    #[Test]
    public function itStopsAtAnAnalysedNameBeforeReadingItsSource(): void
    {
        foreach ([['App\A', 0, FixedParentSource::unconfigured()], ['\APP\a', 0, FixedParentSource::unconfigured()], ['Vendor\B', 1, new FixedParentSource(['Vendor\B' => '\APP\a', 'APP\a' => null])]] as [$name, $prefix, $source]) {
            $queries = [];
            $answer = $this->trackedAncestry($source, $queries)->depthOf($name, ['app\a' => true]);
            self::assertSame($prefix, $answer->depth);
            self::assertSame(ExternalChainOutcome::ReachedAnalysedName, $answer->outcome);
            self::assertSame(ltrim($prefix === 0 ? $name : 'APP\a', '\\'), $answer->analysedName);
            self::assertNull($answer->unresolved);
            self::assertNull($answer->reachesThrowable);
            self::assertFalse($answer->isComplete());
            self::assertSame($prefix === 0 ? [] : ['Vendor\B'], $queries);
        }
        $queries = [];
        $builtin = $this->trackedAncestry(FixedParentSource::unconfigured(), $queries)->depthOf('ArgumentCountError', ['error' => true]);
        self::assertSame(2, $builtin->depth);
        self::assertSame(ExternalChainOutcome::ReachedAnalysedName, $builtin->outcome);
        self::assertSame('Error', $builtin->analysedName);
        self::assertTrue($builtin->reachesThrowable);
        self::assertSame([], $queries);
    }

    #[Test]
    public function itPreservesExternalOnlyWalkingWhenNoGraphNamesAreSupplied(): void
    {
        $cases = [
            [new FixedParentSource(['Vendor\B' => null]), ExternalChainOutcome::ReachedRoot, 0, false],
            [new FixedParentSource(), ExternalChainOutcome::BrokeAt, 0, null],
            [FixedParentSource::unconfigured(), ExternalChainOutcome::NoMapForIt, 0, null],
            [new FixedParentSource(['Vendor\B' => 'vendor\b']), ExternalChainOutcome::Loop, null, null],
            [new FixedParentSource(['Vendor\B' => 'ArgumentCountError']), ExternalChainOutcome::ReachedRoot, 3, true],
        ];
        foreach ($cases as [$source, $outcome, $depth, $truth]) {
            $answer = (new ExternalAncestry($source))->depthOf('Vendor\B');
            self::assertSame($outcome, $answer->outcome);
            self::assertSame($depth, $answer->depth);
            self::assertSame($truth, $answer->reachesThrowable);
            self::assertNull($answer->analysedName);
        }
    }

    #[Test]
    public function itCompletesKnownGraphBoundariesAtTheExternalVisitLimit(): void
    {
        foreach ([63, 64, 65] as $length) {
            $parents = [];
            for ($i = 0; $i < $length; ++$i) {
                $parents['Vendor\C' . $i] = $i + 1 === $length ? 'App\A' : 'Vendor\C' . ($i + 1);
            }
            $parents['App\A'] = null;
            $queries = [];
            $answer = $this->trackedAncestry(new FixedParentSource($parents), $queries)->depthOf('Vendor\C0', ['app\a' => true]);
            self::assertSame(min(64, $length), $answer->depth);
            self::assertCount(min(64, $length), $queries);
            self::assertNotContains('App\A', $queries);
            self::assertSame($length <= 64 ? ExternalChainOutcome::ReachedAnalysedName : ExternalChainOutcome::BrokeAt, $answer->outcome);
            self::assertSame($length <= 64 ? 'App\A' : null, $answer->analysedName);
            self::assertSame($length <= 64 ? null : 'Vendor\C64', $answer->unresolved);
        }
    }

    #[Test]
    public function itKeepsStaticBuiltinLookupBeforeComposerPlacement(): void
    {
        $queries = [];
        $ancestry = $this->trackedAncestry(FixedParentSource::unconfigured(), $queries);
        foreach (['RuntimeException' => 1, 'ArgumentCountError' => 2, 'runtimeexception' => 1, '\ARGUMENTCOUNTERROR' => 2] as $name => $depth) {
            $answer = $ancestry->depthOf($name, ['app\a' => true]);
            self::assertSame($depth, $answer->depth);
            self::assertSame(ExternalChainOutcome::ReachedRoot, $answer->outcome);
            self::assertTrue($answer->reachesThrowable);
        }
        self::assertSame([], $queries);
    }

    /** @param list<string> $queries */
    private function trackedAncestry(FixedParentSource $source, array &$queries): ExternalAncestry
    {
        $record = static function (string $name) use (&$queries): void {
            $queries[] = $name;
        };
        $tracked = new class ($source, $record) implements ExternalParentSourceInterface {
            /** @param Closure(string): void $record */
            public function __construct(private readonly FixedParentSource $source, private readonly Closure $record) {}

            public function isConfigured(): bool
            {
                return $this->source->isConfigured();
            }

            public function parentOf(string $fqcn): ParentLookup
            {
                ($this->record)($fqcn);

                return $this->source->parentOf($fqcn);
            }
        };

        return new ExternalAncestry($tracked);
    }

    /**
     * @param array<string, string|null> $parents
     */
    private function ancestry(array $parents): ExternalAncestry
    {
        return new ExternalAncestry(new FixedParentSource($parents));
    }
}
