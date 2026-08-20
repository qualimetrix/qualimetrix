<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;
use SplFileInfo;

/**
 * The wire subject of a finding carries a non-zero ordinal.
 *
 * This producer never reaches `FileProcessor`, so neither guard covers it: the
 * evidence that it agrees with the rest is the shared index plus this case.
 */
#[CoversClass(CodeSmellCollector::class)]
final class DuplicateDeclarationWireSubjectTest extends TestCase
{
    #[Test]
    public function itTransportsTheOrdinalOfTheSecondDeclarationOfOneIdentity(): void
    {
        $source = <<<'PHP'
            <?php

            namespace App;

            if (\PHP_VERSION_ID > 80000) {
                class Greeter
                {
                    public function greet(): string
                    {
                        return (string) @file_get_contents('first');
                    }
                }
            } else {
                class Greeter
                {
                    public function greet(): string
                    {
                        return (string) @file_get_contents('second');
                    }
                }
            }
            PHP;
        $file = sys_get_temp_dir() . '/qmx-dup-smell-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($file, $source);

        try {
            $ast = (new ParserFactory())->createForHostVersion()->parse($source) ?? [];
            $collector = new CodeSmellCollector();
            $registrar = (new DeclarationRegistrarFactory())->createForFile();
            $traverser = new NodeTraverser();
            $traverser->addVisitor($registrar);
            $visitor = $collector->getVisitor();
            self::assertInstanceOf(DeclarationIndexAwareInterface::class, $visitor);
            $visitor->useDeclarationIndex($registrar->index());
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            $entries = $collector->collect(new SplFileInfo($file), $ast)->entries('codeSmell.error_suppression');
            $subjects = array_map(
                static fn(array $entry): string => MetricSubjectCodec::decodeEntry(
                    $entry,
                    RelativePath::fromString('src/Dup.php'),
                )->toCanonical(),
                $entries,
            );
            sort($subjects);

            self::assertSame([
                'declaration:callable:App\Greeter::greet@src/Dup.php',
                'declaration:callable:App\Greeter::greet@src/Dup.php#1',
            ], $subjects);
        } finally {
            unlink($file);
        }
    }
}
