<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Tests\Analysis\Evidence\Coupling\Support\RfcMeasurement;

final class RfcGlobalCollectorTest extends TestCase
{
    #[Test]
    #[DataProvider('resolvedBodies')]
    public function itResolvesOnlyEffectiveOwnedBodies(string $code, string $class, int $own, int $external): void
    {
        $bag = RfcMeasurement::byName(RfcMeasurement::measure(['Case.php' => $code]))[$class];
        self::assertSame($own, $bag->get('coupling.rfc-own'));
        self::assertSame($external, $bag->get('coupling.rfc-external'));
        self::assertSame($own + $external, $bag->get('coupling.rfc'));
    }

    /** @return iterable<string, array{string, string, int, int}> */
    public static function resolvedBodies(): iterable
    {
        yield 'case and imported class aliases' => ['<?php namespace Lib { class Svc { static function run() {} } } namespace App { use Lib\\Svc as Alias; class C { function m() { Alias::RUN(); \\lib\\svc::run(); } } }', 'App\\C', 1, 1];
        yield 'namespace relative class' => ['<?php namespace App; class Svc { static function run() {} } class C { function m() { namespace\\Svc::run(); Svc::RUN(); \\App\\Svc::run(); } }', 'App\\C', 1, 1];
        yield 'assignment targets retain their direct invocations' => ['<?php class C { function m($a) { $a->make()->p = value(); } }', 'C', 1, 2];
        yield 'functions and methods are separate kinds' => ['<?php class C { function m($a, $b) { run(); RUN(); $a->run(); $b->RUN(); } }', 'C', 1, 2];
        yield 'function aliases resolve without class resolver' => ['<?php namespace App; use function Lib\\run as go; class C { function m() { go(); \\Lib\\run(); } }', 'App\\C', 1, 1];
        yield 'owned aliases count exposed methods but share body membership' => ['<?php trait T { function run() { $this->one(); $this->two(); $this->run(); } } class C { use T { run as one; run as two; } }', 'C', 3, 0];
        yield 'excluded trait body is not scanned' => ['<?php trait A { function m() { first(); } } trait B { function m() { second(); } } class C { use A, B { B::m insteadof A; } }', 'C', 1, 1];
        yield 'qualified alias restores excluded body' => ['<?php trait A { function m() { first(); } } trait B { function m() { second(); } } class C { use A, B { B::m insteadof A; A::m as original; } }', 'C', 2, 2];
        yield 'visibility changes do not add methods' => ['<?php trait T { private function run() { $this->run(); } } class C { use T { run as protected; } }', 'C', 1, 0];
        yield 'nested alias retains leaf body source' => ['<?php trait Leaf { function run() { $this->one(); $this->two(); } } trait Middle { use Leaf { run as one; } } class C { use Middle { one as two; } }', 'C', 3, 0];
        yield 'class override excludes trait body' => ['<?php trait T { function run() { excluded(); } } class C { use T; function run() {} }', 'C', 1, 0];
        yield 'trait context belongs to consumer' => ['<?php class P { function inherited() { excluded(); } } trait T { function run() { $this->own(); self::own(); static::own(); parent::inherited(); $this->inherited(); } } class C extends P { use T; function own() {} }', 'C', 2, 1];
        yield 'trait property and promoted consumer type' => ['<?php class S { function run() {} } trait T { public S $s; function m() { $this->s->run(); $this->other->run(); } } class C { use T; function __construct(public S $other) {} }', 'C', 2, 1];
        yield 'inherited property supplies one exact receiver type' => ['<?php class S { function run() {} } class P { public S $s; } class C extends P { function m() { $this->s->run(); S::run(); } }', 'C', 1, 1];
        yield 'own untyped property does not borrow a trait type' => ['<?php class S { function run() {} } trait T { public S $s; function m() { $this->s->run(); S::run(); } } class C { use T; public $s; }', 'C', 1, 2];
        yield 'inherited self and parent property types retain declaring context' => ['<?php class G { function run() {} } class P extends G { public self $p; public parent $g; function run() {} } class C extends P { function run() {} function m() { $this->p->run(); $this->g->run(); } }', 'C', 2, 2];
        yield 'unread trait adds no invented member' => ['<?php class C { use Missing; function m($x) { $this->lost(); $x->LOST(); } }', 'C', 1, 1];
        yield 'ambiguous trait has no chosen source' => ['<?php trait T { function first() {} } if (false) { trait T { function second() {} } } class C { use T; function m() { $this->first(); $this->second(); } }', 'C', 1, 2];
        yield 'ambiguous parent cannot absorb responses' => ['<?php class P { function run() {} } if (false) { class P { function other() {} } } class C extends P { function m($x) { parent::RUN(); $x->run(); } }', 'C', 1, 1];
        yield 'inheritance cycle falls back by method name' => ['<?php class A extends B { function m($x) { $this->lost(); $x->LOST(); } } class B extends A {}', 'A', 1, 1];
        yield 'nullable single class and local reassignment' => ['<?php class S { function run() {} } class C { function m(?S $s) { $x = $s; $x = $x; $x->RUN(); $s->run(); $x = unknown(); $x->run(); } }', 'C', 1, 3];
        yield 'positive instanceof does not leak outside branch' => ['<?php class S { function run() {} } class C { function m($x) { if ($x instanceof S) { $x->RUN(); } $x->run(); } }', 'C', 1, 2];
        yield 'typed assignment invalidates after a branch' => ['<?php class S { function run() {} } class C { function m(S $x) { if (flag()) { $x = unknown(); } $x->run(); S::run(); } }', 'C', 1, 4];
        yield 'loop assignments cannot leak across iterations' => ['<?php class S { function run() {} } class C { function m(S $x) { while (flag()) { $x->run(); $x = unknown(); } S::run(); } }', 'C', 1, 4];
        yield 'conditional assignment cannot narrow the following statement' => ['<?php class S { function run() {} } class C { function m($x) { flag() && ($x = new S); $x->run(); S::run(); } }', 'C', 1, 4];
        yield 'unions and intersections have no unique receiver' => ['<?php class A { function run() {} } class B { function run() {} } class C { function m(A|B $x, A&B $y) { $x->run(); $y->RUN(); } }', 'C', 1, 1];
        yield 'closures keep captured receivers and their own parameter scope' => ['<?php class S { function run() {} } class C { function m(S $s) { $a = function () use ($s) { $s->run(); }; $b = fn() => $s->RUN(); $c = function ($s) { $s->run(); }; } }', 'C', 1, 2];
        yield 'hook property spelling is case sensitive' => ['<?php class C { public string $p { get => first(); } public string $P { get => second(); } public function get() {} }', 'C', 3, 2];
        yield 'hook bodies keep distinct caller identities' => ['<?php class C { public string $a { get => first(); } public string $b { get => second(); set { third(); } } }', 'C', 3, 3];
        yield 'imported hooks keep property spelling and bodies' => ['<?php trait T { public string $p { get => first(); } public string $P { get => second(); } } trait Middle { use T; } class C { use Middle; }', 'C', 2, 2];
        yield 'conflicting trait hooks have no arbitrary source' => ['<?php trait A { public string $p { get => first(); } } trait B { public string $p { get => second(); } } class C { use A, B; }', 'C', 0, 0];
        yield 'hooked and plain trait property conflict has no chosen body' => ['<?php trait A { public string $p { get => first(); } } trait B { public string $p; } class C { use A, B; }', 'C', 0, 0];
        yield 'one chosen property cannot import a conflicted sibling hook' => ['<?php trait A { public string $a { get => good(); } public string $p { get => wrong(); } } trait B { public string $p { get => other(); } } class C { use A, B; }', 'C', 1, 1];
        yield 'empty declaration gets explicit zero' => ['<?php class C {}', 'C', 0, 0];
        yield 'unread ancestry retains the method name fallback' => ['<?php class C extends ParentType { function m($x) { parent::RUN(); $this->run(); $x->run(); } }', 'C', 1, 1];
        yield 'ambiguous named receiver has no selected declaration' => ['<?php class A { function run() {} } if (false) { class A { function run() {} } } class C { function m($x) { A::run(); $x->run(); } }', 'C', 1, 1];
        yield 'known missing methods retain receiver identity' => ['<?php class A {} class B {} class C { function m($x) { A::run(); B::RUN(); $x->run(); } }', 'C', 1, 3];
        yield 'unread direct named class retains class identity' => ['<?php class C { function m($x) { External::run(); OTHER::RUN(); $x->run(); } }', 'C', 1, 3];
    }

    #[Test]
    public function itUsesEachExactCrossFileDeclarationParent(): void
    {
        $repository = RfcMeasurement::measure([
            'Parents.php' => '<?php class A { function shared() {} } class B { function shared() {} }',
            'One.php' => '<?php class C extends A { function m() { $this->shared(); A::shared(); } }',
            'Two.php' => '<?php class C extends B { function m() { $this->shared(); A::shared(); } }',
        ]);
        $values = [];
        foreach ($repository->allClassDeclarations() as $info) {
            if ($info->symbolPath->toString() === 'C') {
                self::assertNotNull($info->subject);
                $values[] = $repository->getSubject($info->subject)->get('coupling.rfc');
            }
        }
        self::assertSame([2, 3], $values);
    }
    #[Test]
    public function itAggregatesTheFinalDeclarationValuesExactlyOnce(): void
    {
        $repository = RfcMeasurement::measure([
            'A.php' => '<?php namespace App; class A { function run() {} }',
            'C.php' => '<?php namespace App; class C extends A { function m() { $this->run(); } } class Bare {} interface Contract { function one(); function two(); }',
        ]);
        foreach ([\Qualimetrix\Core\Symbol\SymbolPath::forNamespace('App'), \Qualimetrix\Core\Symbol\SymbolPath::forProject()] as $aggregate) {
            $bag = $repository->get($aggregate);
            self::assertSame(5, $bag->get('coupling.rfc.sum'));
            self::assertSame(4, $bag->get('coupling.rfc-own.sum'));
            self::assertSame(1, $bag->get('coupling.rfc-external.sum'));
            self::assertSame(4, $bag->get('coupling.rfc.count'));
            self::assertEqualsWithDelta(1.25, $bag->get('coupling.rfc.avg'), 1.0e-9);
            self::assertSame([], $bag->entries(\Qualimetrix\Analysis\Evidence\Coupling\RfcCollector::FACTS));
        }
    }

}
