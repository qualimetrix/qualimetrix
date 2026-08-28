<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Reporting\Formatter\MetricsJsonFormatter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;
use ReflectionClass;

#[CoversClass(MetricsJsonFormatter::class)]
final class MetricsJsonFormatterTest extends TestCase
{
    private MetricsJsonFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new MetricsJsonFormatter();
    }

    #[Test]
    public function itReturnsMetricsName(): void
    {
        self::assertSame('metrics', $this->formatter->getName());
    }

    #[Test]
    public function itReturnsDefaultGroupByNone(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    #[Test]
    public function itFormatsWithNullMetrics(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.5,
            errorCount: 0,
            warningCount: 0,
            metrics: null,
        );

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('1.0.0', $data['version']);
        self::assertSame('qmx', $data['package']);
        self::assertArrayHasKey('timestamp', $data);
        self::assertSame([], $data['symbols']);
        self::assertSame(1, $data['summary']['filesAnalyzed']);
        self::assertSame(0, $data['summary']['filesSkipped']);
        self::assertSame(0.5, $data['summary']['duration']);
        self::assertSame(0, $data['summary']['violations']);
        self::assertSame(0, $data['summary']['errors']);
        self::assertSame(0, $data['summary']['warnings']);
    }

    #[Test]
    public function itFormatsWithMetrics(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $methodPath = SymbolPath::forMethod('App\\Service', 'UserService', 'calculate');

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(static function (SymbolLevel $level) use ($classPath, $methodPath): array {
                if ($level === SymbolLevel::Class_) {
                    return [new SymbolInfo($classPath, RelativePath::fromString('src/Service/UserService.php'), 10)];
                }
                if ($level === SymbolLevel::Callable) {
                    return [new SymbolInfo($methodPath, RelativePath::fromString('src/Service/UserService.php'), 42)];
                }

                return [];
            });

        $repository->method('get')
            ->willReturnCallback(static function (SymbolPath $path) use ($classPath): MetricBag {
                if ($path === $classPath) {
                    return MetricBag::fromArray(['size.method-count' => 5, 'complexity.ccn.sum' => 25]);
                }

                return MetricBag::fromArray(['complexity.ccn' => 12, 'code-smell.parameter-count' => 3]);
            });

        $report = new Report(
            findings: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.5,
            errorCount: 0,
            warningCount: 0,
            metrics: $repository,
        );

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(2, $data['symbols']);

        // Class symbol
        $classSymbol = $data['symbols'][0];
        self::assertSame('class', $classSymbol['type']);
        self::assertSame('App\\Service\\UserService', $classSymbol['name']);
        self::assertSame('src/Service/UserService.php', $classSymbol['file']);
        self::assertSame(10, $classSymbol['line']);
        self::assertSame(5, $classSymbol['metrics']['size.method-count']);
        self::assertSame(25, $classSymbol['metrics']['complexity.ccn.sum']);

        // Method symbol
        $methodSymbol = $data['symbols'][1];
        self::assertSame('method', $methodSymbol['type']);
        self::assertSame('App\\Service\\UserService::calculate', $methodSymbol['name']);
        self::assertSame('src/Service/UserService.php', $methodSymbol['file']);
        self::assertSame(42, $methodSymbol['line']);
        self::assertSame(12, $methodSymbol['metrics']['complexity.ccn']);
        self::assertSame(3, $methodSymbol['metrics']['code-smell.parameter-count']);
    }

    /**
     * Losing this: the export publishes the level word instead of the
     * declaration kind, or lets the repository's callable order decide where
     * a global function lands among the methods.
     */
    #[Test]
    public function itPublishesTheDeclarationKindAndKeepsTheBucketOrder(): void
    {
        $filePath = SymbolPath::forFile(RelativePath::fromString('src/Service/UserService.php'));
        $projectPath = SymbolPath::forProject();
        $namespacePath = SymbolPath::forNamespace('App\\Service');
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $methodPath = SymbolPath::forMethod('App\\Service', 'UserService', 'calculate');
        $functionPath = SymbolPath::forGlobalFunction('App\\Service', 'helper');

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(static function (SymbolLevel $level) use (
                $filePath,
                $projectPath,
                $namespacePath,
                $classPath,
                $methodPath,
                $functionPath,
            ): array {
                $file = RelativePath::fromString('src/Service/UserService.php');

                return match ($level) {
                    SymbolLevel::File => [new SymbolInfo($filePath, $file, 1)],
                    SymbolLevel::Project => [new SymbolInfo($projectPath, null, null)],
                    SymbolLevel::Namespace_ => [new SymbolInfo($namespacePath, null, null)],
                    SymbolLevel::Class_ => [new SymbolInfo($classPath, $file, 10)],
                    // The function first: one enumeration holds both kinds, and
                    // the published order must not follow this one.
                    SymbolLevel::Callable => [
                        new SymbolInfo($functionPath, $file, 80),
                        new SymbolInfo($methodPath, $file, 42),
                    ],
                };
            });

        // Every symbol must carry a metric: one with an empty bag is skipped
        // outright, so a fixture without metrics passes under any ordering.
        $repository->method('get')->willReturn(MetricBag::fromArray(['complexity.ccn' => 1]));

        $report = new Report(
            findings: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.1,
            errorCount: 0,
            warningCount: 0,
            metrics: $repository,
        );

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(
            ['file', 'project', 'namespace', 'class', 'method', 'function'],
            array_column($data['symbols'], 'type'),
        );
        self::assertSame('App\\Service\\UserService::calculate', $data['symbols'][4]['name']);
        self::assertSame('App\\Service\\helper', $data['symbols'][5]['name']);
    }

    #[Test]
    public function itSkipsEmptyMetrics(): void
    {
        $classPath = SymbolPath::forClass('App', 'Empty');

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(static function (SymbolLevel $level) use ($classPath): array {
                if ($level === SymbolLevel::Class_) {
                    return [new SymbolInfo($classPath, RelativePath::fromString('src/Empty.php'), 1)];
                }

                return [];
            });

        $repository->method('get')
            ->willReturn(MetricBag::fromArray([]));

        $report = new Report(
            findings: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.1,
            errorCount: 0,
            warningCount: 0,
            metrics: $repository,
        );

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame([], $data['symbols']);
    }

    #[Test]
    public function itFiltersNonFiniteValues(): void
    {
        $classPath = SymbolPath::forClass('App', 'Test');

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(static function (SymbolLevel $level) use ($classPath): array {
                if ($level === SymbolLevel::Class_) {
                    return [new SymbolInfo($classPath, RelativePath::fromString('src/Test.php'), 1)];
                }

                return [];
            });

        $repository->method('get')
            ->willReturn(MetricBag::fromArray([
                'valid' => 42,
                'nan' => \NAN,
                'inf' => \INF,
                'neg_inf' => -\INF,
                'float' => 3.14,
            ]));

        $report = new Report(
            findings: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.1,
            errorCount: 0,
            warningCount: 0,
            metrics: $repository,
        );

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(1, $data['symbols']);
        $metrics = $data['symbols'][0]['metrics'];
        self::assertSame(42, $metrics['valid']);
        self::assertSame(3.14, $metrics['float']);
        // Non-finite values should be replaced with null, not dropped
        self::assertArrayHasKey('nan', $metrics);
        self::assertNull($metrics['nan']);
        self::assertArrayHasKey('inf', $metrics);
        self::assertNull($metrics['inf']);
        self::assertArrayHasKey('neg_inf', $metrics);
        self::assertNull($metrics['neg_inf']);
    }

    #[Test]
    public function itFiltersInternalDerivedMetricKeys(): void
    {
        $filePath = SymbolPath::forFile(RelativePath::fromString('src/Service/UserService.php'));

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(static function (SymbolLevel $level) use ($filePath): array {
                if ($level === SymbolLevel::File) {
                    return [new SymbolInfo($filePath, RelativePath::fromString('src/Service/UserService.php'), 1)];
                }

                return [];
            });

        $repository->method('get')
            ->willReturn(MetricBag::fromArray([
                'size.loc' => 100,
                'complexity.ccn' => 5,
                'complexity.ccn:App\Service\UserService::calculate' => 12,
                'complexity.npath:App\Service\UserService::process' => 42,
            ]));

        $report = new Report(
            findings: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.1,
            errorCount: 0,
            warningCount: 0,
            metrics: $repository,
        );

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(1, $data['symbols']);
        $metrics = $data['symbols'][0]['metrics'];

        // Public metrics should be present
        self::assertArrayHasKey('size.loc', $metrics);
        self::assertArrayHasKey('complexity.ccn', $metrics);

        // Internal derived-metric keys containing ':' should be filtered out
        \assert(\is_array($metrics));
        foreach (array_keys($metrics) as $key) {
            self::assertStringNotContainsString(':', (string) $key, "Internal key '{$key}' should not appear in output");
        }
    }

    #[Test]
    public function itProducesValidJson(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 0,
            filesSkipped: 0,
            duration: 0.0,
            errorCount: 0,
            warningCount: 0,
        );

        $output = $this->formatter->format($report, new FormatterContext());

        // Should not throw
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
    }

    /** A declaration kind absent from the publication order is dropped from the export. */
    #[Test]
    public function itGivesEveryDeclarationKindAPublicationPosition(): void
    {
        $kinds = (new ReflectionClass(MetricsJsonFormatter::class))->getConstant('DECLARATION_KINDS');

        self::assertIsArray($kinds);
        self::assertEqualsCanonicalizing(SymbolType::cases(), $kinds);
    }
}
