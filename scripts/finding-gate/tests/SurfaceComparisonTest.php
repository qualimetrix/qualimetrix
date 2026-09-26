<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\ChannelSplit;
use QmxFindingGate\Corpus;
use QmxFindingGate\DeclaredDelta;
use QmxFindingGate\DeclaredDeltaCheck;
use QmxFindingGate\DeclaredFieldMoves;
use QmxFindingGate\ExactDiff;
use QmxFindingGate\FailureClass;
use QmxFindingGate\FingerprintCheck;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\GateReport;
use QmxFindingGate\MetricVocabulary;
use QmxFindingGate\Normalization;
use QmxFindingGate\Options;
use QmxFindingGate\RenameMaps;
use QmxFindingGate\RunContext;
use QmxFindingGate\SurfaceComparison;
use QmxFindingGate\SurfacePair;
use QmxFindingGate\SurfaceStage;
use QmxFindingGate\SyntheticTree;
use QmxFindingGate\Tsv;

/**
 * A form's registered stage takes part in every surface's comparison, at the
 * step it names.
 */
final class SurfaceComparisonTest extends TestCase
{
    private string $root;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->root = SyntheticTree::create(SyntheticTree::clean());
    }

    protected function tearDown(): void
    {
        SyntheticTree::remove($this->root);
    }

    #[Test]
    public function itRunsARegisteredStageBeforeTheStepItNames(): void
    {
        $report = new GateReport();
        $stage = self::settling('difference');
        $json = (string) json_encode(['violations' => []]);

        $this->comparison($report, [$stage])->compareSurfaces(
            ['case:alpha|format:json' => $json, 'case:alpha|format:text' => 'candidate text'],
            ['case:alpha|format:json' => $json, 'case:alpha|format:text' => 'reference text'],
        );

        self::assertSame(['case:alpha|format:json', 'case:alpha|format:text'], $stage->seen);
        self::assertSame([], $report->raised(), 'a surface the stage settled reaches no later step');
    }

    #[Test]
    public function itComparesFindingCountsOnlyWhenBothDeclaredSidesPublishFindings(): void
    {
        SyntheticTree::remove($this->root);
        $this->root = SyntheticTree::create(\QmxFindingGate\SelfTestOutcomes::fixture());
        foreach ([true, false] as $declared) {
            if (!$declared) {
                Fs::write($this->root . '/finding-gate/' . \QmxFindingGate\DeclaredOutcomes::INDEX, Tsv::render(\QmxFindingGate\DeclaredOutcomes::COLUMNS, []));
            }
            $report = new GateReport();
            $this->comparison($report, [self::settling('difference')])->compareSurfaces(['case:alpha|format:json' => ''], ['case:alpha|format:json' => '{"violations":[{}]}']);
            if ($declared) {
                self::assertNotContains(FailureClass::FINDING_COUNT_MISMATCH, $report->failureClasses());
            } else {
                self::assertContains(FailureClass::FINDING_COUNT_MISMATCH, $report->failureClasses());
            }
        }
    }

    #[Test]
    public function itRefusesAStageBeforeAStepThatDoesNotExist(): void
    {
        $this->expectException(GateError::class);

        $this->comparison(new GateReport(), [self::settling('translate')]);
    }

    #[Test]
    public function itComparesOnePositionIndependentDeltaForEveryCaseOfASurfaceClass(): void
    {
        $canonical = "--- candidate\n+++ reference (mapped)\n-a\n+A\n";
        $this->declare('format:text', $canonical);
        $report = new GateReport();
        $check = $this->deltaCheck($report);
        $check->checkDifference('case:alpha|format:text', "a\n", "A\n");
        $check->checkDifference('case:beta|format:text', "padding\na\n", "padding\nA\n");
        self::assertSame([], $report->raised());
        $check->checkStaleDeclaredDelta();
        self::assertSame([], $report->raised());
    }

    #[Test]
    public function itRefusesAnEqualCaseUnderADeclaredSurfaceClass(): void
    {
        $this->declare('format:text', "--- candidate\n+++ reference (mapped)\n-a\n+A\n");
        $report = new GateReport();
        $this->deltaCheck($report)->observeEqual('case:alpha|format:text');
        self::assertContains(FailureClass::DELTA_STALE, $report->failureClasses());
    }

    #[Test]
    public function itRefusesDifferentCaseMeasurementsOfOneSurfaceClassWhileDeriving(): void
    {
        $this->declare('format:text', "--- candidate\n+++ reference (mapped)\n-a\n+A\n");
        $report = new GateReport();
        $check = $this->deltaCheck($report);
        $check->startDeriving();
        $check->checkDifference('case:alpha|format:text', "a\n", "A\n");
        $check->checkDifference('case:beta|format:text', "b\n", "B\n");
        self::assertContains(FailureClass::DELTA_MISMATCH, $report->failureClasses());
        self::assertSame([], $check->rewriteDerived());
    }

    #[Test]
    public function itRefusesAnUnannouncedNeighbourBeforeADerivationWritesAnyFile(): void
    {
        $this->declare('case:alpha|format:text', "--- candidate\n+++ reference (mapped)\n-a\n+A\n");
        $path = $this->root . '/finding-gate/' . DeclaredDelta::INDEX;
        $before = Fs::read($path);
        $report = new GateReport();
        $check = $this->deltaCheck($report);
        $check->startDeriving();
        $this->comparison($report, [])->compareSurfaces(['case:beta|format:text' => "a\n"], ['case:beta|format:text' => "A\n"]);
        self::assertContains(FailureClass::SURFACE_MISMATCH, $report->failureClasses());
        self::assertSame([], $check->rewriteDerived());
        self::assertSame($before, Fs::read($path));
    }

    #[Test]
    public function itChecksOnlyTheChosenOutputMarkerAfterItsNamedNormalization(): void
    {
        $rule = new \QmxFindingGate\NormalizationRule('stderr:check:output', '~^(Report written to ).*()$~m', \QmxFindingGate\NormalizationRule::KIND_LINE_REGEX, 'The validated output destination varies per run.');
        $report = new GateReport();
        $comparison = $this->comparison($report, [], Normalization::fromRules([$rule]));
        $comparison->checkPathLeaks(['case:alpha|stderr:check:output' => 'Report written to ' . $this->root . "/chosen.json\n"], [], '/other-reference');
        self::assertSame([], $report->raised());
        $comparison->checkPathLeaks([
            'case:alpha|stderr:check:output' => 'Report written to ' . $this->root . "/chosen.json\nordinary " . $this->root,
            'case:alpha|stderr:rules' => 'Report written to ' . $this->root . '/chosen.json',
            'case:alpha|check:output' => 'Report written to ' . $this->root . '/chosen.json',
        ], [], '/other-reference');
        self::assertCount(3, $report->raised());
        $without = new GateReport();
        $this->comparison($without, [])->checkPathLeaks(['case:alpha|stderr:check:output' => 'Report written to ' . $this->root . '/chosen.json'], [], '/other-reference');
        self::assertContains(FailureClass::PATH_LEAK, $without->failureClasses());
    }

    #[Test]
    public function itKeepsStructuralMetadataOutsideComparedRecordsAndRefusesRecordOverreach(): void
    {
        $a = '{"meta":{"message":"New schema label"},"violations":[]}';
        $b = '{"meta":{"message":"Old schema label"},"violations":[]}';
        $this->declare('case:alpha|format:json', ExactDiff::between($a, $b, 'candidate', 'reference (mapped)')->render());
        $report = new GateReport();
        $this->deltaCheck($report)->checkDifference('case:alpha|format:json', $a, $b);
        self::assertSame([], $report->raised());
        $a = '{"meta":{},"violations":[{"message":"New message"}]}';
        $b = '{"meta":{},"violations":[{"message":"Old message"}]}';
        $this->declare('case:alpha|format:json', ExactDiff::between($a, $b, 'candidate', 'reference (mapped)')->render());
        $report = new GateReport();
        $this->deltaCheck($report)->checkDifference('case:alpha|format:json', $a, $b);
        self::assertContains(FailureClass::DELTA_OVERREACH, $report->failureClasses());
    }

    #[Test]
    public function itKeepsSarifPathProtectionOutsideItsDeclaredCaptureSourceUri(): void
    {
        $rule = new \QmxFindingGate\NormalizationRule('format:sarif', 'runs.*.originalUriBaseIds.%SRCROOT%.uri', \QmxFindingGate\NormalizationRule::KIND_JSON_PATH, 'The isolated capture directory varies.');
        $document = ['runs' => [['originalUriBaseIds' => ['%SRCROOT%' => ['uri' => 'file://' . $this->root . '/']], 'results' => [['message' => ['text' => 'A populated finding'], 'locations' => [['physicalLocation' => ['artifactLocation' => ['uri' => 'src/A.php']]]]]]]]];
        $artifact = json_encode($document, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $report = new GateReport();
        $comparison = $this->comparison($report, [], Normalization::fromRules([$rule]));
        $comparison->checkPathLeaks(['case:alpha|format:sarif' => $artifact], [], '/other-reference');
        self::assertSame([], $report->raised());
        $document['runs'][0]['results'][0]['message']['text'] = 'An unexpected directory ' . $this->root;
        $comparison->checkPathLeaks(['case:alpha|format:sarif' => json_encode($document, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)], [], '/other-reference');
        self::assertSame([FailureClass::PATH_LEAK], $report->failureClasses());
        self::assertCount(1, $report->raised());
        $without = new GateReport();
        $this->comparison($without, [])->checkPathLeaks(['case:alpha|format:sarif' => $artifact], [], '/other-reference');
        self::assertSame([FailureClass::PATH_LEAK], $without->failureClasses());
    }

    #[Test]
    public function itRefusesOverlappingFullSurfaceAndClassStructuralIntentions(): void
    {
        Fs::write($this->root . '/finding-gate/declared-delta/probe.diff', "a measured structural change\n");
        Fs::write($this->root . '/finding-gate/' . DeclaredDelta::INDEX, Tsv::render(DeclaredDelta::COLUMNS, [
            ['format:json', 'declared-delta/probe.diff', 'Every case changes.'],
            ['case:alpha|format:json', 'declared-delta/probe.diff', 'The same case changes again.'],
        ]));
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('overlaps');
        DeclaredDelta::load($this->root . '/finding-gate');
    }

    #[Test]
    public function itRefusesAFirstOnlyOrSecondOnlySurfaceInEitherDirection(): void
    {
        $key = 'case:alpha|format:health';
        foreach ([[$key => 'first'], []] as $candidate) {
            $reference = $candidate === [] ? [$key => 'second'] : [];
            $report = new GateReport();
            $this->comparison($report, [])->compareSurfaces($candidate, $reference);
            self::assertSame(1, $report->exitCode());
            self::assertCount(1, $report->raised());
            self::assertSame(FailureClass::SURFACE_MISMATCH, $report->raised()[0]['class']);
            self::assertSame($key, $report->raised()[0]['scope']);
            self::assertStringContainsString($candidate === [] ? 'the reference' : 'the candidate', $report->raised()[0]['detail']);
        }
    }

    #[Test]
    public function itRefusesDifferentUnlicensedBytesInBothDirectionsAndAcceptsEquality(): void
    {
        $key = 'case:alpha|format:health';
        foreach ([['first', 'second'], ['second', 'first'], ['same', 'same']] as [$candidate, $reference]) {
            $report = new GateReport();
            $this->comparison($report, [])->compareSurfaces([$key => $candidate], [$key => $reference]);
            if ($candidate === $reference) {
                self::assertSame([], $report->raised());
                self::assertSame(0, $report->exitCode());
            } else {
                self::assertSame(1, $report->exitCode());
                self::assertCount(1, $report->raised());
                self::assertSame(FailureClass::SURFACE_MISMATCH, $report->raised()[0]['class']);
                self::assertSame($key, $report->raised()[0]['scope']);
                self::assertStringContainsString('outside every declared structural intention', $report->raised()[0]['detail']);
            }
        }
    }

    #[Test]
    public function itRefusesDifferentBytesEvenForAnUnknownSurfaceKey(): void
    {
        $report = new GateReport();
        $this->comparison($report, [])->compareSurfaces(['case:alpha|unknown' => 'first'], ['case:alpha|unknown' => 'second']);
        self::assertSame(1, $report->exitCode());
        self::assertCount(1, $report->raised());
        self::assertSame(FailureClass::SURFACE_MISMATCH, $report->raised()[0]['class']);
        self::assertSame('case:alpha|unknown', $report->raised()[0]['scope']);
    }

    #[Test]
    public function itLooksUpAnIntentionWithoutCreditingItAsPerformed(): void
    {
        $this->declare('format:health', "--- candidate\n+++ reference (mapped)\n-a\n+A\n");
        $report = new GateReport();
        $check = $this->deltaCheck($report);
        self::assertTrue($check->hasIntention('case:alpha|format:health'));
        self::assertFalse($check->hasIntention('case:alpha|unknown'));
        $check->checkStaleDeclaredDelta();
        self::assertCount(1, $report->raised());
        self::assertSame(FailureClass::DELTA_STALE, $report->raised()[0]['class']);
        self::assertSame('format:health', $report->raised()[0]['scope']);
    }

    #[Test]
    public function itRefusesADirectDeltaComparisonWithoutAnExplicitIntention(): void
    {
        $report = new GateReport();
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('A structural delta comparison requires an explicit intention: case:alpha|unknown');
        $this->deltaCheck($report)->checkDifference('case:alpha|unknown', 'first', 'second');
    }

    private function declare(string $key, string $diff): void
    {
        Fs::write($this->root . '/finding-gate/declared-delta/probe.diff', $diff);
        Fs::write($this->root . '/finding-gate/' . DeclaredDelta::INDEX, Tsv::render(DeclaredDelta::COLUMNS, [[$key, 'declared-delta/probe.diff', 'An explicit structural intention.']]));
    }

    private function deltaCheck(GateReport $report): DeclaredDeltaCheck
    {
        return new DeclaredDeltaCheck(Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], $this->root), $report, DeclaredDelta::load($this->root . '/finding-gate'), DeclaredFieldMoves::load($this->root . '/finding-gate'), ChannelSplit::of(RenameMaps::fromPairs([])));
    }

    /** @param list<SurfaceStage> $stages */
    private function comparison(GateReport $report, array $stages, ?Normalization $normalization = null): SurfaceComparison
    {
        $maps = RenameMaps::load($this->root . '/finding-gate/maps', MetricVocabulary::ofTree($this->root));
        $options = Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], $this->root);
        $fingerprints = new FingerprintCheck($report);

        return new SurfaceComparison(
            $report,
            Corpus::load($this->root),
            $maps,
            $normalization ?? Normalization::fromRules([]),
            $fingerprints,
            new DeclaredDeltaCheck(
                $options,
                $report,
                DeclaredDelta::load($this->root . '/finding-gate'),
                DeclaredFieldMoves::load($this->root . '/finding-gate'),
                ChannelSplit::of($maps),
            ),
            $this->root,
            $stages,
        );
    }

    /**
     * A stage that settles every surface it sees, once both sides have been
     * through every step before the one it names.
     *
     * @return SurfaceStage&object{seen: list<string>}
     */
    private static function settling(string $before): SurfaceStage
    {
        return new class ($before) implements SurfaceStage {
            /** @var list<string> */
            public array $seen = [];

            public function __construct(private readonly string $step) {}

            public static function create(RunContext $run): static
            {
                throw new GateError('Built by the test, not by the gate.');
            }

            public function before(): string
            {
                return $this->step;
            }

            public function applyStage(SurfacePair $pair): void
            {
                $this->seen[] = $pair->key;
                $pair->settle();
            }
        };
    }
}
