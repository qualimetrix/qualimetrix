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

        foreach (['App', ''] as $namespace) {
            self::assertFalse($none->mayDeclare($namespace));
            self::assertFalse($none->mayDeclareWithin($namespace));
        }
        self::assertTrue($none->isKnown());
    }

    #[Test]
    public function itMayDeclareAnythingWhenUnknown(): void
    {
        $unknown = RemovedCodeNamespaces::unknown();

        foreach (['Any\\Thing', ''] as $namespace) {
            self::assertTrue($unknown->mayDeclare($namespace));
            self::assertTrue($unknown->mayDeclareWithin($namespace));
        }
        self::assertFalse($unknown->isKnown());
    }

    #[Test]
    public function itTreatsAncestorsOfADeclaredNamespaceAsDeclared(): void
    {
        $known = RemovedCodeNamespaces::known(['App\\Foo\\Bar']);

        self::assertTrue($known->mayDeclare('App\\Foo\\Bar'));
        self::assertTrue($known->mayDeclare('App\\Foo'));
        self::assertTrue($known->mayDeclare('App'));
        self::assertFalse($known->mayDeclare('App\\Baz'));
        self::assertFalse($known->mayDeclare('App\\Foo\\Bar\\Deeper'));
    }

    #[Test]
    public function itAsksWhetherAnythingDeclaredLiesInsideASubtree(): void
    {
        $known = RemovedCodeNamespaces::known(['App\\Foo\\Bar']);

        self::assertTrue($known->mayDeclareWithin('App'));
        self::assertTrue($known->mayDeclareWithin('App\\Foo'));
        self::assertTrue($known->mayDeclareWithin('App\\Foo\\Bar'));
        self::assertFalse($known->mayDeclareWithin('App\\Foo\\Bar\\Deeper'));
        self::assertFalse($known->mayDeclareWithin('App\\Baz'));
        self::assertFalse($known->mayDeclareWithin('Ap'), 'A prefix is not a namespace boundary');
    }

    #[Test]
    public function itFoldsAsciiCaseAndIgnoresSurroundingBackslashes(): void
    {
        $known = RemovedCodeNamespaces::known(['\\App\\Foo']);

        self::assertTrue($known->mayDeclare('app\\foo'));
        self::assertTrue($known->mayDeclareWithin('APP'));
        self::assertFalse($known->mayDeclare('app\\fooo'));
    }

    #[Test]
    public function itKeepsTheGlobalNamespaceApartFromNamedOnes(): void
    {
        $global = RemovedCodeNamespaces::known(['']);

        self::assertTrue($global->mayDeclare(''));
        self::assertTrue($global->mayDeclareWithin(''));
        self::assertFalse($global->mayDeclare('App'));
        self::assertFalse($global->mayDeclareWithin('App'));

        $named = RemovedCodeNamespaces::known(['App']);

        self::assertFalse($named->mayDeclare(''), 'Declaring App does not declare global code');
        self::assertTrue($named->mayDeclareWithin(''), 'The subtree of the global namespace covers everything');
    }

    #[Test]
    public function itMergesWithUnknownAbsorbingAndNoneNeutral(): void
    {
        $a = RemovedCodeNamespaces::known(['App\\A']);
        $b = RemovedCodeNamespaces::known(['Lib\\B']);

        $both = $a->merge($b);
        self::assertTrue($both->mayDeclare('App\\A'));
        self::assertTrue($both->mayDeclare('Lib\\B'));
        self::assertFalse($both->mayDeclare('Other'));

        self::assertFalse($a->merge(RemovedCodeNamespaces::none())->mayDeclareWithin('Lib'));
        self::assertTrue(RemovedCodeNamespaces::none()->merge($a)->mayDeclare('App\\A'));
        self::assertFalse(RemovedCodeNamespaces::none()->merge(RemovedCodeNamespaces::none())->mayDeclareWithin('App'));

        self::assertTrue($a->merge(RemovedCodeNamespaces::unknown())->mayDeclare('Zzz'));
        self::assertTrue(RemovedCodeNamespaces::unknown()->merge($a)->mayDeclare('Zzz'));
        self::assertFalse($a->merge(RemovedCodeNamespaces::unknown())->isKnown());
    }
}
