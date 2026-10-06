<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Ast\Unit;

use LogicException;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Ast\NameResolution;
use Qualimetrix\Core\Ast\ResolvedName;

#[CoversClass(ResolvedName::class)]
final class ResolvedNameTest extends TestCase
{
    #[Test]
    public function itReadsOnlyAnAnnotatedClassNameAndComparesItCaseInsensitively(): void
    {
        $ast = (new ParserFactory())->createForHostVersion()->parse('<?php namespace App; use Vendor\\Base as ParentAlias; class Child extends parentalias {}') ?? [];
        NameResolution::resolve($ast);
        $namespace = $ast[0] ?? null;
        self::assertInstanceOf(Namespace_::class, $namespace);
        $class = $namespace->stmts[1] ?? null;
        self::assertInstanceOf(Class_::class, $class);
        self::assertInstanceOf(Name::class, $class->extends);

        self::assertSame('Vendor\\Base', ResolvedName::className($class->extends));
        self::assertTrue(ResolvedName::sameClass('APP\\Child', 'app\\child'));
        self::assertNull(ResolvedName::className(new Name('self')));
    }

    #[Test]
    public function itRejectsAnUnqualifiedFunctionNameAsARuntimeResolution(): void
    {
        $ast = (new ParserFactory())->createForHostVersion()->parse('<?php namespace App; function f() { unknown(); }') ?? [];
        NameResolution::resolve($ast);
        $namespace = $ast[0] ?? null;
        self::assertInstanceOf(Namespace_::class, $namespace);
        $function = $namespace->stmts[0] ?? null;
        self::assertInstanceOf(Function_::class, $function);
        $statement = $function->stmts[0] ?? null;
        self::assertInstanceOf(Expression::class, $statement);
        self::assertInstanceOf(FuncCall::class, $statement->expr);
        $name = $statement->expr->name;
        self::assertInstanceOf(Name::class, $name);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Not a class-name position');
        ResolvedName::className($name);
    }

    #[Test]
    public function itExplainsAnUnannotatedNamespaceOrUseItemName(): void
    {
        $ast = (new ParserFactory())->createForHostVersion()->parse('<?php namespace App; use Vendor\\Base;') ?? [];
        NameResolution::resolve($ast);
        $namespace = $ast[0] ?? null;
        self::assertInstanceOf(Namespace_::class, $namespace);
        $name = $namespace->name;
        self::assertInstanceOf(Name::class, $name);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No resolvedName');
        ResolvedName::className($name);
    }
}
