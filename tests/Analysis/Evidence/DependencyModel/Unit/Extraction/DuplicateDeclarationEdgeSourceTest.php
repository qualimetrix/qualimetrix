<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\DependencyModel\Unit\Extraction;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Core\Path\RelativePath;

/**
 * The source of a dependency edge carries a non-zero ordinal.
 *
 * The graph path never reaches `FileProcessor`, so this producer is covered by
 * the shared index and this case rather than by either guard.
 */
#[CoversClass(DependencyVisitor::class)]
final class DuplicateDeclarationEdgeSourceTest extends TestCase
{
    #[Test]
    public function itNumbersTheSecondDeclarationOfOneClassIdentityInItsEdges(): void
    {
        $source = <<<'PHP'
            <?php

            namespace App;

            if (\PHP_VERSION_ID > 80000) {
                class Greeter implements Port
                {
                }
            } else {
                class Greeter implements Port
                {
                }
            }
            PHP;
        $ast = (new ParserFactory())->createForHostVersion()->parse($source) ?? [];

        $visitor = new DependencyVisitor();
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($registrar);
        $traverser->addVisitor($visitor);
        $visitor->beginFile(RelativePath::fromString('src/Dup.php'), $registrar->index());
        $traverser->traverse($ast);

        $sources = array_map(
            static fn(Dependency $dependency): string => $dependency->source->toCanonical(),
            $visitor->dependencies(),
        );
        sort($sources);

        self::assertSame([
            'declaration:class:App\Greeter@src/Dup.php',
            'declaration:class:App\Greeter@src/Dup.php#1',
        ], $sources);
    }
}
