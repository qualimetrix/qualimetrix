<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog;
use Qualimetrix\Core\Symbol\SymbolLevel;

#[CoversClass(HealthDecompositionCatalog::class)]
final class HealthDecompositionCatalogTest extends TestCase
{
    private HealthDecompositionCatalog $provider;

    protected function setUp(): void
    {
        $this->provider = new HealthDecompositionCatalog();
    }

    #[Test]
    public function itGetDecompositionKnownDimension(): void
    {
        self::assertSame(
            ['complexity.ccn.avg', 'complexity.cognitive.avg', 'complexity.ccn.p95', 'complexity.cognitive.p95', 'complexity.ccn.max'],
            $this->provider->getDecomposition('health.complexity', SymbolLevel::Project),
        );
        self::assertSame(['cohesion.tcc.avg', 'cohesion.lcom.avg'], $this->provider->getDecomposition('health.cohesion', SymbolLevel::Project));
        self::assertSame(
            ['coupling.distance.avg', 'coupling.cbo.avg', 'coupling.cbo.p95', 'coupling.cbo.max'],
            $this->provider->getDecomposition('health.coupling', SymbolLevel::Project),
        );
        self::assertSame([], $this->provider->getDecomposition('health.typing', SymbolLevel::Project));
        self::assertSame(
            ['maintainability.mi.avg', 'maintainability.mi.p5', 'maintainability.mi.min'],
            $this->provider->getDecomposition('health.maintainability', SymbolLevel::Project),
        );
        self::assertSame([], $this->provider->getDecomposition('health.overall', SymbolLevel::Project));
    }

    #[Test]
    public function itGetDecompositionAnswersPerLevel(): void
    {
        // Coupling is the dimension where the levels disagree most: the class
        // formula reads Ce, the namespace one Ce aggregates plus distance, the
        // project one CBO aggregates.
        self::assertSame(['coupling.ce-packages', 'coupling.ce'], $this->provider->getDecomposition('health.coupling', SymbolLevel::Class_));
        self::assertSame(
            ['coupling.distance', 'coupling.ce-packages.avg', 'coupling.ce.avg', 'coupling.ce.max', 'coupling.ce'],
            $this->provider->getDecomposition('health.coupling', SymbolLevel::Namespace_),
        );
    }

    #[Test]
    public function itInheritsTheNamespaceListAtProjectLevelWhenThereIsNoProjectFormula(): void
    {
        // Mirrors ComputedMetricDefinition::getFormulaForLevel(): cohesion has
        // no project formula, so the project score is the namespace formula and
        // its inputs are the namespace list.
        self::assertSame(
            $this->provider->getDecomposition('health.cohesion', SymbolLevel::Namespace_),
            $this->provider->getDecomposition('health.cohesion', SymbolLevel::Project),
        );
    }

    #[Test]
    public function itRanksContributorsByClassLevelKeys(): void
    {
        self::assertSame(
            ['complexity.ccn.sum', 'complexity.cognitive.sum', 'complexity.ccn.p95', 'complexity.cognitive.p95'],
            array_column($this->provider->getDecompositionForClasses('health.complexity'), 'classKey'),
        );
    }

    #[Test]
    public function itSelectsContributorMetrics(): void
    {
        self::assertSame(
            [
                'primaryValue' => 12.0,
                'contributorMetrics' => ['complexity.ccn.sum' => 12, 'complexity.cognitive.sum' => 8],
            ],
            $this->provider->selectContributorMetrics(
                [
                    ['classKey' => 'complexity.ccn.sum', 'direction' => 'lower'],
                    ['classKey' => 'complexity.cognitive.sum', 'direction' => 'lower'],
                    ['classKey' => 'missing', 'direction' => 'lower'],
                ],
                static fn(string $key): ?int => [
                    'complexity.ccn.sum' => 12,
                    'complexity.cognitive.sum' => 8,
                ][$key] ?? null,
            ),
        );
    }

    #[Test]
    public function itGetDecompositionUnknownDimension(): void
    {
        self::assertSame([], $this->provider->getDecomposition('health.unknown', SymbolLevel::Project));
        self::assertSame([], $this->provider->getDecompositionForClasses('health.unknown'));
    }

    #[Test]
    public function itShipsEveryLevelResolved(): void
    {
        foreach ($this->provider->healthDecomposition() as $dimension => $data) {
            foreach (['class', 'namespace', 'project'] as $level) {
                self::assertArrayHasKey($level, $data['levels'], "{$dimension} missing level {$level}");
            }
        }
    }
}
