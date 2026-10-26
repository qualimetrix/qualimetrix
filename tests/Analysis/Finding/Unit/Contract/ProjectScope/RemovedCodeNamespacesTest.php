<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Contract\ProjectScope;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\RemovedCodeNamespaces;

#[CoversClass(RemovedCodeNamespaces::class)]
final class RemovedCodeNamespacesTest extends TestCase
{
    #[Test]
    public function itMayDeclareNothingWhenNothingWasRemoved(): void
    {
        $none = RemovedCodeNamespaces::none();

        foreach ([true, false] as $subtree) {
            self::assertFalse($none->mayDeclareWithin('App', $subtree));
            self::assertFalse($none->mayDeclareWithin('', $subtree));
        }
        self::assertTrue($none->isKnown());
    }

    #[Test]
    public function itMayDeclareAnythingWhenUnknown(): void
    {
        $unknown = RemovedCodeNamespaces::unknown();

        foreach ([true, false] as $subtree) {
            self::assertTrue($unknown->mayDeclareWithin('Any\\Thing', $subtree));
            self::assertTrue($unknown->mayDeclareWithin('', $subtree));
        }
        self::assertFalse($unknown->isKnown());
    }

    #[Test]
    public function itTreatsAncestorsOfADeclaredNamespaceAsDeclared(): void
    {
        $known = RemovedCodeNamespaces::known(['App\\Foo\\Bar']);

        self::assertTrue($known->mayDeclareWithin('App\\Foo\\Bar', false));
        self::assertTrue($known->mayDeclareWithin('App\\Foo', false));
        self::assertTrue($known->mayDeclareWithin('App', false));
        self::assertFalse($known->mayDeclareWithin('App\\Baz', false));
        self::assertFalse($known->mayDeclareWithin('App\\Foo\\Bar\\Deeper', false));
    }

    #[Test]
    public function itAsksWhetherAnythingDeclaredLiesInsideASubtree(): void
    {
        $known = RemovedCodeNamespaces::known(['App\\Foo\\Bar']);

        self::assertTrue($known->mayDeclareWithin('App', true));
        self::assertTrue($known->mayDeclareWithin('App\\Foo', true));
        self::assertTrue($known->mayDeclareWithin('App\\Foo\\Bar', true));
        self::assertFalse($known->mayDeclareWithin('App\\Foo\\Bar\\Deeper', true));
        self::assertFalse($known->mayDeclareWithin('App\\Baz', true));
        self::assertFalse($known->mayDeclareWithin('Ap', true), 'A prefix is not a namespace boundary');
    }

    #[Test]
    public function itFoldsAsciiCaseAndIgnoresSurroundingBackslashes(): void
    {
        $known = RemovedCodeNamespaces::known(['\\App\\Foo']);

        self::assertTrue($known->mayDeclareWithin('app\\foo', false));
        self::assertTrue($known->mayDeclareWithin('APP', true));
        self::assertFalse($known->mayDeclareWithin('app\\fooo', false));
    }

    #[Test]
    public function itKeepsTheGlobalNamespaceApartFromNamedOnes(): void
    {
        $global = RemovedCodeNamespaces::known(['']);

        self::assertTrue($global->mayDeclareWithin('', false));
        self::assertTrue($global->mayDeclareWithin('', true));
        self::assertFalse($global->mayDeclareWithin('App', false));
        self::assertFalse($global->mayDeclareWithin('App', true));

        $named = RemovedCodeNamespaces::known(['App']);

        self::assertFalse($named->mayDeclareWithin('', false), 'Declaring App does not declare global code');
        self::assertTrue($named->mayDeclareWithin('', true), 'The subtree of the global namespace covers everything');
    }

    #[Test]
    public function itMergesWithUnknownAbsorbingAndNoneNeutral(): void
    {
        $a = RemovedCodeNamespaces::known(['App\\A']);
        $b = RemovedCodeNamespaces::known(['Lib\\B']);

        $both = $a->merge($b);
        self::assertTrue($both->mayDeclareWithin('App\\A', false));
        self::assertTrue($both->mayDeclareWithin('Lib\\B', false));
        self::assertFalse($both->mayDeclareWithin('Other', false));

        self::assertFalse($a->merge(RemovedCodeNamespaces::none())->mayDeclareWithin('Lib', true));
        self::assertTrue(RemovedCodeNamespaces::none()->merge($a)->mayDeclareWithin('App\\A', false));
        self::assertFalse(RemovedCodeNamespaces::none()->merge(RemovedCodeNamespaces::none())->mayDeclareWithin('App', true));

        self::assertTrue($a->merge(RemovedCodeNamespaces::unknown())->mayDeclareWithin('Zzz', false));
        self::assertTrue(RemovedCodeNamespaces::unknown()->merge($a)->mayDeclareWithin('Zzz', false));
        self::assertFalse($a->merge(RemovedCodeNamespaces::unknown())->isKnown());
    }
}
