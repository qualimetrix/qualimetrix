<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Impact;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Impact\ClassRankIndex;
use Qualimetrix\Reporting\Impact\ClassRankResolver;

#[CoversClass(ClassRankResolver::class)]
final class ClassRankResolverTest extends TestCase
{
    private ClassRankResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ClassRankResolver();
    }

    #[Test]
    public function resolveForMethodViolation(): void
    {
        $classMetrics = (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.05);

        $metrics = self::createStub(MetricRepositoryInterface::class);
        $metrics->method('get')->willReturnCallback(
            static function (SymbolPath $sp) use ($classMetrics): MetricBag {
                if ($sp->toCanonical() === 'class:App\Service\UserService') {
                    return $classMetrics;
                }

                return new MetricBag();
            },
        );

        $violation = $this->createViolation(
            SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
        );

        $index = new ClassRankIndex([], [], null);
        self::assertSame(0.05, $this->resolver->resolve($violation, $metrics, $index));
    }

    #[Test]
    public function resolveForClassViolation(): void
    {
        $classMetrics = (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.12);

        $metrics = self::createStub(MetricRepositoryInterface::class);
        $metrics->method('get')->willReturnCallback(
            static function (SymbolPath $sp) use ($classMetrics): MetricBag {
                if ($sp->toCanonical() === 'class:App\Service\UserService') {
                    return $classMetrics;
                }

                return new MetricBag();
            },
        );

        $violation = $this->createViolation(
            SymbolPath::forClass('App\Service', 'UserService'),
        );

        $index = new ClassRankIndex([], [], null);
        self::assertSame(0.12, $this->resolver->resolve($violation, $metrics, $index));
    }

    #[Test]
    public function resolveForNamespaceViolationReturnsMaxClassRank(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);

        $metrics->method('getNamespaces')->willReturn(['App\Service']);

        $metrics->method('all')->willReturn([
            new SymbolInfo(SymbolPath::forClass('App\Service', 'UserService'), RelativePath::fromString('src/UserService.php'), 1),
            new SymbolInfo(SymbolPath::forClass('App\Service', 'OrderService'), RelativePath::fromString('src/OrderService.php'), 1),
            new SymbolInfo(SymbolPath::forClass('App\Service', 'LogService'), RelativePath::fromString('src/LogService.php'), 1),
        ]);

        $metrics->method('get')->willReturnCallback(
            static function (SymbolPath $sp): MetricBag {
                return match ($sp->toCanonical()) {
                    'class:App\Service\UserService' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.01),
                    'class:App\Service\OrderService' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.05),
                    'class:App\Service\LogService' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.03),
                    default => new MetricBag(),
                };
            },
        );

        $violation = $this->createViolation(SymbolPath::forNamespace('App\Service'));

        $index = $this->resolver->buildIndex($metrics);
        self::assertSame(0.05, $this->resolver->resolve($violation, $metrics, $index));
    }

    #[Test]
    public function resolveForNamespaceIncludesSubNamespaces(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);

        $metrics->method('getNamespaces')->willReturn(['App\Service', 'App\Service\Sub']);

        $metrics->method('all')->willReturn([
            new SymbolInfo(SymbolPath::forClass('App\Service', 'UserService'), RelativePath::fromString('src/UserService.php'), 1),
            new SymbolInfo(SymbolPath::forClass('App\Service\Sub', 'DeepService'), RelativePath::fromString('src/Sub/DeepService.php'), 1),
        ]);

        $metrics->method('get')->willReturnCallback(
            static function (SymbolPath $sp): MetricBag {
                return match ($sp->toCanonical()) {
                    'class:App\Service\UserService' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.02),
                    'class:App\Service\Sub\DeepService' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.08),
                    default => new MetricBag(),
                };
            },
        );

        $violation = $this->createViolation(SymbolPath::forNamespace('App\Service'));

        $index = $this->resolver->buildIndex($metrics);
        self::assertSame(0.08, $this->resolver->resolve($violation, $metrics, $index));
    }

    #[Test]
    public function resolveForFileViolationReturnsMaxClassRankInFile(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);

        $metrics->method('getNamespaces')->willReturn(['App']);

        $metrics->method('all')->willReturn([
            new SymbolInfo(SymbolPath::forClass('App', 'ClassA'), RelativePath::fromString('src/target.php'), 1),
            new SymbolInfo(SymbolPath::forClass('App', 'ClassB'), RelativePath::fromString('src/target.php'), 20),
            new SymbolInfo(SymbolPath::forClass('App', 'ClassC'), RelativePath::fromString('src/other.php'), 1),
        ]);

        $metrics->method('get')->willReturnCallback(
            static function (SymbolPath $sp): MetricBag {
                return match ($sp->toCanonical()) {
                    'class:App\ClassA' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.01),
                    'class:App\ClassB' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.07),
                    'class:App\ClassC' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.99),
                    default => new MetricBag(),
                };
            },
        );

        $violation = $this->createViolation(SymbolPath::forFile(RelativePath::fromString('src/target.php')));

        $index = $this->resolver->buildIndex($metrics);
        self::assertSame(0.07, $this->resolver->resolve($violation, $metrics, $index));
    }

    #[Test]
    public function resolveSkipsClassesWithNullFileWhenBuildingFileIndex(): void
    {
        // ADR 0015 Phase 1c regression pin: pre-migration, CouplingCollector added
        // class symbols with file='', which produced spurious $fileIndex[''] entries
        // matched by violations with empty file path. After Phase 1c, the file is null
        // (no inherent owning file for graph-derived metrics) and is skipped during
        // index building. Lookups for a file path that's not in the index now return null.
        $metrics = self::createStub(MetricRepositoryInterface::class);

        $metrics->method('getNamespaces')->willReturn(['App']);
        $metrics->method('all')->willReturn([
            // File present — should land in the file index.
            new SymbolInfo(SymbolPath::forClass('App', 'WithFile'), RelativePath::fromString('src/WithFile.php'), 1),
            // File absent — must NOT poison the file index with a phantom entry.
            new SymbolInfo(SymbolPath::forClass('App', 'WithoutFile'), null, 0),
        ]);
        $metrics->method('get')->willReturnCallback(
            static fn(SymbolPath $sp): MetricBag => match ($sp->toCanonical()) {
                'class:App\WithFile' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.05),
                'class:App\WithoutFile' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.99),
                default => new MetricBag(),
            },
        );

        $index = $this->resolver->buildIndex($metrics);

        // The lookup for a file present in the index returns its rank.
        self::assertSame(
            0.05,
            $this->resolver->resolve(
                $this->createViolation(SymbolPath::forFile(RelativePath::fromString('src/WithFile.php'))),
                $metrics,
                $index,
            ),
        );

        // A file lookup that has no entry — and the null-file class is NOT a fallback — returns null.
        self::assertNull(
            $this->resolver->resolve(
                $this->createViolation(SymbolPath::forFile(RelativePath::fromString('src/Unknown.php'))),
                $metrics,
                $index,
            ),
        );
    }

    #[Test]
    public function resolveReturnsNullWhenNoClassRankMetric(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $metrics->method('get')->willReturn(new MetricBag());

        $violation = $this->createViolation(
            SymbolPath::forClass('App\Service', 'UserService'),
        );

        $index = new ClassRankIndex([], [], null);
        self::assertNull($this->resolver->resolve($violation, $metrics, $index));
    }

    #[Test]
    public function resolveReturnsNullForProjectViolation(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);

        $violation = $this->createViolation(SymbolPath::forProject());

        $index = new ClassRankIndex([], [], null);
        self::assertNull($this->resolver->resolve($violation, $metrics, $index));
    }

    #[Test]
    public function resolveReturnsNullForFunctionViolation(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);

        $violation = $this->createViolation(
            SymbolPath::forGlobalFunction('App\Utils', 'helper'),
        );

        $index = new ClassRankIndex([], [], null);
        self::assertNull($this->resolver->resolve($violation, $metrics, $index));
    }

    #[Test]
    public function resolveFiltersNanAndInfinite(): void
    {
        $nanMetrics = (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, \NAN);
        $infMetrics = (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, \INF);

        $metrics = self::createStub(MetricRepositoryInterface::class);

        // Test NAN
        $metrics->method('get')->willReturn($nanMetrics);

        $violation = $this->createViolation(
            SymbolPath::forClass('App', 'NanClass'),
        );

        $index = new ClassRankIndex([], [], null);
        self::assertNull($this->resolver->resolve($violation, $metrics, $index));

        // Test INF with a fresh mock
        $metricsInf = self::createStub(MetricRepositoryInterface::class);
        $metricsInf->method('get')->willReturn($infMetrics);

        $violationInf = $this->createViolation(
            SymbolPath::forClass('App', 'InfClass'),
        );

        $indexInf = new ClassRankIndex([], [], null);
        self::assertNull($this->resolver->resolve($violationInf, $metricsInf, $indexInf));
    }

    private function createViolation(SymbolPath $symbolPath): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('test.php'), 1),
            symbolPath: $symbolPath,
            ruleName: 'test.rule',
            violationCode: 'test.rule',
            message: 'Test message',
            severity: Severity::Warning,
        );
    }
}
