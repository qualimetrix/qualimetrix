<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Unit\Ast;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Ast\NameResolution;

#[CoversClass(NameResolution::class)]
final class NameResolutionTest extends TestCase
{
    #[Test]
    public function itAnnotatesTheOriginalNameNodesBeforeConsumersVisitTheirParents(): void
    {
        $ast = (new ParserFactory())->createForHostVersion()->parse('<?php namespace App; use Vendor\\Base as ParentAlias; class Child extends ParentAlias {}') ?? [];
        $namespace = $ast[0] ?? null;
        self::assertInstanceOf(Namespace_::class, $namespace);
        $class = $namespace->stmts[1] ?? null;
        self::assertInstanceOf(Class_::class, $class);
        $name = $class->extends;
        self::assertInstanceOf(Name::class, $name);

        NameResolution::resolve($ast);

        self::assertSame($name, $class->extends);
        $resolved = $name->getAttribute('resolvedName');
        self::assertInstanceOf(Name::class, $resolved);
        self::assertSame('Vendor\\Base', $resolved->toString());
        NameResolution::resolve($ast);
        $resolved = $name->getAttribute('resolvedName');
        self::assertInstanceOf(Name::class, $resolved);
        self::assertSame('Vendor\\Base', $resolved->toString());
    }

    #[Test]
    public function itCollectsAliasErrorsWithoutFailingTheFile(): void
    {
        $ast = (new ParserFactory())->createForHostVersion()->parse('<?php namespace App; use Vendor\\One as Alias; use Vendor\\Two as Alias; class Subject extends Alias {}') ?? [];

        NameResolution::resolve($ast);

        self::assertCount(1, $ast);
    }
}
