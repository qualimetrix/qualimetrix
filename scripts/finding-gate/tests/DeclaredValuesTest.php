<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\{ChannelSplit, Corpus, Declarations, DeclaredValues, FailureClass, GateReport, MetricVocabulary, Normalization, Options, RenameMaps, RunContext, SurfacePair, SyntheticTree, ValueCheck, ValueDerivation, ValueStage};
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\Tsv;

/**
 * The declaration forms as tables: what a row must look like, what loading
 * refuses, and which rows are stale when nothing consumed them.
 */
final class DeclaredValuesTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $trees = [];

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->root = Fs::temporaryDirectory('declarations-test-');
    }

    protected function tearDown(): void
    {
        Fs::removeRecursively($this->root);
        foreach ($this->trees as $tree) {
            SyntheticTree::remove($tree);
        }
    }

    #[Test]
    public function itRefusesADerivedValueNoIntentDeclares(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [[DeclaredValues::METRIC, 'ccn', 'class', 'recalibrated']]);
        $this->write(DeclaredValues::DERIVED, DeclaredValues::DERIVED_COLUMNS, [[DeclaredValues::METRIC, 'npath', 'App\\A', '1', '2']]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredValues::load($root), 'which no intent in declared-values.tsv declares');
    }

    #[Test]
    public function itKeepsTheDerivedValueTableAsBytesAndAnIntentUncreditedIsStale(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [
            [DeclaredValues::METRIC, 'ccn', DeclaredValues::EVERY_LEVEL, 'recalibrated'],
            [DeclaredValues::FIELD, 'message', 'class', 'reworded'],
        ]);
        $derived = $this->write(DeclaredValues::DERIVED, DeclaredValues::DERIVED_COLUMNS, [[DeclaredValues::METRIC, 'ccn', 'App\\A', '1', '2']]);
        $values = DeclaredValues::load($this->root);
        $values->credit(DeclaredValues::METRIC, 'ccn');

        self::assertSame($derived, $values->derivedText());
        self::assertSame([['scope' => DeclaredValues::INDEX, 'detail' => 'The field "message" at level class']], $values->stale());
    }

    #[Test]
    public function itRefusesAnIntentAtALevelTheProductDoesNotHave(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [[DeclaredValues::METRIC, 'ccn', 'galaxy', 'why']]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredValues::load($root), '"level"');
    }

    #[Test]
    public function itRefusesExitIntentionsOutsideExactCommandClassesAndTheWildcardLevel(): void
    {
        foreach ([['exit', 'check:prefix', '*', 'why'], ['exit', 'check', 'class', 'why']] as $row) {
            $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [$row]);
            $this->assertRefused(static fn(string $root): mixed => DeclaredValues::load($root), 'exact command class');
        }
    }

    #[Test]
    public function itSharesMeasuredValuesAcrossSeparatelyCreatedComparisonAndDerivationRoles(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [['exit', 'check', '*', 'The synthetic exit changes.']]);
        $run = $this->context();
        $writer = ValueDerivation::create($run);
        $writer->startDeriving();
        $pair = new SurfacePair('case:alpha|exit:format:json', 'exit:format:json', '5', '2');
        ValueStage::create($run)->applyStage($pair);
        ValueCheck::create($run)->checkRun([], []);
        self::assertSame('2', $pair->candidate);
        self::assertSame([], $run->report->raised());
        self::assertSame([DeclaredValues::DERIVED], $writer->rewriteDerived());
        self::assertSame([['kind' => 'exit', 'key' => 'check', 'subject' => 'case:alpha|format:json', 'from' => '2', 'to' => '5']], Tsv::rows($run->options->candidateRoot . '/finding-gate/' . DeclaredValues::DERIVED, DeclaredValues::DERIVED_COLUMNS));
    }

    #[Test]
    public function itRefusesAChangedNeighbourExitEvenInsideTheSameCommandIntention(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [['exit', 'check', '*', 'Only the exact measured invocation changes.']]);
        $this->write(DeclaredValues::DERIVED, DeclaredValues::DERIVED_COLUMNS, [['exit', 'check', 'case:alpha|format:json', '2', '5']]);
        $run = $this->context();
        ValueStage::create($run)->applyStage(new SurfacePair('case:alpha|exit:format:text', 'exit:format:text', '5', '2'));
        ValueCheck::create($run)->checkRun([], []);
        self::assertContains(FailureClass::VALUE_MISMATCH, $run->report->failureClasses());
    }

    #[Test]
    public function itSharesOnlyAnExactMeasuredAndDeclaredExitWithPopulationChecks(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [['exit', 'graph:export', '*', 'One measured invocation changes.']]);
        $this->write(DeclaredValues::DERIVED, DeclaredValues::DERIVED_COLUMNS, [['exit', 'graph:export', 'tree|graph:export', '1', '0']]);
        $values = ValueCheck::create($this->context());
        self::assertNull($values->referenceExitFor('graph:export', 'tree|graph:export', '0'));
        self::assertTrue($values->measure('exit', 'graph:export', 'tree|graph:export', '*', 1, 0));
        self::assertSame('1', $values->referenceExitFor('graph:export', 'tree|graph:export', '0'));
        self::assertNull($values->referenceExitFor('graph:export', 'case:alpha|graph:export', '0'));
        self::assertNull($values->referenceExitFor('check', 'tree|graph:export', '0'));
        self::assertNull($values->referenceExitFor('graph:export', 'tree|graph:export', '5'));
    }

    #[Test]
    public function itKeepsPopulationUnlicensedWhenTheDerivedExitMultisetDisagrees(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [['exit', 'graph:export', '*', 'One measured invocation changes.']]);
        $this->write(DeclaredValues::DERIVED, DeclaredValues::DERIVED_COLUMNS, [['exit', 'graph:export', 'tree|graph:export', '1', '5']]);
        $values = ValueCheck::create($this->context());
        self::assertTrue($values->measure('exit', 'graph:export', 'tree|graph:export', '*', 1, 0));
        self::assertNull($values->referenceExitFor('graph:export', 'tree|graph:export', '0'));
        $values->startDeriving();
        self::assertSame('1', $values->referenceExitFor('graph:export', 'tree|graph:export', '0'));
        self::assertNull($values->referenceExitFor('graph:export', 'case:alpha|graph:export', '0'));
    }

    #[Test]
    public function itRefusesNonintegerMeasuredReferenceExitsAsPopulationEvidence(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [['exit', 'graph:export', '*', 'One measured invocation changes.']]);
        foreach (['1', 1.0, true, null, []] as $reference) {
            $values = ValueCheck::create($this->context());
            $values->startDeriving();
            self::assertTrue($values->measure('exit', 'graph:export', 'tree|graph:export', '*', $reference, 0));
            self::assertNull($values->referenceExitFor('graph:export', 'tree|graph:export', '0'));
        }
    }

    #[Test]
    public function itDoesNotLicenseAFieldMoveAtAnotherSubjectLevel(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [['field', 'message', 'class', 'Only classes move.']]);
        $run = $this->context();
        self::assertFalse(ValueCheck::create($run)->measure('field', 'message', 'callable:A::run', 'callable', 'old', 'new'));
        self::assertContains(FailureClass::VALUE_MISMATCH, $run->report->failureClasses());
        self::assertCount(1, $run->declarations->values->stale());
    }

    #[Test]
    public function itRefusesAnIntentionWithNoMeasuredTransitionDuringDerivation(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [['metric', 'ccn', '*', 'The value must move.']]);
        $run = $this->context();
        $writer = ValueDerivation::create($run);
        $writer->startDeriving();
        self::assertFalse(ValueCheck::create($run)->measure('metric', 'ccn', 'class:A', 'class', 2, 2));
        ValueCheck::create($run)->checkRun([], []);
        self::assertContains(FailureClass::VALUE_STALE, $run->report->failureClasses());
        self::assertSame([], $writer->rewriteDerived());
    }

    #[Test]
    public function itRefusesConflictingTransitionsForOneExactMeasuredSubject(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [['metric', 'ccn', '*', 'A single reproducible transition.']]);
        $run = $this->context();
        $values = ValueCheck::create($run);
        self::assertTrue($values->measure('metric', 'ccn', 'class:A', 'class', 1, 2));
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('One exact value subject measured two different transitions.');
        $values->measure('metric', 'ccn', 'class:A', 'class', 1, 3);
    }

    private function context(): RunContext
    {
        $tree = SyntheticTree::create(SyntheticTree::clean());
        $this->trees[] = $tree;
        foreach ([DeclaredValues::INDEX, DeclaredValues::DERIVED] as $file) {
            if (is_file($this->root . '/' . $file)) {
                Fs::write($tree . '/finding-gate/' . $file, Fs::read($this->root . '/' . $file));
            }
        }
        $maps = RenameMaps::fromPairs([]);
        return new RunContext(Options::parse(['gate', '--candidate=' . $tree, '--reference=HEAD'], $tree), new GateReport(), Corpus::load($tree), $maps, ChannelSplit::of($maps), MetricVocabulary::ofTree($tree), Normalization::fromRules([]), Declarations::load($tree), $tree);
    }

    /**
     * @param list<string> $columns
     * @param list<list<string>> $rows
     */
    private function write(string $file, array $columns, array $rows): string
    {
        $text = Tsv::render($columns, $rows);
        Fs::write($this->root . '/' . $file, $text);

        return $text;
    }

    /** @param callable(string): mixed $load */
    private function assertRefused(callable $load, string $reason): void
    {
        try {
            $load($this->root);
        } catch (GateError $error) {
            self::assertStringContainsString($reason, $error->getMessage());

            return;
        }

        self::fail('The declaration was accepted.');
    }
}
