<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCleaner;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEdge;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FixedClock;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\StubBaselineRun;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `baseline:cleanup` reports on its own and removes only what it is told to.
 *
 * The two properties worth defending are opposites: nothing is removed by
 * inference (absence of a finding is not proof the debt is gone), and what is
 * named *is* removed exactly — including one of two entries that differ in
 * nothing but their dependency edge, which is the case a selector shorter
 * than the full identity could not address at all.
 */
#[CoversClass(BaselineCleanupCommand::class)]
final class BaselineCleanupCommandTest extends TestCase
{
    private const string EDGE_CHANNEL = 'architecture.layer-violation';
    private const string OCCURRENCE_CHANNEL = 'code-smell.goto';

    private string $tempDir;
    private string $baselinePath;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-baseline-cleanup-');
        $this->baselinePath = $this->tempDir . '/baseline.json';
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->tempDir);
    }

    /**
     * With no `--remove` the command is a report. Neither the bytes nor the
     * timestamp move: a scheduled `cleanup` that rewrote the file every night
     * would be indistinguishable from one that had decided something.
     */
    #[Test]
    public function itReportsAndWritesNothingWithoutRemove(): void
    {
        $this->writeBaseline([self::occurrenceEntry()], ['src']);

        $bytesBefore = (string) file_get_contents($this->baselinePath);
        touch($this->baselinePath, 1_700_000_000);
        clearstatcache();
        $mtimeBefore = filemtime($this->baselinePath);

        $tester = $this->execute([]);

        clearstatcache();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('could be removed', $tester->getDisplay());
        self::assertStringContainsString('nothing reported for this identity', $tester->getDisplay());
        self::assertSame($bytesBefore, file_get_contents($this->baselinePath));
        self::assertSame($mtimeBefore, filemtime($this->baselinePath));
    }

    /**
     * Two entries on one symbol and one channel, differing only in the edge
     * they forbid, plus an unrelated neighbour. Removing one leaves the other
     * two — which is only possible because the selector digests the edge too.
     */
    #[Test]
    public function itRemovesOnlyTheNamedEntryEvenWhenTwoDifferOnlyByEdge(): void
    {
        $doomed = self::edgeIdentity('class:App\\Db\\Connection');
        $sibling = self::edgeIdentity('class:App\\Db\\Statement');

        $this->writeBaseline(
            [
                new BaselineEntry($doomed, null, 1),
                new BaselineEntry($sibling, null, 1),
                self::occurrenceEntry(),
            ],
            ['src'],
        );

        $tester = $this->execute(['--remove' => [$doomed->selector()->value]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $targets = self::edgeTargetsInFile($this->baselinePath);
        self::assertSame(['class:App\\Db\\Statement'], $targets);
        self::assertCount(2, self::allEntries($this->baselinePath));
    }

    #[Test]
    public function itReportsASelectorThatMatchesNothingAndWritesNothing(): void
    {
        $this->writeBaseline([self::occurrenceEntry()], ['src']);

        $bytesBefore = (string) file_get_contents($this->baselinePath);
        $tester = $this->execute(['--remove' => ['0123456789ab']]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('No entry with selector 0123456789ab', $tester->getDisplay());
        self::assertSame($bytesBefore, file_get_contents($this->baselinePath));
    }

    #[Test]
    public function itRefusesAValueThatIsNotASelectorAtAll(): void
    {
        $this->writeBaseline([self::occurrenceEntry()], ['src']);

        $bytesBefore = (string) file_get_contents($this->baselinePath);
        $tester = $this->execute(['--remove' => ['file:src/Legacy.php']]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame($bytesBefore, file_get_contents($this->baselinePath));
    }

    /**
     * The same guard `baseline:update` carries, on the command where the
     * hazard is worst: a narrower run makes every identity outside it look
     * absent, so the rest of the file would be offered up for removal.
     */
    #[Test]
    public function itRefusesARunThatDoesNotCoverTheRecordedScope(): void
    {
        $this->writeBaseline([self::occurrenceEntry()], ['src', 'tests']);

        $tester = $this->execute([], ['src']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('does not cover tests', $tester->getDisplay());
    }

    #[Test]
    public function itAllowsARunWiderThanTheRecordedScope(): void
    {
        $this->writeBaseline([self::occurrenceEntry()], ['src/Domain']);

        $tester = $this->execute([], ['src']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    #[Test]
    public function itWritesANarrowedRunUnderForce(): void
    {
        $entry = self::occurrenceEntry();
        $this->writeBaseline([$entry], ['src', 'tests']);

        $tester = $this->execute(['--force' => true, '--remove' => [$entry->selector()->value]], ['src']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([], self::allEntries($this->baselinePath));
    }

    /**
     * A candidate the run *did* report is not listed: staleness is about the
     * identity being absent from the measured set, not about the file being
     * old.
     */
    #[Test]
    public function itDoesNotOfferAnEntryTheRunStillReports(): void
    {
        $this->writeBaseline([self::occurrenceEntry()], ['src']);

        $tester = $this->execute([], ['src'], [self::gotoFinding()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('No entry is a removal candidate', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $runScope
     * @param list<Finding> $measured
     */
    private function execute(array $options, array $runScope = ['src'], array $measured = []): CommandTester
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();

        $command = new BaselineCleanupCommand(
            new StubBaselineRun($measured, $runScope, AbsolutePath::fromString($this->tempDir)),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            new BaselineCleaner(new FixedClock('2026-09-01T00:00:00+00:00')),
            new BaselineWriter(),
            $declarations,
        );

        $tester = new CommandTester($command);
        $tester->execute(['baseline' => $this->baselinePath, 'paths' => $runScope, ...$options]);

        return $tester;
    }

    /**
     * @param list<BaselineEntry> $entries
     * @param list<string> $scope
     */
    private function writeBaseline(array $entries, array $scope): void
    {
        (new BaselineWriter())->write(
            new Baseline(
                generated: (new FixedClock())->now(),
                scope: $scope,
                entries: $entries,
            ),
            $this->baselinePath,
            AbsolutePath::fromString($this->tempDir),
        );
    }

    private static function edgeIdentity(string $target): BaselineIdentity
    {
        $symbol = SymbolPath::forClass('App\\Web', 'Controller');

        return new BaselineIdentity(
            MetricSubject::declaration(
                DeclarationPath::of($symbol, RelativePath::fromString('src/Web/Controller.php'), DeclarationOrdinal::fromRank(0)),
            )->toCanonical(),
            new FindingChannel(self::EDGE_CHANNEL),
            edge: new BaselineEdge($target, DependencyType::New_),
        );
    }

    private static function occurrenceEntry(): BaselineEntry
    {
        $path = RelativePath::fromString('src/Legacy.php');
        $symbol = SymbolPath::forFile($path);

        return new BaselineEntry(
            new BaselineIdentity(
                MetricSubject::aggregate($symbol)->toCanonical(),
                new FindingChannel(self::OCCURRENCE_CHANNEL),
            ),
            null,
            3,
        );
    }

    private static function gotoFinding(): Finding
    {
        $path = RelativePath::fromString('src/Legacy.php');
        $symbol = SymbolPath::forFile($path);

        return new Finding(
            location: new Location($path, 3),
            subject: MetricSubject::aggregate($symbol),
            symbolPath: $symbol,
            ruleName: 'code-smell.goto',
            code: 'code-smell.goto',
            message: 'finding',
            severity: Severity::Warning,
        );
    }

    /**
     * @return list<string>
     */
    private static function edgeTargetsInFile(string $path): array
    {
        $targets = [];

        foreach (self::allEntries($path) as $entry) {
            if (isset($entry['edge']['target']) && \is_string($entry['edge']['target'])) {
                $targets[] = $entry['edge']['target'];
            }
        }

        return $targets;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function allEntries(string $path): array
    {
        /** @var array{entries: array<string, list<array<string, mixed>>>} $data */
        $data = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        $entries = [];
        foreach ($data['entries'] as $forSymbol) {
            foreach ($forSymbol as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
