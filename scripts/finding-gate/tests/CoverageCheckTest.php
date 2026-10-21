<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\ChannelCoverage;
use QmxFindingGate\ChannelWitness;
use QmxFindingGate\Corpus;
use QmxFindingGate\CoverageCheck;
use QmxFindingGate\FailureClass;
use QmxFindingGate\GateReport;
use QmxFindingGate\Options;
use QmxFindingGate\SubjectLevel;
use QmxFindingGate\SyntheticTree;

/**
 * What the corpus covers, counted per channel-and-level pair, and the case
 * claims and declaration witnesses that coverage is built from.
 *
 * @phpstan-import-type Specification from SyntheticTree
 * @phpstan-import-type Finding from SyntheticTree
 */
final class CoverageCheckTest extends TestCase
{
    private ?string $root = null;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            SyntheticTree::remove($this->root);
        }
    }

    /**
     * Counted by channel name the same universe is green, which is what every
     * run reported before coverage counted pairs.
     */
    #[Test]
    public function itRaisesCoverageShortfallForADeclaredPairThatFiresInNoCase(): void
    {
        $declared = ['a.rule#a.code@class', 'a.rule#a.code@callable'];
        $observed = ['a.rule#a.code@class'];

        $shortfall = new GateReport();
        ChannelCoverage::inspectCoverage($shortfall, $declared, $observed, incompleteCorpus: false);
        self::assertSame([[FailureClass::COVERAGE_SHORTFALL, 'corpus']], self::classesAndScopes($shortfall));

        $names = static fn(array $pairs): array => array_values(array_unique(array_map(SubjectLevel::channelOf(...), $pairs)));
        $byName = new GateReport();
        ChannelCoverage::inspectCoverage($byName, $names($declared), $names($observed), incompleteCorpus: false);
        self::assertSame(GateReport::VERDICT_GREEN, $byName->verdict());

        $covered = new GateReport();
        ChannelCoverage::inspectCoverage($covered, $declared, $declared, incompleteCorpus: false);
        self::assertSame([], $covered->raised());
    }

    #[Test]
    public function itDowngradesAShortfallToPartialUnderIncompleteCorpus(): void
    {
        $report = new GateReport();
        ChannelCoverage::inspectCoverage($report, ['a.rule#a.code@class', 'a.rule#a.code@callable'], ['a.rule#a.code@class'], incompleteCorpus: true);

        self::assertSame([], $report->raised());
        self::assertSame(GateReport::VERDICT_PARTIAL, $report->verdict());
    }

    #[Test]
    public function itRaisesCoverageSurplusForAnObservedLevelNothingDeclares(): void
    {
        $surplus = new GateReport();
        ChannelCoverage::inspectCoverage($surplus, ['a.rule#a.code@class'], ['a.rule#a.code@class', 'a.rule#a.code@callable'], incompleteCorpus: false);
        self::assertSame([[FailureClass::COVERAGE_SURPLUS, 'corpus']], self::classesAndScopes($surplus));
        self::assertStringContainsString('a.rule#a.code@callable', $surplus->render());
    }

    /**
     * A fixture whose levels column drifts from the declaration in code must
     * not read as a corpus that lost a fixture.
     */
    #[Test]
    public function itRaisesWitnessDisagreementWhenTheFixtureAndTheContainerDifferOnALevel(): void
    {
        $disagreement = new GateReport();
        ChannelWitness::checkAgreement($disagreement, ['a.rule#a.code@class'], ['a.rule#a.code@callable']);
        self::assertSame([[FailureClass::WITNESS_DISAGREEMENT, 'governance/Channel/Fixtures/declared.txt']], self::classesAndScopes($disagreement));

        $agreed = new GateReport();
        ChannelWitness::checkAgreement($agreed, ['a.rule#a.code@class', 'b@file'], ['b@file', 'a.rule#a.code@class']);
        self::assertSame([], $agreed->raised());
    }

    #[Test]
    public function itRaisesLevelVocabularyDriftForALevelTheProductSpellsDifferently(): void
    {
        $drifted = new GateReport();
        ChannelWitness::checkLevelVocabulary($drifted, ['callable', 'klass', 'file', 'namespace', 'project']);
        self::assertSame([[FailureClass::LEVEL_VOCABULARY_DRIFT, 'scripts/finding-gate/SubjectLevel.php']], self::classesAndScopes($drifted));

        $current = new GateReport();
        ChannelWitness::checkLevelVocabulary($current, array_reverse(SubjectLevel::levels()));
        self::assertSame([], $current->raised());
    }

    /** A lost level-fixture is visible only here: the channel still fires, at its other level. */
    #[Test]
    public function itRaisesCaseClaimMismatchForACaseFiringAPairItsClaimDoesNotName(): void
    {
        $tree = SyntheticTree::clean();
        $exact = $tree['findings']['alpha'];

        self::assertSame([], self::classesAndScopes($this->coverage($tree, ['alpha' => $exact])));

        $wider = [...$exact, SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:class:Replay\\Alpha@src/Alpha.php')];
        $report = $this->coverage($tree, ['alpha' => $wider]);
        self::assertContains([FailureClass::CASE_CLAIM_MISMATCH, 'case:alpha'], self::classesAndScopes($report));
    }

    #[Test]
    public function itRaisesCoverageMultiplicityForAChannelTwoAuthoritativeCasesFire(): void
    {
        $tree = SyntheticTree::clean();
        $tree['cases']['beta'] = ['replay.beta@callable'];
        $tree['static']['replay.beta'] = ['callable'];
        $tree['fixture']['replay.beta'] = ['callable'];
        $beta = [SyntheticTree::finding($tree['tuple'], 'replay.beta', 'declaration:callable:Replay\\Beta::run@src/Beta.php')];

        self::assertSame([], self::classesAndScopes($this->coverage($tree, ['alpha' => $tree['findings']['alpha'], 'beta' => $beta])));

        $tree['cases']['beta'] = ['replay.alpha@callable'];
        unset($tree['static']['replay.beta'], $tree['fixture']['replay.beta']);
        $shared = [SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\\Beta::run@src/Beta.php')];
        $report = $this->coverage($tree, ['alpha' => $tree['findings']['alpha'], 'beta' => $shared]);
        self::assertSame([[FailureClass::COVERAGE_MULTIPLICITY, 'corpus']], self::classesAndScopes($report));
        self::assertStringContainsString('replay.alpha: alpha, beta', $report->render());
    }

    #[Test]
    public function itRaisesCandidateInputRefusedForACoverageBearingCaseWithNoObservedFindings(): void
    {
        $report = $this->coverage(SyntheticTree::clean(), []);

        self::assertContains([FailureClass::CANDIDATE_INPUT_REFUSED, 'case:alpha'], self::classesAndScopes($report));
    }

    /**
     * @param Specification $tree
     * @param array<string, list<Finding>> $findingsByCase
     */
    private function coverage(array $tree, array $findingsByCase): GateReport
    {
        if ($this->root !== null) {
            SyntheticTree::remove($this->root);
        }
        $this->root = SyntheticTree::fixture($tree);
        $report = new GateReport();
        $check = new CoverageCheck(
            Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], $this->root),
            $report,
            Corpus::load($this->root),
            new ChannelWitness($this->root),
        );
        $check->checkCoverage($findingsByCase);
        $check->checkChannelWitnesses();

        return $report;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function classesAndScopes(GateReport $report): array
    {
        return array_map(static fn(array $failure): array => [$failure['class'], $failure['scope']], $report->raised());
    }
}
