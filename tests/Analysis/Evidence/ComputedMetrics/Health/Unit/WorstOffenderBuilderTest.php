<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderBuilder;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(WorstOffenderBuilder::class)]
final class WorstOffenderBuilderTest extends TestCase
{
    #[Test]
    public function itSelectsClassesByNamespaceBoundary(): void
    {
        $offenders = (new WorstOffenderBuilder())->buildWorstClasses(
            $this->snapshots(),
            'App\\Service',
            [],
            60.0,
            40.0,
        );

        self::assertSame(['Worker'], $this->typesOf($offenders));
    }

    #[Test]
    public function itIgnoresATrailingBackslashInTheSelector(): void
    {
        $offenders = (new WorstOffenderBuilder())->buildWorstClasses(
            $this->snapshots(),
            'App\\Service\\',
            [],
            60.0,
            40.0,
        );

        self::assertSame(['Worker'], $this->typesOf($offenders));
    }

    #[Test]
    public function itMatchesGlobSelectors(): void
    {
        $offenders = (new WorstOffenderBuilder())->buildWorstClasses(
            $this->snapshots(),
            'App\\*',
            [],
            60.0,
            40.0,
        );

        self::assertSame(['Worker', 'Bus', 'Other'], $this->typesOf($offenders));
    }

    /**
     * @return list<array{symbol: SymbolInfo, overall: float|null, dimensionScores: array<string, float>, 'size.loc': int|float|null, notableMetrics: array<string, int|float>}>
     */
    private function snapshots(): array
    {
        return [
            $this->snapshot('App\\Service', 'Worker'),
            $this->snapshot('App\\ServiceBus', 'Bus'),
            $this->snapshot('App\\Other', 'Other'),
        ];
    }

    /**
     * @return array{symbol: SymbolInfo, overall: float|null, dimensionScores: array<string, float>, 'size.loc': int|float|null, notableMetrics: array<string, int|float>}
     */
    private function snapshot(string $namespace, string $class): array
    {
        return [
            'symbol' => new SymbolInfo(
                SymbolPath::forClass($namespace, $class),
                RelativePath::fromString('src/' . $class . '.php'),
                1,
            ),
            'overall' => 50.0,
            'dimensionScores' => ['complexity' => 50.0],
            'size.loc' => 100,
            'notableMetrics' => [],
        ];
    }

    /**
     * @param list<\Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender> $offenders
     *
     * @return list<string|null>
     */
    private function typesOf(array $offenders): array
    {
        return array_map(static fn($offender): ?string => $offender->symbolPath->type, $offenders);
    }
}
