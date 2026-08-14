<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score\ContributorRanker;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionMethod;

#[CoversClass(ContributorRanker::class)]
final class ContributorRankerTest extends TestCase
{
    private ContributorRanker $ranker;

    protected function setUp(): void
    {
        $this->ranker = new ContributorRanker();
    }

    #[Test]
    public function itReturnsEmptyForZeroLimit(): void
    {
        self::assertSame([], $this->ranker->rank([], 'lower', 0));
    }

    #[Test]
    public function itReturnsEmptyForNegativeLimit(): void
    {
        self::assertSame([], $this->ranker->rank([], 'lower', -1));
    }

    #[Test]
    public function itRejectsUnsupportedDirection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported contributor ranking direction: sideways.');

        (new ReflectionMethod($this->ranker, 'rank'))->invoke($this->ranker, [], 'sideways');
    }

    #[Test]
    public function itReturnsEmptyWhenNoCandidates(): void
    {
        self::assertSame([], $this->ranker->rank([], 'lower'));
    }

    #[Test]
    public function itSkipsClassesWithoutPrimaryMetric(): void
    {
        $candidate = $this->candidate('App', 'Empty', null, []);

        self::assertSame([], $this->ranker->rank([$candidate], 'lower'));
    }

    #[Test]
    public function itRanksClassesByPrimaryMetricDescendingForLowerIsBetter(): void
    {
        $result = $this->ranker->rank([
            $this->candidate('App', 'ClassA', 5.0, ['ccn.sum' => 5]),
            $this->candidate('App', 'ClassB', 15.0, ['ccn.sum' => 15]),
            $this->candidate('App', 'ClassC', 10.0, ['ccn.sum' => 10]),
        ], 'lower');

        self::assertCount(3, $result);
        self::assertSame('ClassB', $result[0]->className);
        self::assertSame('ClassC', $result[1]->className);
        self::assertSame('ClassA', $result[2]->className);
    }

    #[Test]
    public function itRanksClassesByPrimaryMetricAscendingForHigherIsBetter(): void
    {
        $result = $this->ranker->rank([
            $this->candidate('App', 'ClassA', 0.8, ['tcc' => 0.8]),
            $this->candidate('App', 'ClassB', 0.2, ['tcc' => 0.2]),
        ], 'higher');

        self::assertCount(2, $result);
        self::assertSame('ClassB', $result[0]->className);
        self::assertSame('ClassA', $result[1]->className);
    }

    #[Test]
    public function itRespectsLimit(): void
    {
        $result = $this->ranker->rank([
            $this->candidate('App', 'ClassA', 5.0, ['ccn.sum' => 5]),
            $this->candidate('App', 'ClassB', 15.0, ['ccn.sum' => 15]),
            $this->candidate('App', 'ClassC', 10.0, ['ccn.sum' => 10]),
        ], 'lower', limit: 2);

        self::assertCount(2, $result);
        self::assertSame('ClassB', $result[0]->className);
        self::assertSame('ClassC', $result[1]->className);
    }

    #[Test]
    public function itTiedPrimaryMetricSortsByCanonicalPath(): void
    {
        $result = $this->ranker->rank([
            $this->candidate('App\Beta', 'Service', 10.0, ['ccn.sum' => 10]),
            $this->candidate('App\Alpha', 'Service', 10.0, ['ccn.sum' => 10]),
        ], 'lower');

        self::assertCount(2, $result);
        self::assertSame('class:App\Alpha\Service', $result[0]->symbolPath);
        self::assertSame('class:App\Beta\Service', $result[1]->symbolPath);
    }

    #[Test]
    public function itContributorIncludesAllSelectedMetrics(): void
    {
        $result = $this->ranker->rank([
            $this->candidate('App', 'Service', 12.0, ['ccn.sum' => 12, 'cognitive.sum' => 8]),
        ], 'lower');

        self::assertCount(1, $result);
        self::assertSame('Service', $result[0]->className);
        self::assertSame(['ccn.sum' => 12, 'cognitive.sum' => 8], $result[0]->metricValues);
    }

    /**
     * @param array<string, int|float> $contributorMetrics
     *
     * @return array{symbol: SymbolInfo, primaryValue: float|null, contributorMetrics: array<string, int|float>}
     */
    private function candidate(
        string $namespace,
        string $class,
        ?float $primaryValue,
        array $contributorMetrics,
    ): array {
        $path = SymbolPath::forClass($namespace, $class);

        return [
            'symbol' => new SymbolInfo($path, RelativePath::fromString('src/' . $class . '.php'), null),
            'primaryValue' => $primaryValue,
            'contributorMetrics' => $contributorMetrics,
        ];
    }
}
