<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Metrics;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Namespace_\NamespaceTree;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * Integration test that verifies mathematical invariants hold for ALL
 * aggregated metrics after running the full Qualimetrix pipeline on fixture files.
 *
 * These invariants should hold regardless of specific metric values,
 * catching aggregation bugs that golden-file tests might miss.
 *
 * Fixture directory: tests/Fixtures/GoldenMetrics/
 */
#[Group('integration')]
final class MetricInvariantTest extends TestCase
{
    private const float DELTA = 0.001;
    private const string FIXTURE_NS_PREFIX = 'GoldenMetrics\\';

    private static MetricRepositoryInterface $repository;
    private static NamespaceTree $namespaceTree;

    public static function setUpBeforeClass(): void
    {
        $containerFactory = new ContainerFactory();
        $container = $containerFactory->create();

        /** @var AnalysisPipelineInterface $pipeline */
        $pipeline = $container->get(AnalysisPipelineInterface::class);

        $fixturesPath = \dirname(__DIR__, 2) . '/Fixtures/GoldenMetrics';
        $result = $pipeline->analyze($fixturesPath);

        self::$repository = $result->metrics;
        self::assertNotNull($result->namespaceTree, 'NamespaceTree must be present in analysis result');
        self::$namespaceTree = $result->namespaceTree;
    }

    // ──────────────────────────────────────────────────────────────────
    // 1. Sum consistency: leaf namespace vs class totals
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testLeafNamespaceSumEqualsClassTotal(): void
    {
        foreach (self::$namespaceTree->getLeaves() as $ns) {
            if (!$this->isFixtureNamespace($ns)) {
                continue;
            }

            $nsBag = self::$repository->get(SymbolPath::forNamespace($ns));
            $nsCcnSum = $nsBag->get('ccn.sum');

            if ($nsCcnSum === null) {
                continue;
            }

            // Sum CCN across all class symbols in this namespace
            $classCcnTotal = 0;
            $hasClasses = false;

            foreach (self::$repository->forNamespace($ns) as $symbolInfo) {
                $type = $symbolInfo->symbolPath->getType();

                if ($type === SymbolType::Class_) {
                    $classBag = self::$repository->get($symbolInfo->symbolPath);
                    $classCcn = $classBag->get('ccn.sum');
                    if ($classCcn !== null) {
                        $classCcnTotal += $classCcn;
                        $hasClasses = true;
                    }
                }
            }

            if ($hasClasses) {
                // For leaf namespaces, CCN sum should include both class and function contributions.
                // Collect function CCN too.
                $functionCcnTotal = 0;
                foreach (self::$repository->forNamespace($ns) as $symbolInfo) {
                    if ($symbolInfo->symbolPath->getType() === SymbolType::Function_) {
                        $fnBag = self::$repository->get($symbolInfo->symbolPath);
                        $fnCcn = $fnBag->get('ccn');
                        if ($fnCcn !== null) {
                            $functionCcnTotal += $fnCcn;
                        }
                    }
                }

                self::assertSame(
                    $nsCcnSum,
                    $classCcnTotal + $functionCcnTotal,
                    "Leaf NS '{$ns}': ccn.sum ({$nsCcnSum}) must equal "
                    . "sum of class ccn.sum ({$classCcnTotal}) + function ccn ({$functionCcnTotal})",
                );
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 2. Sum consistency: parent namespace vs descendant subtree
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testParentNamespaceSumEqualsDescendantTotal(): void
    {
        foreach (self::$namespaceTree->getParentNamespaces() as $parentNs) {
            if (!$this->isFixtureNamespace($parentNs)) {
                continue;
            }

            $parentBag = self::$repository->get(SymbolPath::forNamespace($parentNs));
            $parentCcnSum = $parentBag->get('ccn.sum');

            if ($parentCcnSum === null) {
                continue;
            }

            // Collect all descendant namespaces (including the parent itself if it has own symbols)
            $allNamespaces = [$parentNs, ...self::$namespaceTree->getDescendants($parentNs)];

            $classCcnTotal = 0;
            $functionCcnTotal = 0;

            foreach ($allNamespaces as $ns) {
                foreach (self::$repository->forNamespace($ns) as $symbolInfo) {
                    $type = $symbolInfo->symbolPath->getType();

                    if ($type === SymbolType::Class_) {
                        $classBag = self::$repository->get($symbolInfo->symbolPath);
                        $classCcn = $classBag->get('ccn.sum');
                        if ($classCcn !== null) {
                            $classCcnTotal += $classCcn;
                        }
                    } elseif ($type === SymbolType::Function_) {
                        $fnBag = self::$repository->get($symbolInfo->symbolPath);
                        $fnCcn = $fnBag->get('ccn');
                        if ($fnCcn !== null) {
                            $functionCcnTotal += $fnCcn;
                        }
                    }
                }
            }

            $subtreeTotal = $classCcnTotal + $functionCcnTotal;
            self::assertSame(
                $parentCcnSum,
                $subtreeTotal,
                "Parent NS '{$parentNs}': ccn.sum ({$parentCcnSum}) must equal "
                . "sum of all class ccn.sum ({$classCcnTotal}) + function ccn ({$functionCcnTotal}) in subtree",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 3. Project LOC sum equals file LOC total
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testProjectLocSumEqualsFileLocTotal(): void
    {
        $projectBag = self::$repository->get(SymbolPath::forProject());
        $projectLocSum = $projectBag->get('loc.sum');
        self::assertNotNull($projectLocSum, 'Project loc.sum must exist');

        $fileLocTotal = 0;
        foreach (self::$repository->all(SymbolType::File) as $fileInfo) {
            $fileBag = self::$repository->get($fileInfo->symbolPath);
            $fileLoc = $fileBag->get('loc');
            if ($fileLoc !== null) {
                $fileLocTotal += $fileLoc;
            }
        }

        self::assertSame(
            $projectLocSum,
            $fileLocTotal,
            "Project loc.sum ({$projectLocSum}) must equal sum of all file LOC ({$fileLocTotal})",
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 4. Project classCount sum equals file classCount total
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testProjectClassCountEqualsFileTotal(): void
    {
        $projectBag = self::$repository->get(SymbolPath::forProject());
        $projectClassCountSum = $projectBag->get('classCount.sum');
        self::assertNotNull($projectClassCountSum, 'Project classCount.sum must exist');

        $fileClassCountTotal = 0;
        foreach (self::$repository->all(SymbolType::File) as $fileInfo) {
            $fileBag = self::$repository->get($fileInfo->symbolPath);
            $fileClassCount = $fileBag->get('classCount');
            if ($fileClassCount !== null) {
                $fileClassCountTotal += $fileClassCount;
            }
        }

        self::assertSame(
            $projectClassCountSum,
            $fileClassCountTotal,
            "Project classCount.sum ({$projectClassCountSum}) must equal "
            . "sum of all file classCount ({$fileClassCountTotal})",
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 5. Max consistency: namespace max equals class max in subtree
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testNamespaceMaxEqualsClassMax(): void
    {
        foreach (self::$namespaceTree->getAllNamespaces() as $ns) {
            if (!$this->isFixtureNamespace($ns)) {
                continue;
            }

            $nsBag = self::$repository->get(SymbolPath::forNamespace($ns));
            $nsCcnMax = $nsBag->get('ccn.max');

            if ($nsCcnMax === null) {
                continue;
            }

            // Collect all namespaces in the subtree (including self)
            $allNamespaces = [$ns, ...self::$namespaceTree->getDescendants($ns)];

            $sourceMaxCcn = 0;
            $hasSources = false;

            foreach ($allNamespaces as $subtreeNs) {
                foreach (self::$repository->forNamespace($subtreeNs) as $symbolInfo) {
                    $type = $symbolInfo->symbolPath->getType();

                    // ccn.max at namespace = max of raw method/function CCN values
                    if ($type === SymbolType::Method) {
                        $methodBag = self::$repository->get($symbolInfo->symbolPath);
                        $methodCcn = $methodBag->get('ccn');
                        if ($methodCcn !== null) {
                            $sourceMaxCcn = max($sourceMaxCcn, $methodCcn);
                            $hasSources = true;
                        }
                    } elseif ($type === SymbolType::Function_) {
                        $fnBag = self::$repository->get($symbolInfo->symbolPath);
                        $fnCcn = $fnBag->get('ccn');
                        if ($fnCcn !== null) {
                            $sourceMaxCcn = max($sourceMaxCcn, $fnCcn);
                            $hasSources = true;
                        }
                    }
                }
            }

            if ($hasSources) {
                self::assertSame(
                    $nsCcnMax,
                    $sourceMaxCcn,
                    "NS '{$ns}': ccn.max ({$nsCcnMax}) must equal max method/function ccn ({$sourceMaxCcn}) in subtree",
                );
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 6. Symbol method count matches actual method+function count
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testSymbolMethodCountMatchesActual(): void
    {
        foreach (self::$namespaceTree->getAllNamespaces() as $ns) {
            if (!$this->isFixtureNamespace($ns)) {
                continue;
            }

            $nsBag = self::$repository->get(SymbolPath::forNamespace($ns));
            $symbolMethodCount = $nsBag->get('symbolMethodCount');

            if ($symbolMethodCount === null) {
                continue;
            }

            // For leaf namespaces, count method and function symbols directly
            if (self::$namespaceTree->isLeaf($ns)) {
                $actualCount = 0;
                foreach (self::$repository->forNamespace($ns) as $symbolInfo) {
                    $type = $symbolInfo->symbolPath->getType();
                    if ($type === SymbolType::Method || $type === SymbolType::Function_) {
                        $actualCount++;
                    }
                }

                self::assertSame(
                    $symbolMethodCount,
                    $actualCount,
                    "Leaf NS '{$ns}': symbolMethodCount ({$symbolMethodCount}) must equal "
                    . "actual method+function symbol count ({$actualCount})",
                );
            } else {
                // For parent namespaces, sum symbolMethodCount from all descendant namespaces
                // that have own symbols, plus own symbols
                $subtreeCount = 0;
                foreach (self::$repository->forNamespace($ns) as $symbolInfo) {
                    $type = $symbolInfo->symbolPath->getType();
                    if ($type === SymbolType::Method || $type === SymbolType::Function_) {
                        $subtreeCount++;
                    }
                }
                foreach (self::$namespaceTree->getDescendants($ns) as $descNs) {
                    foreach (self::$repository->forNamespace($descNs) as $symbolInfo) {
                        $type = $symbolInfo->symbolPath->getType();
                        if ($type === SymbolType::Method || $type === SymbolType::Function_) {
                            $subtreeCount++;
                        }
                    }
                }

                self::assertSame(
                    $symbolMethodCount,
                    $subtreeCount,
                    "Parent NS '{$ns}': symbolMethodCount ({$symbolMethodCount}) must equal "
                    . "total method+function symbols in subtree ({$subtreeCount})",
                );
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 7. Symbol class count matches actual class count
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testSymbolClassCountMatchesActual(): void
    {
        foreach (self::$namespaceTree->getAllNamespaces() as $ns) {
            if (!$this->isFixtureNamespace($ns)) {
                continue;
            }

            $nsBag = self::$repository->get(SymbolPath::forNamespace($ns));
            $symbolClassCount = $nsBag->get('symbolClassCount');

            if ($symbolClassCount === null) {
                continue;
            }

            if (self::$namespaceTree->isLeaf($ns)) {
                $actualCount = 0;
                foreach (self::$repository->forNamespace($ns) as $symbolInfo) {
                    if ($symbolInfo->symbolPath->getType() === SymbolType::Class_) {
                        $actualCount++;
                    }
                }

                self::assertSame(
                    $symbolClassCount,
                    $actualCount,
                    "Leaf NS '{$ns}': symbolClassCount ({$symbolClassCount}) must equal "
                    . "actual class symbol count ({$actualCount})",
                );
            } else {
                $subtreeCount = 0;
                foreach (self::$repository->forNamespace($ns) as $symbolInfo) {
                    if ($symbolInfo->symbolPath->getType() === SymbolType::Class_) {
                        $subtreeCount++;
                    }
                }
                foreach (self::$namespaceTree->getDescendants($ns) as $descNs) {
                    foreach (self::$repository->forNamespace($descNs) as $symbolInfo) {
                        if ($symbolInfo->symbolPath->getType() === SymbolType::Class_) {
                            $subtreeCount++;
                        }
                    }
                }

                self::assertSame(
                    $symbolClassCount,
                    $subtreeCount,
                    "Parent NS '{$ns}': symbolClassCount ({$symbolClassCount}) must equal "
                    . "total class symbols in subtree ({$subtreeCount})",
                );
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 8. No double-counting: project class count equals actual Class_ symbols
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testProjectSymbolCountEqualsLeafNsTotal(): void
    {
        $projectBag = self::$repository->get(SymbolPath::forProject());
        $projectSymbolClassCount = $projectBag->get('symbolClassCount');
        self::assertNotNull($projectSymbolClassCount, 'Project symbolClassCount must exist');

        // Count actual Class_ symbols in the repository
        $actualClassCount = 0;
        foreach (self::$repository->all(SymbolType::Class_) as $_) {
            $actualClassCount++;
        }

        self::assertSame(
            $projectSymbolClassCount,
            $actualClassCount,
            "Project symbolClassCount ({$projectSymbolClassCount}) must equal "
            . "actual Class_ symbol count ({$actualClassCount}) — no double-counting",
        );

        // Additionally verify: no parent namespace double-counts classes from children.
        // Each leaf namespace's symbolClassCount should equal its direct Class_ symbols only.
        foreach (self::$namespaceTree->getLeaves() as $leafNs) {
            $leafBag = self::$repository->get(SymbolPath::forNamespace($leafNs));
            $leafClassCount = $leafBag->get('symbolClassCount');

            if ($leafClassCount === null) {
                continue;
            }

            $directClasses = 0;
            foreach (self::$repository->forNamespace($leafNs) as $symbolInfo) {
                if ($symbolInfo->symbolPath->getType() === SymbolType::Class_) {
                    $directClasses++;
                }
            }

            self::assertSame(
                $leafClassCount,
                $directClasses,
                "Leaf NS '{$leafNs}': symbolClassCount ({$leafClassCount}) must equal "
                . "direct Class_ symbol count ({$directClasses})",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 9. Cross-field consistency: avg = sum / count
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testCrossFieldConsistency(): void
    {
        // At namespace level, ccn.avg is the average over all "source" values:
        // class-level ccn.sum for each class, plus raw ccn for each standalone function.
        // The denominator is the number of sources, not symbolClassCount.
        foreach (self::$namespaceTree->getAllNamespaces() as $ns) {
            if (!$this->isFixtureNamespace($ns)) {
                continue;
            }

            $nsBag = self::$repository->get(SymbolPath::forNamespace($ns));
            $ccnAvg = $nsBag->get('ccn.avg');
            $ccnSum = $nsBag->get('ccn.sum');

            if ($ccnAvg === null || $ccnSum === null) {
                continue;
            }

            // Count the number of methods+functions in the subtree (ccn.avg is per-method)
            $allNamespaces = [$ns, ...self::$namespaceTree->getDescendants($ns)];
            $methodCount = 0;

            foreach ($allNamespaces as $subtreeNs) {
                foreach (self::$repository->forNamespace($subtreeNs) as $symbolInfo) {
                    $type = $symbolInfo->symbolPath->getType();
                    if ($type === SymbolType::Method) {
                        $methodBag = self::$repository->get($symbolInfo->symbolPath);
                        if ($methodBag->get('ccn') !== null) {
                            $methodCount++;
                        }
                    } elseif ($type === SymbolType::Function_) {
                        $fnBag = self::$repository->get($symbolInfo->symbolPath);
                        if ($fnBag->get('ccn') !== null) {
                            $methodCount++;
                        }
                    }
                }
            }

            if ($methodCount === 0) {
                continue;
            }

            $expectedAvg = $ccnSum / $methodCount;
            self::assertEqualsWithDelta(
                $expectedAvg,
                $ccnAvg,
                self::DELTA,
                "NS '{$ns}': ccn.avg ({$ccnAvg}) must equal ccn.sum / methodCount "
                . "({$ccnSum} / {$methodCount} = {$expectedAvg})",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 10. Percentile >= average
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testPercentileBounds(): void
    {
        // p95 must be within [min, max] range (not necessarily >= avg for skewed distributions)
        $symbolPaths = [];

        foreach (self::$namespaceTree->getAllNamespaces() as $ns) {
            if ($this->isFixtureNamespace($ns)) {
                $symbolPaths[] = SymbolPath::forNamespace($ns);
            }
        }

        $symbolPaths[] = SymbolPath::forProject();

        foreach ($symbolPaths as $symbolPath) {
            $bag = self::$repository->get($symbolPath);
            $ccnP95 = $bag->get('ccn.p95');
            $ccnMax = $bag->get('ccn.max');

            if ($ccnP95 === null || $ccnMax === null) {
                continue;
            }

            $label = $symbolPath->toString();
            self::assertLessThanOrEqual(
                $ccnMax,
                $ccnP95,
                "'{$label}': ccn.p95 ({$ccnP95}) must be <= ccn.max ({$ccnMax})",
            );
            self::assertGreaterThanOrEqual(
                0,
                $ccnP95,
                "'{$label}': ccn.p95 ({$ccnP95}) must be >= 0",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 11. Coupling consistency: namespace Ce <= class Ce sum
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testNamespaceCouplingConsistency(): void
    {
        foreach (self::$namespaceTree->getLeaves() as $ns) {
            if (!$this->isFixtureNamespace($ns)) {
                continue;
            }

            $nsBag = self::$repository->get(SymbolPath::forNamespace($ns));
            $nsCe = $nsBag->get('ce');
            $nsCeSum = $nsBag->get('ce.sum');

            if ($nsCe === null || $nsCeSum === null) {
                continue;
            }

            // Namespace-level Ce (computed from the dependency graph with internal deps collapsed)
            // should be <= sum of class-level Ce (since internal deps are not double-counted)
            self::assertLessThanOrEqual(
                $nsCeSum,
                $nsCe,
                "Leaf NS '{$ns}': namespace ce ({$nsCe}) must be <= ce.sum ({$nsCeSum}) "
                . '(internal dependencies collapse at namespace level)',
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 12. Non-additive metrics use correct aggregation strategy
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testNonAdditiveMetricsUseCorrectStrategy(): void
    {
        $checked = false;

        foreach (self::$namespaceTree->getAllNamespaces() as $ns) {
            if (!$this->isFixtureNamespace($ns)) {
                continue;
            }

            $nsBag = self::$repository->get(SymbolPath::forNamespace($ns));

            // Check that LCOM is aggregated via avg, NOT sum
            if ($nsBag->has('lcom.avg')) {
                self::assertFalse(
                    $nsBag->has('lcom.sum'),
                    "NS '{$ns}': lcom.sum must NOT exist — LCOM is non-additive and should use avg strategy",
                );
                $checked = true;
            }

            // Check that TCC is aggregated via avg, NOT sum
            if ($nsBag->has('tcc.avg')) {
                self::assertFalse(
                    $nsBag->has('tcc.sum'),
                    "NS '{$ns}': tcc.sum must NOT exist — TCC is non-additive and should use avg strategy",
                );
                $checked = true;
            }
        }

        self::assertTrue($checked, 'At least one namespace must have LCOM or TCC aggregated metrics');
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Checks if the namespace belongs to the fixture set (prefix GoldenMetrics\) or is the global namespace.
     */
    private function isFixtureNamespace(string $ns): bool
    {
        return $ns === '' || str_starts_with($ns, self::FIXTURE_NS_PREFIX);
    }
}
