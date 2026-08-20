<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Size\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Size\LocCollector;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

/**
 * A class-level producer materialises a non-zero ordinal.
 *
 * The self-run cannot show this: no file in `src` declares one identity twice,
 * so a class producer is only ever asked for ordinal zero there.
 *
 * The surviving record is the second declaration, because the eight class maps
 * are keyed by FQN and overwrite. That loss predates this numbering and is not
 * repaired by it; what is asserted here is that the survivor gets its own
 * honest number rather than the first one's.
 */
#[CoversClass(LocCollector::class)]
final class DuplicateClassDeclarationTest extends TestCase
{
    #[Test]
    public function itNumbersTheSecondDeclarationOfOneClassIdentity(): void
    {
        $source = <<<'PHP'
            <?php

            namespace App;

            if (\PHP_VERSION_ID > 80000) {
                class Greeter
                {
                    public function greet(): string
                    {
                        return 'first';
                    }
                }
            } else {
                class Greeter
                {
                    public function greet(): string
                    {
                        return 'second';
                    }
                }
            }
            PHP;
        $file = sys_get_temp_dir() . '/qmx-dup-class-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($file, $source);

        try {
            $ast = (new ParserFactory())->createForHostVersion()->parse($source) ?? [];
            $collector = new LocCollector();
            $registrar = (new DeclarationRegistrarFactory())->createForFile();
            $traverser = new NodeTraverser();
            $traverser->addVisitor($registrar);
            $collector->useDeclarationIndex($registrar->index());
            $traverser->addVisitor($collector->getVisitor());
            $traverser->traverse($ast);
            $collector->collect(new SplFileInfo($file), $ast);

            $classes = $collector->getClassesWithMetrics(RelativePath::fromString('src/Dup.php'));

            self::assertCount(1, $classes, 'The FQN-keyed class map keeps the last declaration only');
            self::assertSame(1, $classes[0]->declarationPath->ordinal->value);
            self::assertSame(
                'declaration:class:App\Greeter@src/Dup.php#1',
                $classes[0]->declarationPath->toCanonical(),
            );
        } finally {
            unlink($file);
        }
    }
}
