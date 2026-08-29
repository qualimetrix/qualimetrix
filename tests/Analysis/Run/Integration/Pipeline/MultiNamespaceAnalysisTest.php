<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Integration\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Cohesion\Runtime\LcomCollectionConfigurationStore;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Size\ClassCountCollector;
use Qualimetrix\Analysis\Evidence\Size\LocCollector;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;

final class MultiNamespaceAnalysisTest extends TestCase
{
    private string $filePath;
    private string $globalStatementFilePath;

    protected function setUp(): void
    {
        $this->filePath = sys_get_temp_dir() . '/qmx_multi_namespace_' . uniqid() . '.php';
        $written = file_put_contents($this->filePath, <<<'PHP'
<?php
namespace One { class A {} }
namespace Two { class B {} }
namespace FunctionsOnly { function run(): void {} }
namespace Empty {}
PHP);
        if ($written === false) {
            throw new RuntimeException('Failed to create multi-namespace fixture');
        }

        $this->globalStatementFilePath = sys_get_temp_dir() . '/qmx_global_statement_' . uniqid() . '.php';
        $written = file_put_contents($this->globalStatementFilePath, <<<'PHP'
<?php
declare(strict_types=1);

require __DIR__ . '/functions.php';
PHP);
        if ($written === false) {
            throw new RuntimeException('Failed to create global statement fixture');
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->filePath)) {
            unlink($this->filePath);
        }
        if (is_file($this->globalStatementFilePath)) {
            unlink($this->globalStatementFilePath);
        }
    }

    #[TestWith([0])]
    #[TestWith([1])]
    #[TestWith([2])]
    #[Test]
    public function itAnalyzesEveryNamespaceBlockWithoutLosingMetrics(int $workers): void
    {
        $process = new Process([
            \PHP_BINARY,
            'bin/qmx',
            'check',
            $this->filePath,
            '--only-rule=size.class-count',
            '--workers=' . $workers,
            '--no-cache',
            '--no-progress',
            '--format=json',
        ], \dirname(__DIR__, 5));
        $process->mustRun();

        /** @var array<string, mixed> $report */
        $report = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1, $report['summary']['filesAnalyzed']);
        self::assertSame(0, $report['summary']['filesSkipped']);

        $namespaces = array_column($report['worstNamespaces'], 'symbolPath');
        self::assertContains('One', $namespaces);
        self::assertContains('Two', $namespaces);
    }

    #[Test]
    public function itMergesCompleteMetricFamiliesForAStatementOnlyGlobalNamespace(): void
    {
        $process = new Process([
            \PHP_BINARY,
            'bin/qmx',
            'check',
            $this->globalStatementFilePath,
            '--only-rule=coupling.distance',
            '--distance-warning=2',
            '--distance-error=2',
            '--workers=0',
            '--no-cache',
            '--no-progress',
            '--format=metrics',
        ], \dirname(__DIR__, 5));
        $process->mustRun();

        /** @var array{symbols: list<array{type: string, name: string, metrics: array<string, int|float>}>} $report */
        $report = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        $globalNamespace = array_values(array_filter(
            $report['symbols'],
            static fn(array $symbol): bool => $symbol['type'] === 'namespace' && $symbol['name'] === '(global)',
        ));

        self::assertCount(1, $globalNamespace);
        self::assertSame(0, $globalNamespace[0]['metrics']['size.class-count.sum']);
        self::assertSame(0, $globalNamespace[0]['metrics']['size.abstract-class-count.sum']);
        self::assertSame(1, $globalNamespace[0]['metrics']['size.class-count.count']);
        self::assertGreaterThan(0, $globalNamespace[0]['metrics']['size.loc.sum']);
    }

    #[Test]
    public function itPreservesNamespaceContributionsAcrossTheWorkerWire(): void
    {
        $fixtureDir = sys_get_temp_dir() . '/qmx_parallel_namespaces_' . uniqid();
        if (!mkdir($fixtureDir)) {
            throw new RuntimeException('Failed to create parallel fixture directory');
        }

        $paths = [];
        $expectedPhysicalLoc = 0;

        try {
            for ($index = 0; $index < 100; ++$index) {
                $path = $fixtureDir . '/File' . $index . '.php';
                $content = $index === 0
                    ? <<<'PHP'
<?php
namespace One { class A {} }
namespace Two { class B {} }
namespace FunctionsOnly { function run(): void {} }
namespace Empty {}
PHP
                    : \sprintf("<?php\nnamespace Filler%d { class C%d {} }\n", $index, $index);
                if (file_put_contents($path, $content) === false) {
                    throw new RuntimeException('Failed to create parallel fixture file');
                }
                $paths[] = $path;
                $expectedPhysicalLoc += substr_count($content, "\n") + 1;
            }

            $strategy = new AmphpParallelStrategy(new FileProcessingTaskFactory(
                new LcomCollectionConfigurationStore(),
                DependencyVisitor::class,
                [LocCollector::class, ClassCountCollector::class],
            ));
            self::assertTrue($strategy->isParallelAvailable(), 'The IPC integration requires amphp workers');
            $strategy->setWorkerCount(2);
            $canonicalFixtureDir = realpath($fixtureDir);
            self::assertIsString($canonicalFixtureDir);
            $strategy->setProjectRoot(AbsolutePath::fromString($canonicalFixtureDir));

            $results = $strategy->execute(
                array_map(static fn(string $path): SplFileInfo => new SplFileInfo($path), $paths),
                static fn(): never => throw new RuntimeException('Sequential fallback executed'),
            );

            self::assertCount(100, $results);
            self::assertSame(
                $expectedPhysicalLoc,
                array_sum(array_map(
                    static fn(FileProcessingResult $result): int => (int) $result->fileBag()->get(MetricName::SIZE_LOC),
                    $results,
                )),
                'Physical file LOC must be counted exactly once across worker results',
            );

            $target = array_values(array_filter(
                $results,
                static fn(FileProcessingResult $result): bool => $result->filePath->value() === 'File0.php',
            ));
            self::assertCount(1, $target);
            $namespaceMetrics = $target[0]->namespaceMetrics();

            self::assertSame(1, $namespaceMetrics['ns:One']['metrics']->get(MetricName::SIZE_CLASS_COUNT));
            self::assertSame(1, $namespaceMetrics['ns:Two']['metrics']->get(MetricName::SIZE_CLASS_COUNT));
            self::assertSame(0, $namespaceMetrics['ns:FunctionsOnly']['metrics']->get(MetricName::SIZE_CLASS_COUNT));
            self::assertSame(1, $namespaceMetrics['ns:FunctionsOnly']['metrics']->get(MetricName::SIZE_FUNCTION_COUNT));
            self::assertSame(0, $namespaceMetrics['ns:Empty']['metrics']->get(MetricName::SIZE_CLASS_COUNT));
            self::assertGreaterThan(0, $namespaceMetrics['ns:FunctionsOnly']['metrics']->get(MetricName::SIZE_LOC));
            self::assertGreaterThan(0, $namespaceMetrics['ns:Empty']['metrics']->get(MetricName::SIZE_LOC));
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($fixtureDir);
        }
    }
}
