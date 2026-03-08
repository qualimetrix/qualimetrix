<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Reporting\Formatter\MetricsJsonFormatter;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricsJsonFormatter::class)]
final class MetricsJsonFormatterTest extends TestCase
{
    private MetricsJsonFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new MetricsJsonFormatter();
    }

    public function testGetName(): void
    {
        self::assertSame('metrics-json', $this->formatter->getName());
    }

    public function testGetDefaultGroupBy(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    public function testFormatWithNullMetrics(): void
    {
        $report = new Report(
            violations: [],
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
        self::assertSame('aimd', $data['package']);
        self::assertArrayHasKey('timestamp', $data);
        self::assertSame([], $data['symbols']);
        self::assertSame(1, $data['summary']['filesAnalyzed']);
        self::assertSame(0, $data['summary']['filesSkipped']);
        self::assertSame(0.5, $data['summary']['duration']);
        self::assertSame(0, $data['summary']['violations']);
        self::assertSame(0, $data['summary']['errors']);
        self::assertSame(0, $data['summary']['warnings']);
    }

    public function testFormatWithMetrics(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $methodPath = SymbolPath::forMethod('App\\Service', 'UserService', 'calculate');

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(static function (SymbolType $type) use ($classPath, $methodPath): array {
                if ($type === SymbolType::Class_) {
                    return [new SymbolInfo($classPath, 'src/Service/UserService.php', 10)];
                }
                if ($type === SymbolType::Method) {
                    return [new SymbolInfo($methodPath, 'src/Service/UserService.php', 42)];
                }

                return [];
            });

        $repository->method('get')
            ->willReturnCallback(static function (SymbolPath $path) use ($classPath): MetricBag {
                if ($path === $classPath) {
                    return MetricBag::fromArray(['methodCount' => 5, 'ccn.sum' => 25]);
                }

                return MetricBag::fromArray(['ccn' => 12, 'parameterCount' => 3]);
            });

        $report = new Report(
            violations: [],
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
        self::assertSame(5, $classSymbol['metrics']['methodCount']);
        self::assertSame(25, $classSymbol['metrics']['ccn.sum']);

        // Method symbol
        $methodSymbol = $data['symbols'][1];
        self::assertSame('method', $methodSymbol['type']);
        self::assertSame('App\\Service\\UserService::calculate', $methodSymbol['name']);
        self::assertSame('src/Service/UserService.php', $methodSymbol['file']);
        self::assertSame(42, $methodSymbol['line']);
        self::assertSame(12, $methodSymbol['metrics']['ccn']);
        self::assertSame(3, $methodSymbol['metrics']['parameterCount']);
    }

    public function testFormatSkipsEmptyMetrics(): void
    {
        $classPath = SymbolPath::forClass('App', 'Empty');

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(static function (SymbolType $type) use ($classPath): array {
                if ($type === SymbolType::Class_) {
                    return [new SymbolInfo($classPath, 'src/Empty.php', 1)];
                }

                return [];
            });

        $repository->method('get')
            ->willReturn(MetricBag::fromArray([]));

        $report = new Report(
            violations: [],
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

    public function testFormatFiltersNonFiniteValues(): void
    {
        $classPath = SymbolPath::forClass('App', 'Test');

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(static function (SymbolType $type) use ($classPath): array {
                if ($type === SymbolType::Class_) {
                    return [new SymbolInfo($classPath, 'src/Test.php', 1)];
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
            violations: [],
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
        self::assertArrayNotHasKey('nan', $metrics);
        self::assertArrayNotHasKey('inf', $metrics);
        self::assertArrayNotHasKey('neg_inf', $metrics);
    }

    public function testJsonIsValid(): void
    {
        $report = new Report(
            violations: [],
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
}
