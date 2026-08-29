<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics;

use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Analysis\Evidence\Measurement\Visitor\DeclarationNumbering;
use Qualimetrix\Analysis\Evidence\Measurement\Visitor\FileEntrySubjectRegistry;
use Qualimetrix\Analysis\Evidence\Measurement\Visitor\VisitorCallableMetadata;
use Qualimetrix\Analysis\Evidence\Measurement\Visitor\VisitorLexicalScope;
use Qualimetrix\Analysis\Evidence\Measurement\Visitor\VisitorMethodContext;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(VisitorCallableScope::class)]
#[CoversClass(VisitorLexicalScope::class)]
#[CoversClass(FileEntrySubjectRegistry::class)]
#[CoversClass(DeclarationNumbering::class)]
#[CoversClass(VisitorCallableMetadata::class)]
#[CoversClass(VisitorMethodContext::class)]
final class VisitorMethodContextTest extends TestCase
{
    #[Test]
    public function itExposesTheExactImmutableCallableScopeContract(): void
    {
        $reflection = new ReflectionClass(VisitorCallableScope::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertSame(
            [
                'namespace',
                'class',
                'anonymousClassContext',
                'member',
                'logicalFqn',
                'traversalKey',
                'startFilePos',
                'sourceLine',
                'kind',
                'anonymousSyntax',
                'classStartFilePos',
                'ordinal',
                'classOrdinal',
            ],
            array_map(static fn($property): string => $property->getName(), $reflection->getProperties()),
        );

        $contextMethods = array_values(array_filter(
            (new ReflectionClass(VisitorMethodContext::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn($method): bool => !$method->isConstructor(),
        ));
        self::assertSame(
            [
                'reset',
                'useDeclarationIndex',
                'enter',
                'leave',
                'currentFileEntrySubjectId',
                'fileEntrySubjectComponents',
                'createCallableWithMetrics',
                'projectLogicalMetricMap',
            ],
            array_map(static fn($method): string => $method->getName(), $contextMethods),
        );
        self::assertSame([], (new ReflectionClass(VisitorCallableMetadata::class))->getProperties());
        self::assertSame(
            [
                'namespace',
                'class',
                'member',
                'startFilePos',
                'sourceLine',
                'kind',
                'anonymousSyntax',
                'classStartFilePos',
            ],
            array_map(
                static fn($parameter): string => $parameter->getName(),
                (new ReflectionClass(VisitorLexicalScope::class))->getMethod('enterCallable')->getParameters(),
            ),
        );
    }

    #[Test]
    public function itTracksCallableIdentityCollisionsAndResetsPerFile(): void
    {
        $context = new VisitorMethodContext();
        $context->useDeclarationIndex(new FileDeclarationIndex());
        $namespace = new Stmt\Namespace_(new Name('App'));
        $function = new Stmt\Function_('run', ['stmts' => []], ['startFilePos' => 40, 'startLine' => 7]);

        self::assertNull($context->enter($namespace));
        $first = $context->enter($function);
        self::assertNotNull($first);
        self::assertSame('App\run', $first->logicalFqn);
        self::assertSame('App\run@40#0', $first->traversalKey);
        self::assertSame(7, $first->sourceLine);
        self::assertSame(
            ['subjectKind' => 'declaration', 'logicalKind' => 'function', 'namespace' => 'App', 'member' => 'run'],
            $context->fileEntrySubjectComponents($context->currentFileEntrySubjectId()),
        );
        self::assertSame($first, $context->leave($function));

        $second = $context->enter($function);
        self::assertNotNull($second);
        self::assertSame('App\run@40#1', $second->traversalKey);

        $context->reset();
        $context->enter($namespace);
        $afterReset = $context->enter($function);
        self::assertNotNull($afterReset);
        self::assertSame('App\run@40#0', $afterReset->traversalKey);
    }

    #[Test]
    public function itKeepsAnonymousClassCallablesAtFileScope(): void
    {
        $context = new VisitorMethodContext();
        $context->useDeclarationIndex(new FileDeclarationIndex());
        $namespace = new Stmt\Namespace_(new Name('App'));
        $anonymousClass = new Stmt\Class_(null, [], ['startFilePos' => 10]);
        $closure = new Node\Expr\Closure(['stmts' => []], ['startFilePos' => 20, 'startLine' => 3]);

        $context->enter($namespace);
        $context->enter($anonymousClass);
        $scope = $context->enter($closure);

        self::assertNotNull($scope);
        self::assertTrue($scope->anonymousClassContext);
        self::assertSame('{anonymous#0}', $scope->class);
        self::assertSame('{closure#1}', $scope->member);
        self::assertSame(CallableKind::AnonymousCallable, $scope->kind);
        self::assertSame('closure', $scope->anonymousSyntax);
        self::assertSame(['subjectKind' => 'file'], $context->fileEntrySubjectComponents($context->currentFileEntrySubjectId()));
    }

    #[Test]
    public function itPreservesAnonymousLineageAndNumbersSiblingAnonymousCallablesOnce(): void
    {
        $context = new VisitorMethodContext();
        $context->useDeclarationIndex(new FileDeclarationIndex());
        $context->enter(new Stmt\Namespace_(new Name('App')));
        $context->enter(new Stmt\Class_('Outer', [], ['startFilePos' => 5]));

        $closure = new Node\Expr\Closure(['stmts' => []], ['startFilePos' => 10, 'startLine' => 2]);
        $closureScope = $context->enter($closure);
        self::assertNotNull($closureScope);
        self::assertSame('App\Outer::{closure#1}', $closureScope->logicalFqn);
        self::assertSame($closureScope, $context->leave($closure));

        $arrow = new Node\Expr\ArrowFunction(['expr' => new Node\Scalar\Int_(1)], ['startFilePos' => 20, 'startLine' => 3]);
        $arrowScope = $context->enter($arrow);
        self::assertNotNull($arrowScope);
        self::assertSame('{closure#2}', $arrowScope->member);
        self::assertSame('arrow', $arrowScope->anonymousSyntax);
        $context->leave($arrow);
        $context->leave(new Stmt\Class_('Outer'));

        $context->enter(new Stmt\Class_(null, [], ['startFilePos' => 30]));
        $context->enter(new Stmt\Class_('Nested', [], ['startFilePos' => 40]));
        $method = new Stmt\ClassMethod('run', ['stmts' => []], ['startFilePos' => 50, 'startLine' => 6]);
        $methodScope = $context->enter($method);
        self::assertNotNull($methodScope);
        self::assertTrue($methodScope->anonymousClassContext);
        self::assertSame('Nested', $methodScope->class);
        self::assertSame(['subjectKind' => 'file'], $context->fileEntrySubjectComponents($context->currentFileEntrySubjectId()));
    }

    #[Test]
    public function itBuildsPropertyHookIdentityFromTheEnteredProperty(): void
    {
        $context = new VisitorMethodContext();
        $context->useDeclarationIndex(new FileDeclarationIndex());
        $context->enter(new Stmt\Namespace_(new Name('App')));
        $context->enter(new Stmt\Class_('Thing', [], ['startFilePos' => 2]));
        $property = new Stmt\Property(0, [new PropertyItem('value')]);
        $hook = new Node\PropertyHook('get', [], [], ['startFilePos' => 8, 'startLine' => 4]);

        $context->enter($property);
        $scope = $context->enter($hook);

        self::assertNotNull($scope);
        self::assertSame('value::get', $scope->member);
        self::assertSame('App\Thing::value::get', $scope->logicalFqn);
        self::assertSame(CallableKind::PropertyHook, $scope->kind);
        self::assertSame($scope, $context->leave($hook));
        self::assertNull($context->leave($property));
    }

    #[Test]
    public function itProjectsTypedScopesIntoCallableMetricsAndLogicalMaps(): void
    {
        $context = new VisitorMethodContext();
        $scope = new VisitorCallableScope('App', 'Thing', false, 'run', 'App\Thing::run', 'App\Thing::run@12#0', 12, 8, CallableKind::Method, null, 3, DeclarationOrdinal::fromRank(0), DeclarationOrdinal::fromRank(0));
        $duplicate = new VisitorCallableScope('App', 'Thing', false, 'run', 'App\Thing::run', 'App\Thing::run@12#1', 12, 9, CallableKind::Method, null, 3, DeclarationOrdinal::fromRank(1), DeclarationOrdinal::fromRank(0));

        self::assertSame(0, $scope->ordinal->value);
        self::assertSame(1, $duplicate->ordinal->value);
        self::assertSame(['App\Thing::run' => 2], $context->projectLogicalMetricMap(['first' => 1, 'second' => 2], ['first' => $scope, 'second' => $duplicate]));

        $callable = $context->createCallableWithMetrics($scope, RelativePath::fromString('src/Thing.php'), MetricBag::fromArray(['complexity.ccn' => 2]));
        self::assertSame('declaration:callable:App\Thing::run@src/Thing.php', $callable->declarationPath->toCanonical());
        self::assertSame('declaration:class:App\Thing@src/Thing.php', $callable->lexicalClassContext?->toCanonical());
        self::assertSame(2, $callable->metrics->get('complexity.ccn'));
    }

    #[Test]
    public function itRejectsMembersThatContradictTheCallableKind(): void
    {
        $scope = new VisitorLexicalScope(new FileEntrySubjectRegistry(), new DeclarationNumbering());

        try {
            $scope->enterCallable(null, null, 'named', 1, 1, CallableKind::AnonymousCallable, 'closure', null);
            self::fail('Anonymous callables must derive their member from traversal state');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Anonymous callable scope must not supply a member', $exception->getMessage());
        }

        $this->expectExceptionObject(new InvalidArgumentException('Named callable scope requires a non-empty member'));
        $scope->enterCallable(null, null, null, 1, 1, CallableKind::Function, null, null);
    }
}
