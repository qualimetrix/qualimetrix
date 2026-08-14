<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Baseline\BaselineGenerator;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FixedClock;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\StubBaselineRun;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BaselineGenerateCommand::class)]
final class BaselineGenerateCommandTest extends TestCase
{
    private string $tempDir;
    private string $baselinePath;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-baseline-generate-');
        $this->baselinePath = $this->tempDir . '/baseline.json';
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->tempDir);
    }

    /**
     * Regenerating over an existing file discards every acceptance it
     * records, including entries a user deliberately tightened — and the
     * command line that does it is the one that created the file.
     */
    #[Test]
    public function itRefusesToOverwriteAnExistingBaselineWithoutForce(): void
    {
        file_put_contents($this->baselinePath, 'do not touch');

        $tester = $this->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertSame('do not touch', file_get_contents($this->baselinePath));
    }

    #[Test]
    public function itOverwritesAnExistingBaselineUnderForce(): void
    {
        file_put_contents($this->baselinePath, 'do not touch');

        $tester = $this->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([1], self::countsOf(self::entriesOf($this->baselinePath)));
    }

    /**
     * The refusal is decided before the analysis and must still hold after
     * it.
     *
     * An analysis takes minutes; the neighbouring terminal takes seconds. A
     * check made only up front promises about a moment long past by the time
     * the file is written, and the file it would then overwrite is one this
     * run never decided to replace — the exact loss `--force` exists to make
     * deliberate.
     */
    #[Test]
    public function itRefusesToWriteOverAFileThatAppearedDuringTheAnalysis(): void
    {
        $tester = $this->execute([], null, function (): void {
            file_put_contents($this->baselinePath, 'written by somebody else');
        });

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('appeared since it was read as absent', $tester->getDisplay());
        self::assertSame('written by somebody else', file_get_contents($this->baselinePath));
    }

    #[Test]
    public function itRefusesADanglingSymlinkWithoutForceAndLeavesItUntouched(): void
    {
        $target = $this->tempDir . '/missing-target.json';
        symlink($target, $this->baselinePath);

        $tester = $this->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertTrue(is_link($this->baselinePath));
        self::assertSame($target, readlink($this->baselinePath));
    }

    #[Test]
    public function itRefusesToForceOverADanglingSymlinkWithoutAnUnsafeFallback(): void
    {
        $target = $this->tempDir . '/missing-target.json';
        symlink($target, $this->baselinePath);

        $tester = $this->execute(['--force' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('not a regular file', $tester->getDisplay());
        self::assertTrue(is_link($this->baselinePath));
        self::assertSame($target, readlink($this->baselinePath));
    }

    #[Test]
    public function itRefusesToForceOverASymlinkToARegularFileWithoutTouchingEither(): void
    {
        $target = $this->tempDir . '/target.json';
        $contents = '{"owned": "by another process"}';
        file_put_contents($target, $contents);
        symlink($target, $this->baselinePath);

        $tester = $this->execute(['--force' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('not a regular file', $tester->getDisplay());
        self::assertTrue(is_link($this->baselinePath));
        self::assertSame($target, readlink($this->baselinePath));
        self::assertSame($contents, file_get_contents($target));
    }

    /**
     * The same window on the `--force` path, where the decision was "replace
     * *this* file" rather than "create one".
     *
     * Here the writer's own compare-and-swap token carries the decision into
     * the lock it holds across the rename, so the refusal comes from the
     * write itself rather than from a check in front of it.
     */
    #[Test]
    public function itRefusesToForceOverAFileRewrittenDuringTheAnalysis(): void
    {
        file_put_contents($this->baselinePath, 'the file the decision was about');

        $tester = $this->execute(['--force' => true], null, function (): void {
            file_put_contents($this->baselinePath, 'somebody else got here first');
        });

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('changed since it was read', $tester->getDisplay());
        self::assertSame('somebody else got here first', file_get_contents($this->baselinePath));
    }

    /**
     * The ratchet default writes no `mode` key at all: an entry without one
     * *is* a ratchet entry, and spelling the default would make its absence
     * mean something else in every file already written.
     */
    #[Test]
    public function itWritesNoModeKeyByDefault(): void
    {
        $tester = $this->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        foreach (self::entriesOf($this->baselinePath) as $entry) {
            self::assertArrayNotHasKey('mode', $entry);
        }
    }

    #[Test]
    public function itWritesModeSuppressOnEveryEntryWhenAsked(): void
    {
        $tester = $this->execute(['--mode' => 'suppress']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $entries = self::entriesOf($this->baselinePath);
        self::assertNotSame([], $entries);

        foreach ($entries as $entry) {
            self::assertSame('suppress', $entry['mode'] ?? null);
        }
    }

    #[Test]
    public function itRejectsAModeItDoesNotKnow(): void
    {
        $tester = $this->execute(['--mode' => 'ratched']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Unknown --mode value', $tester->getDisplay());
        self::assertFileDoesNotExist($this->baselinePath);
    }

    /**
     * A run whose findings all sit on channels no rule declares writes an
     * empty baseline. Reporting only "0 entries written" would send the user
     * straight into a `check` that reports everything they believed they had
     * just accepted, with nothing anywhere to explain it.
     */
    #[Test]
    public function itNamesTheGroupsItCouldNotCapture(): void
    {
        $tester = $this->execute([], [self::violation('no-such-rule', 'no-such-rule')]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('were not recorded and will be reported again', $tester->getDisplay());
        self::assertStringContainsString('no rule declares the channel', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $options
     * @param ?list<Violation> $violations
     * @param ?Closure(): void $duringAnalysis what happens to the world while the run is
     *                                         measuring — the window the overwrite decision
     *                                         has to survive
     */
    private function execute(array $options, ?array $violations = null, ?Closure $duringAnalysis = null): CommandTester
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();

        $command = new BaselineGenerateCommand(
            new StubBaselineRun(
                $violations ?? [self::violation('code-smell.goto', 'code-smell.goto')],
                ['src'],
                AbsolutePath::fromString($this->tempDir),
                onMeasure: $duringAnalysis,
            ),
            new BaselineGenerator($declarations, new FixedClock()),
            new BaselineWriter(),
        );

        $tester = new CommandTester($command);
        $tester->execute(['baseline' => $this->baselinePath, 'paths' => ['src'], ...$options]);

        return $tester;
    }

    private static function violation(string $ruleName, string $violationCode): Violation
    {
        $path = RelativePath::fromString('src/Legacy.php');
        $symbol = SymbolPath::forFile($path);

        return new Violation(
            location: new Location($path, 3),
            subject: MetricSubject::aggregate($symbol),
            symbolPath: $symbol,
            ruleName: $ruleName,
            violationCode: $violationCode,
            message: 'finding',
            severity: Severity::Warning,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function entriesOf(string $path): array
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

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<int>
     */
    private static function countsOf(array $entries): array
    {
        return array_map(static fn(array $entry): int => (int) $entry['count'], $entries);
    }
}
