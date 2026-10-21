<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Corpus;
use QmxFindingGate\EquivalenceTuple;
use QmxFindingGate\FailureClass;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\GateModes;
use QmxFindingGate\GateReport;
use QmxFindingGate\Options;
use QmxFindingGate\SyntheticTree;
use QmxFindingGate\Tsv;
use QmxFindingGate\TupleCheck;

/**
 * The tracked equivalence tuple against the publishing code, the fingerprint
 * licence and every published finding's key set, and the provenance a tuple
 * row has to resolve before it loads.
 *
 * @phpstan-import-type Specification from SyntheticTree
 */
final class TupleCheckTest extends TestCase
{
    private ?string $root = null;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            Fs::removeRecursively($this->root);
        }
    }

    #[Test]
    public function itAcceptsATrackedTupleThatIsWhatThePublisherPublishes(): void
    {
        $report = $this->checkTuple(SyntheticTree::clean());

        self::assertSame([], $report->raised(), $report->render());
    }

    #[Test]
    public function itRaisesTupleFieldDriftWhenThePublisherPublishesAFieldTheTupleLacks(): void
    {
        $tree = SyntheticTree::clean();
        $tree['tuple'] = array_values(array_diff($tree['tuple'], ['message']));

        $report = $this->checkTuple($tree);

        self::assertSame([[FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH]], self::classesAndScopes($report));
        self::assertStringContainsString('no longer match the tracked tuple', $report->raised()[0]['detail']);
    }

    #[Test]
    public function itRaisesTupleFieldDriftWhenTheFingerprintHashesAFieldTheTupleDoesNotCompare(): void
    {
        $tree = SyntheticTree::clean();
        $tree['tuple'] = array_values(array_diff($tree['tuple'], ['edge']));
        $tree['published'] = array_values(array_diff($tree['published'], ['edge']));

        $report = $this->checkTuple($tree);

        self::assertSame([[FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH]], self::classesAndScopes($report));
        self::assertStringContainsString('The fingerprint is composed from published field(s) the tuple does not compare: edge.', $report->raised()[0]['detail']);
    }

    #[Test]
    public function itRaisesFindingTupleMismatchForAPublishedFindingWithAKeyTheTupleDoesNotName(): void
    {
        $tree = SyntheticTree::clean();
        $this->root = SyntheticTree::fixture($tree);
        $case = Corpus::load($this->root)->cases[0];
        $tuple = EquivalenceTuple::load($this->root);
        $finding = $tree['findings']['alpha'][0];

        $exact = new GateReport();
        $this->tupleCheck($exact)->checkTupleAgainstFindings('candidate', $case, $tuple, [$finding]);
        self::assertSame([], $exact->raised(), $exact->render());

        $widened = new GateReport();
        $this->tupleCheck($widened)->checkTupleAgainstFindings('candidate', $case, $tuple, [$finding, $finding + ['unpublished' => 'a key the tuple does not name']]);
        self::assertSame([[FailureClass::FINDING_TUPLE_MISMATCH, 'candidate / alpha / finding #1']], self::classesAndScopes($widened));
    }

    #[Test]
    public function itRewritesTheTrackedTupleFromThePublisherAndExitsWithTheWriteCode(): void
    {
        $tree = SyntheticTree::clean();
        $tree['tuple'] = array_values(array_diff($tree['tuple'], ['message']));
        $this->root = SyntheticTree::fixture($tree);

        ob_start();
        try {
            $exit = GateModes::run(Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD', '--derive-tuple'], $this->root), new GateReport());
        } finally {
            ob_end_clean();
        }

        self::assertSame(GateModes::WROTE, $exit);
        self::assertSame($tree['published'], EquivalenceTuple::load($this->root)->fields);
        $report = new GateReport();
        $this->tupleCheck($report)->checkTuple();
        self::assertSame([], $report->raised(), $report->render());
    }

    #[Test]
    public function itLoadsATupleOnlyWhileEverySourceNamesAFileAndAMethodTheTreeStillHas(): void
    {
        $root = Fs::temporaryDirectory('tuple-provenance-');
        $this->root = $root;
        Fs::write($root . '/src/Publisher.php', '<?php class Publisher { private function formatFinding(): array {} }');

        $this->writeTupleSource('src/Publisher.php::formatFinding');
        self::assertSame(['channel'], EquivalenceTuple::load($root)->fields);

        foreach ([
            'a file the tree no longer has' => 'src/Gone.php::formatFinding',
            'a method the publisher no longer declares' => 'src/Publisher.php::formatViolation',
            'a cell that is not "<file>::<method>"' => 'src/Publisher.php',
        ] as $defect => $source) {
            $this->writeTupleSource($source);
            try {
                EquivalenceTuple::load($root);
                self::fail('A tuple whose source names ' . $defect . ' was loaded.');
            } catch (GateError) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param Specification $tree */
    private function checkTuple(array $tree): GateReport
    {
        $this->root = SyntheticTree::fixture($tree);
        $report = new GateReport();
        $this->tupleCheck($report)->checkTuple();

        return $report;
    }

    private function tupleCheck(GateReport $report): TupleCheck
    {
        return new TupleCheck(Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], (string) $this->root), $report);
    }

    private function writeTupleSource(string $source): void
    {
        Fs::write($this->root . '/' . EquivalenceTuple::TRACKED_PATH, Tsv::render(EquivalenceTuple::COLUMNS, [['channel', $source]]));
    }

    /** @return list<array{0: string, 1: string}> */
    private static function classesAndScopes(GateReport $report): array
    {
        return array_map(static fn(array $failure): array => [$failure['class'], $failure['scope']], $report->raised());
    }
}
