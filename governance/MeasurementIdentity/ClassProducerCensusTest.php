<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\MeasurementIdentity;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\UnusedPrivateCollector;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomCollector;
use Qualimetrix\Analysis\Evidence\Cohesion\TccLccCollector;
use Qualimetrix\Analysis\Evidence\Coupling\RfcCollector;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\InheritanceDepthCollector;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\TypeCoverageCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Size\LocCollector;
use Qualimetrix\Analysis\Evidence\Size\MethodCountCollector;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * The census `ClassProducerOrdinalTest`'s per-producer fixture relies on: the
 * class-metric producers it fixtures are exactly the class-metric producers
 * `src/` declares, and the shared duplicate-identity helper's call site it
 * names for each still lies where it says.
 *
 * The shared helper only guarantees that the position identifying a
 * declaration and the position it was collected at come from one place. Which
 * node each visitor carries to its record stays its own: a producer that
 * stored a neighbour's position is answered with a fresh zero rather than
 * being rejected, so nothing but a per-producer fixture shows the number it
 * actually materialises — which is why the population behind the fixture is
 * checked here rather than trusted.
 */
final class ClassProducerCensusTest extends TestCase
{
    /**
     * The class-metric producers, and the file whose call site of the shared
     * helper belongs to each.
     *
     * `TypeCoverageCollector` is the producer; the helper sits in the visitor
     * it drives, which is why the two columns are not the same file.
     *
     * @var array<class-string<ClassMetricsProviderInterface>, string>
     */
    private const array PRODUCERS = [
        UnusedPrivateCollector::class => 'src/Analysis/Evidence/CodeSmell/UnusedPrivateCollector.php',
        LcomCollector::class => 'src/Analysis/Evidence/Cohesion/LcomCollector.php',
        TccLccCollector::class => 'src/Analysis/Evidence/Cohesion/TccLccCollector.php',
        RfcCollector::class => 'src/Analysis/Evidence/Coupling/RfcCollector.php',
        InheritanceDepthCollector::class => 'src/Analysis/Evidence/Design/Inheritance/InheritanceDepthCollector.php',
        TypeCoverageCollector::class => 'src/Analysis/Evidence/Design/TypeCoverage/TypeCoverageVisitor.php',
        LocCollector::class => 'src/Analysis/Evidence/Size/LocCollector.php',
        MethodCountCollector::class => 'src/Analysis/Evidence/Size/MethodCountCollector.php',
    ];

    /**
     * A ninth class-metric producer is one no fixture above covers.
     *
     * The producers are enumerated by what makes them producers — the contract
     * they implement, resolved through the autoloader — and not by the text of
     * the helper call, which a new producer is free to spell differently or to
     * reach through the trait's other method.
     */
    #[Test]
    public function itCoversEveryClassMetricProducer(): void
    {
        $found = [];
        foreach (self::productionClasses() as $class) {
            if (!is_a($class, ClassMetricsProviderInterface::class, true)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            $found[] = $class;
        }
        sort($found);
        $covered = array_keys(self::PRODUCERS);
        sort($covered);

        self::assertSame($covered, $found);
    }

    /**
     * The call site each producer's fixture stands for still lies where it did.
     *
     * Subordinate to the enumeration above: it is what makes the fixtures'
     * claim about the *helper* checkable, not what makes the set complete.
     */
    #[Test]
    public function itFindsTheHelperCallSiteOfEveryCoveredProducer(): void
    {
        $root = \dirname(__DIR__, 2);
        $callSites = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }

            if (str_contains((string) file_get_contents($entry->getPathname()), '$this->classWithMetrics(')) {
                $callSites[] = substr($entry->getPathname(), \strlen($root) + 1);
            }
        }
        sort($callSites);
        $covered = array_values(self::PRODUCERS);
        sort($covered);

        self::assertSame($covered, $callSites);
    }

    /**
     * @return list<class-string>
     */
    private static function productionClasses(): array
    {
        $root = \dirname(__DIR__, 2);
        $classes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($entry->getPathname(), \strlen($root) + 5);
            /** @var class-string $class */
            $class = 'Qualimetrix\\' . str_replace('/', '\\', substr($relative, 0, -4));
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
