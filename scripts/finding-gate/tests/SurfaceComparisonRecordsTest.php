<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\FailureClass;
use QmxFindingGate\GateReport;
use QmxFindingGate\SubjectLevel;
use QmxFindingGate\SyntheticTree;
use QmxFindingGate\ValueCheck;

/**
 * The two record questions the surface comparison asks before it compares
 * bytes — how many findings each side reports, and whether each side publishes
 * its records in its own key's order — over recorded publications.
 *
 * @phpstan-import-type Specification from SyntheticTree
 */
final class SurfaceComparisonRecordsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itComparesACleanRecordedTreeWithoutAnyFailure(): void
    {
        $report = RecordedComparison::report(SyntheticTree::clean());

        self::assertSame([], $report->raised(), $report->render());
    }

    #[Test]
    public function itRaisesFindingCountMismatchWhenTheCandidateReportsAnotherNumberOfFindings(): void
    {
        $tree = SyntheticTree::clean();
        $tree['candidateFindings']['alpha'] = [
            ...$tree['findings']['alpha'],
            SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\\Alpha::walk@src/Alpha.php'),
        ];

        $report = RecordedComparison::report($tree);

        self::assertSame([[FailureClass::FINDING_COUNT_MISMATCH, 'case:alpha']], self::raised($report, FailureClass::FINDING_COUNT_MISMATCH));
        self::assertStringContainsString('The candidate reports 2 finding(s), the reference 1.', $report->render());
    }

    #[Test]
    public function itRaisesPublishedOrderDriftForABaselineNotInItsOwnKeyOrder(): void
    {
        $ordered = self::twoFindingTree(reversed: false);
        self::assertSame([], RecordedComparison::report($ordered)->raised());

        $report = RecordedComparison::report(self::twoFindingTree(reversed: true));

        self::assertNotSame([], self::raised($report, FailureClass::PUBLISHED_ORDER_DRIFT), $report->render());
        self::assertSame([], array_values(array_filter(
            self::raised($report, FailureClass::PUBLISHED_ORDER_DRIFT),
            static fn(array $failure): bool => $failure[1] !== 'case:alpha|baseline-file',
        )));
    }

    /**
     * Case `alpha` firing two channels on one subject, with its baseline
     * entries for that subject in the product's order or reversed.
     *
     * @return Specification
     */
    private static function twoFindingTree(bool $reversed): array
    {
        $tree = SyntheticTree::clean();
        $subject = $tree['findings']['alpha'][0]['subject'];
        $tree['cases']['alpha'][] = SubjectLevel::claim('replay.zulu', 'callable');
        $definition = json_decode($tree['declarations']['cases/alpha/case.json'], true, 512, \JSON_THROW_ON_ERROR);
        $definition['channels'] = $tree['cases']['alpha'];
        $tree['declarations']['cases/alpha/case.json'] = ValueCheck::value($definition);
        $tree['findings']['alpha'][] = SyntheticTree::finding($tree['tuple'], 'replay.zulu', $subject);
        $tree['static']['replay.zulu'] = ['callable'];
        $tree['fixture']['replay.zulu'] = ['callable'];
        if ($reversed) {
            $answers = SyntheticTree::caseAnswers('alpha', $tree['findings']['alpha'], false, []);
            $document = json_decode($answers['case:alpha|baseline-file']['stdout'] ?? '', true, 512, \JSON_THROW_ON_ERROR);
            $document['entries'][$subject] = array_reverse($document['entries'][$subject]);
            $payload = ValueCheck::value($document);
            $tree['answers']['case:alpha|baseline-file'] = ['stdout' => $payload, 'file' => $payload];
        }

        return $tree;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function raised(GateReport $report, string $class): array
    {
        return array_values(array_map(
            static fn(array $failure): array => [$failure['class'], $failure['scope']],
            array_filter($report->raised(), static fn(array $failure): bool => $failure['class'] === $class),
        ));
    }
}
