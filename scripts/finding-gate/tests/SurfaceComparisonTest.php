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
use QmxFindingGate\FingerprintCheck;
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
    public function itRefusesAStageBeforeAStepThatDoesNotExist(): void
    {
        $this->expectException(GateError::class);

        $this->comparison(new GateReport(), [self::settling('translate')]);
    }

    /** @param list<SurfaceStage> $stages */
    private function comparison(GateReport $report, array $stages): SurfaceComparison
    {
        $maps = RenameMaps::load($this->root . '/finding-gate/maps', MetricVocabulary::ofTree($this->root));
        $options = Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], $this->root);
        $fingerprints = new FingerprintCheck($report);

        return new SurfaceComparison(
            $report,
            Corpus::load($this->root),
            $maps,
            Normalization::fromRules([]),
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
