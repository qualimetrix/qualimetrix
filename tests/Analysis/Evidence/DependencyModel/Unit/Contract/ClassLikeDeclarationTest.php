<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\DependencyModel\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(ClassLikeDeclaration::class)]
final class ClassLikeDeclarationTest extends TestCase
{
    #[Test]
    public function itCanRewriteLogicalSpellingWithoutChangingExactDeclarationFacts(): void
    {
        $exact = DeclarationPath::of(
            SymbolPath::fromClassFqn('App\\HTTP\\Handler'),
            RelativePath::fromString('src/Handler.php'),
            DeclarationOrdinal::fromRank(1),
        );
        $fact = ClassLikeDeclaration::of($exact, ClassType::Trait_, true, true);

        $rewritten = $fact->withLogicalClass(new LogicalClassPath(SymbolPath::fromClassFqn('App\\Http\\Handler')));

        self::assertSame($exact, $rewritten->declaration);
        self::assertSame('App\\Http\\Handler', $rewritten->logical->symbolPath->toString());
        self::assertSame(ClassType::Trait_, $rewritten->type);
        self::assertTrue($rewritten->declaresToString);
        self::assertTrue($rewritten->aliasesTraitMethodAsToString);
    }
}
