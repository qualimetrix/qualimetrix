<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * An executable index of ADR 0017's residual limitations.
 *
 * The limitation list is the canonical source, so an added or removed item
 * fails until the map changes in the same patch.
 */
final class ResidualLimitationsCoverageTest extends TestCase
{
    /**
     * @var array<int, list<array{class: class-string, method: string}>>
     */
    private const array CASES = [
        1 => [[
            'class' => \Qualimetrix\Tests\Analysis\Policy\Baseline\Unit\BaselineCeilingStageAcceptanceTest::class,
            'method' => 'itAcceptsAGroupWhoseMembersSwappedAtEqualMagnitude',
        ]],
        2 => [[
            'class' => \Qualimetrix\Tests\Infrastructure\Console\Unit\ViolationFilterOrchestratorBaselineReportingTest::class,
            'method' => 'itDoesNotCountAShrunkButPresentGroupAsResolved',
        ]],
        3 => [[
            'class' => \Qualimetrix\Tests\Analysis\Policy\Baseline\Unit\BaselineCeilingStageAcceptanceTest::class,
            'method' => 'itAcceptsACompoundRuleWhoseNonTallyContextChangedWithoutMovingTheTally',
        ]],
        4 => [[
            'class' => \Qualimetrix\Tests\Analysis\Policy\Baseline\Unit\BaselineCeilingStagePromotionTest::class,
            'method' => 'itReportsFourErrorsWhenAGroupOfFourExceedsACountOfThree',
        ]],
        5 => [[
            'class' => \Qualimetrix\Tests\Analysis\Policy\Baseline\Functional\BaselineExplainCommandTest::class,
            'method' => 'itPrintsTheStoredAndTheCurrentNumberOnADriftingChannel',
        ]],
        6 => [[
            'class' => NpathSaturationCeilingTest::class,
            'method' => 'itAcceptsTheSameSaturatedValueFromTwoIncreasinglyWorseSources',
        ]],
        7 => [[
            'class' => \Qualimetrix\Tests\Analysis\Policy\Baseline\Unit\BaselineCeilingStageFailSafeTest::class,
            'method' => 'itReportsARenamedSymbolAndStrandsItsEntry',
        ]],
        8 => [[
            'class' => \Qualimetrix\Tests\Analysis\Policy\Baseline\Functional\BaselineLifecycleTest::class,
            'method' => 'itKeepsADuplicateAcceptedWhenThePrimaryCopyChanges',
        ]],
        9 => [[
            'class' => \Qualimetrix\Tests\Analysis\Policy\Baseline\Unit\BaselineGeneratorTest::class,
            'method' => 'itSeparatesSameFqnFindingsWithDifferentDeclarationSubjects',
        ]],
        10 => [[
            'class' => CboAggregateBreachTest::class,
            'method' => 'itBreachesAClassCboEntryAfterAnotherFileChanges',
        ]],
        11 => [[
            'class' => \Qualimetrix\Tests\Analysis\Policy\Baseline\Unit\BaselineCeilingStageAcceptanceTest::class,
            'method' => 'itFormsOneGroupFromTwoProjectKeyedDiagnosticsOfOneChannel',
        ]],
        12 => [
            [
                'class' => \Qualimetrix\Tests\Analysis\Policy\Baseline\Unit\BaselineCeilingStageAcceptanceTest::class,
                'method' => 'itAcceptsASurvivorThatGrewJustShortOfTheVacatedMagnitude',
            ],
            [
                'class' => \Qualimetrix\Tests\Analysis\Policy\Baseline\Unit\BaselineCeilingStageAcceptanceTest::class,
                'method' => 'itReportsASurvivorThatGrewPastTheWorstAcceptedMagnitude',
            ],
        ],
    ];

    #[Test]
    public function itKeepsEveryResidualLimitationPinnedByAConcreteTest(): void
    {
        self::assertSame(self::canonicalLimitIds(), array_keys(self::CASES));

        foreach (self::CASES as $number => $cases) {
            foreach ($cases as $case) {
                $method = new ReflectionMethod($case['class'], $case['method']);
                self::assertTrue($method->isPublic(), "Residual limitation {$number} names a non-public test method.");
                self::assertNotSame([], $method->getAttributes(Test::class), "Residual limitation {$number} names a method without #[Test].");
            }
        }
    }

    /**
     * @return list<int>
     */
    private static function canonicalLimitIds(): array
    {
        $adr = (string) file_get_contents(\dirname(__DIR__, 5) . '/docs/adr/0017-baseline-ceiling.md');
        preg_match('~^## Residual limitations$(.*)~ms', $adr, $section);
        self::assertArrayHasKey(1, $section, 'The canonical residual-limitations section is missing.');

        preg_match_all('~^(\\d+)\\. \\*\\*~m', $section[1], $items);

        return array_map(static fn(string $id): int => (int) $id, $items[1]);
    }
}
