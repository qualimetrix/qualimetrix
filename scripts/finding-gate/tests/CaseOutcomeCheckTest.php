<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CaseOutcomeCheck;
use QmxFindingGate\Corpus;
use QmxFindingGate\FailureClass;
use QmxFindingGate\GateReport;
use QmxFindingGate\SyntheticTree;

/**
 * A case's outcome decides which of the run checks apply to it, as `CaseOutcome::CHECKS` states.
 */
final class CaseOutcomeCheckTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itHoldsAnAnalysisToItsBaselineFile(): void
    {
        self::assertSame([FailureClass::RUN_FAILED, FailureClass::RUN_FAILED], $this->failuresWithoutABaselineFile(null));
    }

    #[Test]
    public function itDoesNotHoldAnIncompleteAnalysisToABaselineFileItCannotWrite(): void
    {
        self::assertSame([], $this->failuresWithoutABaselineFile(['kind' => 'incomplete', 'exit' => 4]));
    }

    /**
     * @param array{kind: string, exit: int}|null $outcome
     *
     * @return list<string>
     */
    private function failuresWithoutABaselineFile(?array $outcome): array
    {
        $tree = SyntheticTree::clean();

        if ($outcome !== null) {
            $tree['declarations']['cases/alpha/case.json'] = json_encode([
                'id' => 'alpha',
                'description' => 'A case whose analysis ends incomplete.',
                'paths' => ['src'],
                'config' => 'qmx.yaml',
                'channels' => ['replay.alpha@callable'],
                'outcome' => $outcome,
            ], \JSON_THROW_ON_ERROR);
        }

        $root = SyntheticTree::create($tree);

        try {
            $corpus = Corpus::load($root);
            $report = new GateReport();
            $findings = (new CaseOutcomeCheck($report, $corpus))->findingsOf('candidate', $corpus->cases[0], [
                'case:alpha|format:json' => '{"violations": []}',
                'case:alpha|exit:baseline:generate' => '4',
            ]);

            self::assertSame([], $findings);

            return array_column($report->raised(), 'class');
        } finally {
            SyntheticTree::remove($root);
        }
    }
}
