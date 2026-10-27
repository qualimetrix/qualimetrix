<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Analysis\Evidence\Measurement\Visitor\DeclarationNumbering;
use Qualimetrix\Analysis\Evidence\Measurement\Visitor\VisitorCallableMetadata;
use Qualimetrix\Analysis\Evidence\Measurement\Visitor\VisitorLexicalScope;
use Qualimetrix\Analysis\Evidence\Measurement\Visitor\VisitorMethodContext;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;

/**
 * An anonymous callable is named under its nearest enclosing frame and counted
 * inside that frame, and every projection of the name agrees with the scope.
 */
#[CoversClass(VisitorLexicalScope::class)]
#[CoversClass(DeclarationNumbering::class)]
#[CoversClass(VisitorCallableMetadata::class)]
#[CoversClass(VisitorMethodContext::class)]
final class AnonymousCallableNameTest extends TestCase
{
    private const string FILE = 'src/Fixture.php';

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideNamedCases')]
    public function itNamesAnonymousCallablesUnderTheirNearestEnclosingFrame(string $source, array $expected): void
    {
        self::assertSame($expected, self::anonymousNames(self::traverse($source)));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function provideNamedCases(): iterable
    {
        yield 'closure and arrow function in methods' => [<<<'PHP'
            <?php
            namespace App;
            final class A {
                public function rows(array $r): array { return array_map(static function ($x) { return $x; }, $r); }
            }
            final class B {
                public function f(): callable { return static fn() => 1; }
            }
            PHP, ['App\A::rows::{closure#1}', 'App\B::f::{closure#1}']];

        yield 'nested closures count inside their own parent' => [<<<'PHP'
            <?php
            namespace App;
            final class A {
                public function rows(): void {
                    $outer = function () { $inner = fn() => fn() => 1; $second = fn() => 2; };
                    $sibling = fn() => 3;
                }
            }
            PHP, [
            'App\A::rows::{closure#1}',
            'App\A::rows::{closure#1}::{closure#1}',
            'App\A::rows::{closure#1}::{closure#1}::{closure#1}',
            'App\A::rows::{closure#1}::{closure#2}',
            'App\A::rows::{closure#2}',
        ]];

        yield 'closure in a closure in an anonymous class method' => [<<<'PHP'
            <?php
            namespace App;
            final class Factory {
                public function make(): object {
                    return new class {
                        public function run(): callable { return function () { return fn() => 1; }; }
                    };
                }
            }
            PHP, ['App\{anonymous#0}::run::{closure#1}', 'App\{anonymous#0}::run::{closure#1}::{closure#1}']];

        yield 'closure in an anonymous class property default inside a method' => [<<<'PHP'
            <?php
            namespace App;
            final class Factory {
                public function make(): object {
                    return new class { public $handler = static fn() => 1; };
                }
            }
            PHP, ['App\{anonymous#0}::{closure#1}']];

        yield 'closures in class constant, property default and attribute arguments' => [<<<'PHP'
            <?php
            namespace App;
            #[Marker(static fn() => 1)]
            final class Holder {
                const C = static fn() => 2;
                public $handler = static function () { return 3; };
                #[Marker(static fn() => 4)]
                public function run(): void { $local = fn() => 5; }
            }
            PHP, ['App\Holder::{closure#1}', 'App\Holder::{closure#2}', 'App\Holder::{closure#3}', 'App\Holder::run::{closure#1}', 'App\Holder::run::{closure#2}']];

        yield 'class declared inside a function owns the closure in its constant' => [<<<'PHP'
            <?php
            namespace App;
            function f() {
                class X { const C = static fn() => 1; }
                return fn() => 2;
            }
            PHP, ['App\X::{closure#1}', 'App\f::{closure#1}']];

        yield 'property hook and function declared inside a method' => [<<<'PHP'
            <?php
            namespace App;
            class Service {
                public string $name { get => (fn (): string => 'name')(); }
                public function outer(): void {
                    function nested(): void { $n = fn() => 1; }
                    $closure = function (): void {};
                }
            }
            PHP, ['App\Service::name::get::{closure#1}', 'App\nested::{closure#1}', 'App\Service::outer::{closure#1}']];

        yield 'top-level function and file top-level code' => [<<<'PHP'
            <?php
            namespace App;
            function top(): callable { return fn() => 1; }
            $first = fn() => 2;
            $second = function () { return fn() => 3; };
            PHP, ['App\top::{closure#1}', 'App\{closure#1}', 'App\{closure#2}', 'App\{closure#2}::{closure#1}']];

        yield 'global namespace' => [<<<'PHP'
            <?php
            function top(): callable { return fn() => 1; }
            $first = fn() => 2;
            PHP, ['top::{closure#1}', '{closure#1}']];

        yield 'file-level counter spans braced namespaces' => [<<<'PHP'
            <?php
            namespace First { $a = fn() => 1; }
            namespace Second { $b = fn() => 2; }
            PHP, ['First\{closure#1}', 'Second\{closure#2}']];
    }

    #[Test]
    public function itKeepsAClosureNameWhenAClosureIsAddedToAnotherMethod(): void
    {
        $before = <<<'PHP'
            <?php
            namespace App;
            final class A {
                public function rows(array $r): array { return array_map(static function ($x) { return $x > 1 ? 1 : 0; }, $r); }
            }
            final class B {
                public function f(): callable { return static fn() => 1; }
            }
            PHP;
        $after = str_replace('return array_map(', '$extra = fn() => 0; return array_map(', $before);

        $arrowBefore = self::scopeAtLine(self::traverse($before), 7);
        $arrowAfter = self::scopeAtLine(self::traverse($after), 7);

        self::assertSame($arrowBefore['scope']->logicalFqn, $arrowAfter['scope']->logicalFqn);
        self::assertSame(
            $arrowBefore['callable']->declarationPath->toCanonical(),
            $arrowAfter['callable']->declarationPath->toCanonical(),
        );
        self::assertSame('App\B::f::{closure#1}', $arrowBefore['scope']->logicalFqn);
    }

    #[Test]
    public function itTellsSameNamedConditionalParentsApartByOrdinal(): void
    {
        $records = self::traverse(<<<'PHP'
            <?php
            namespace App;
            if (PHP_VERSION_ID > 80000) {
                function f() { return fn() => 1; }
            } else {
                function f() { return fn() => 2; }
            }
            PHP);
        $closures = array_values(array_filter(
            $records,
            static fn(array $record): bool => $record['scope']->kind === CallableKind::AnonymousCallable,
        ));

        self::assertSame(['App\f::{closure#1}', 'App\f::{closure#1}'], self::anonymousNames($records));
        self::assertSame(
            [
                'declaration:func:App::f::{closure#1}@src/Fixture.php',
                'declaration:func:App::f::{closure#1}@src/Fixture.php#1',
            ],
            array_map(static fn(array $record): string => $record['callable']->declarationPath->toCanonical(), $closures),
        );
    }

    #[Test]
    public function itProjectsOneNameIntoEveryIdentityOfACallable(): void
    {
        $records = self::traverse(<<<'PHP'
            <?php
            namespace App;
            #[Marker(static fn() => 0)]
            final class A {
                const C = static fn() => 1;
                public function rows(): callable { return function () { return fn() => 2; }; }
            }
            function f() { return fn() => 3; }
            $top = fn() => 4;
            if (PHP_VERSION_ID > 80000) { function g() { return fn() => 5; } } else { function g() { return fn() => 6; } }
            PHP);
        $context = new VisitorMethodContext();
        $canonicals = [];

        foreach ($records as ['scope' => $scope, 'callable' => $callable, 'subject' => $subject]) {
            $declaration = $callable->declarationPath;
            self::assertSame($scope->logicalFqn, $declaration->logical->toString(), 'metadata and scope disagree');
            self::assertSame($scope->logicalFqn . '@' . $scope->startFilePos . '#0', $scope->traversalKey);
            self::assertSame($scope->ordinal, $declaration->ordinal);
            self::assertSame(
                $declaration->toCanonical(),
                MetricSubjectCodec::decode($subject, RelativePath::fromString(self::FILE))->toCanonical(),
                'wire subject and declaration disagree',
            );
            self::assertSame(
                [$scope->logicalFqn => 1],
                $context->projectLogicalMetricMap([$scope->traversalKey => 1], [$scope->traversalKey => $scope]),
            );
            $canonicals[] = $scope->kind->value . ':' . $declaration->toCanonical();
        }

        self::assertSame($canonicals, array_values(array_unique($canonicals)), 'two declarations share one key');
        self::assertContains('App\A::rows::{closure#1}', array_map(static fn(array $record): string => $record['scope']->logicalFqn, $records));
    }

    #[Test]
    public function itKeepsAnonymousCallablesOutOfClassOwnership(): void
    {
        $records = self::traverse(<<<'PHP'
            <?php
            namespace App;
            final class A {
                const C = static fn() => 1;
                public function rows(): callable { return fn() => 2; }
            }
            PHP);

        foreach ($records as ['scope' => $scope, 'callable' => $callable]) {
            if ($scope->kind !== CallableKind::AnonymousCallable) {
                self::assertSame('declaration:class:App\A@src/Fixture.php', $callable->classAggregationOwner?->toCanonical());

                continue;
            }

            self::assertNull($callable->declarationPath->logical->type);
            self::assertNull($callable->classAggregationOwner);
            self::assertSame('declaration:class:App\A@src/Fixture.php', $callable->lexicalClassContext?->toCanonical());
        }
    }

    /**
     * @param list<array{scope: VisitorCallableScope, callable: CallableWithMetrics, subject: array<string, int|string>}> $records
     *
     * @return list<string>
     */
    private static function anonymousNames(array $records): array
    {
        $names = [];
        foreach ($records as $record) {
            if ($record['scope']->kind === CallableKind::AnonymousCallable) {
                $names[] = $record['scope']->logicalFqn;
            }
        }

        return $names;
    }

    /**
     * @param list<array{scope: VisitorCallableScope, callable: CallableWithMetrics, subject: array<string, int|string>}> $records
     *
     * @return array{scope: VisitorCallableScope, callable: CallableWithMetrics, subject: array<string, int|string>}
     */
    private static function scopeAtLine(array $records, int $line): array
    {
        foreach ($records as $record) {
            if ($record['scope']->kind === CallableKind::AnonymousCallable && $record['scope']->sourceLine === $line) {
                return $record;
            }
        }

        self::fail('No anonymous callable starts on line ' . $line);
    }

    /**
     * Traverses one file the way collection does: the registrar fills the
     * index first, then a producer with its own context asks it.
     *
     * @return list<array{scope: VisitorCallableScope, callable: CallableWithMetrics, subject: array<string, int|string>}>
     */
    private static function traverse(string $source): array
    {
        $probe = new class extends NodeVisitorAbstract {
            public readonly VisitorMethodContext $context;

            /** @var list<array{scope: VisitorCallableScope, callable: CallableWithMetrics, subject: array<string, int|string>}> */
            public array $records = [];

            public function __construct()
            {
                $this->context = new VisitorMethodContext();
            }

            public function enterNode(Node $node): ?int
            {
                $scope = $this->context->enter($node);
                if ($scope !== null) {
                    $this->records[] = [
                        'scope' => $scope,
                        'callable' => $this->context->createCallableWithMetrics($scope, RelativePath::fromString('src/Fixture.php'), new MetricBag()),
                        'subject' => $this->context->fileEntrySubjectComponents($this->context->currentFileEntrySubjectId()),
                    ];
                }

                return null;
            }

            public function leaveNode(Node $node): ?int
            {
                $this->context->leave($node);

                return null;
            }
        };

        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $probe->context->useDeclarationIndex($registrar->index());
        $traverser = new NodeTraverser();
        $traverser->addVisitor($registrar);
        $traverser->addVisitor($probe);
        $traverser->traverse((new ParserFactory())->createForHostVersion()->parse($source) ?? []);

        return $probe->records;
    }
}
