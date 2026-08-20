<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Integration\Identity;

use FilesystemIterator;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\UnusedPrivateCollector;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomCollector;
use Qualimetrix\Analysis\Evidence\Cohesion\TccLccCollector;
use Qualimetrix\Analysis\Evidence\Coupling\RfcCollector;
use Qualimetrix\Analysis\Evidence\Design\InheritanceDepthCollector;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverageCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AbstractCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Size\LocCollector;
use Qualimetrix\Analysis\Evidence\Size\MethodCountCollector;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every class-level producer numbers a duplicate identity for itself.
 *
 * The shared helper only guarantees that the position identifying a
 * declaration and the position it was collected at come from one place. Which
 * node each visitor carries to its record, and how it derives the logical name
 * it asks the index under, stay its own: a producer that stored a neighbour's
 * position, or that tracked the namespace differently from the registrar, is
 * answered with a fresh zero rather than being rejected, so nothing but a
 * per-producer fixture shows the number it actually materialises.
 *
 * The self-run cannot show it either: no file in `src` declares one identity
 * twice, so every producer is only ever asked for ordinal zero there.
 */
final class ClassProducerOrdinalTest extends TestCase
{
    /**
     * Every call site of the shared helper, and the collector that owns it.
     *
     * @var array<string, class-string<ClassMetricsProviderInterface&AbstractCollector>>
     */
    private const array PRODUCERS = [
        'src/Analysis/Evidence/CodeSmell/UnusedPrivateCollector.php' => UnusedPrivateCollector::class,
        'src/Analysis/Evidence/Cohesion/LcomCollector.php' => LcomCollector::class,
        'src/Analysis/Evidence/Cohesion/TccLccCollector.php' => TccLccCollector::class,
        'src/Analysis/Evidence/Coupling/RfcCollector.php' => RfcCollector::class,
        'src/Analysis/Evidence/Design/InheritanceDepthCollector.php' => InheritanceDepthCollector::class,
        'src/Analysis/Evidence/Design/TypeCoverageVisitor.php' => TypeCoverageCollector::class,
        'src/Analysis/Evidence/Size/LocCollector.php' => LocCollector::class,
        'src/Analysis/Evidence/Size/MethodCountCollector.php' => MethodCountCollector::class,
    ];

    private const string HELPER = 'src/Analysis/Evidence/Measurement/Contract/DeclarationIndexAwareTrait.php';

    /**
     * The surviving record is the second declaration, because the class maps
     * are keyed by FQN and overwrite. That loss predates the numbering and is
     * not repaired by it; what is asserted is that the survivor gets its own
     * honest number rather than the first one's.
     *
     * @param class-string<ClassMetricsProviderInterface&AbstractCollector> $producer
     */
    #[Test]
    #[DataProvider('provideClassProducers')]
    public function itNumbersTheSecondDeclarationOfOneClassIdentity(string $producer): void
    {
        $source = self::duplicateClass();
        $file = sys_get_temp_dir() . '/qmx-dup-class-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($file, $source);

        try {
            $ast = (new ParserFactory())->createForHostVersion()->parse($source) ?? [];
            $collector = new $producer();
            $registrar = (new DeclarationRegistrarFactory())->createForFile();
            $traverser = new NodeTraverser();
            $traverser->addVisitor($registrar);

            // The wiring of CompositeCollector: the index reaches the collector
            // and its visitor, whichever of the two holds the helper.
            $visitor = $collector->getVisitor();
            self::deliverIndex($collector, $registrar->index());
            self::deliverIndex($visitor, $registrar->index());
            $traverser->addVisitor($visitor);
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

    /**
     * A ninth call site of the helper is a producer no fixture above covers.
     */
    #[Test]
    public function itCoversEveryCallSiteOfTheSharedHelper(): void
    {
        $root = \dirname(__DIR__, 6);
        $callSites = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($entry->getPathname(), \strlen($root) + 1);
            if ($relative === self::HELPER) {
                continue;
            }

            if (str_contains((string) file_get_contents($entry->getPathname()), '$this->classWithMetrics(')) {
                $callSites[] = $relative;
            }
        }
        sort($callSites);

        self::assertSame(array_keys(self::PRODUCERS), $callSites);
    }

    private static function deliverIndex(object $participant, FileDeclarationIndex $index): void
    {
        if ($participant instanceof DeclarationIndexAwareInterface) {
            $participant->useDeclarationIndex($index);
        }
    }

    /**
     * @return iterable<string, array{class-string<ClassMetricsProviderInterface&AbstractCollector>}>
     */
    public static function provideClassProducers(): iterable
    {
        foreach (self::PRODUCERS as $producer) {
            yield $producer => [$producer];
        }
    }

    private static function duplicateClass(): string
    {
        return <<<'PHP'
            <?php

            namespace App;

            if (\PHP_VERSION_ID > 80000) {
                class Greeter
                {
                    private string $greeting = 'first';

                    public function greet(): string
                    {
                        return $this->greeting;
                    }

                    public function shout(): string
                    {
                        return strtoupper($this->greeting);
                    }
                }
            } else {
                class Greeter
                {
                    private string $greeting = 'second';

                    public function greet(): string
                    {
                        return $this->greeting;
                    }

                    public function shout(): string
                    {
                        return strtoupper($this->greeting);
                    }
                }
            }
            PHP;
    }
}
