<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRun;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Support\Console\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The real {@see BaselineRun}, against a real project on disk.
 *
 * Every other functional test of these commands goes through
 * {@see \Qualimetrix\Tests\Support\Console\StubBaselineRun}, which is the
 * right call for enumerating what a command does with a set of findings —
 * but it leaves the seam that *produces* the set untested, and that seam is
 * the whole of §5.5. The one defect that lived there was invisible to every
 * stubbed test by construction: a stub resolves no configuration, so it
 * cannot show that reading the file before the configuration exists changes
 * what the file means.
 *
 * The fixture configures a threshold on `health.complexity` because that
 * family is the one §5.4 cannot declare statically: a `computed.*` /
 * `health.*` channel's shape and direction come from a definition resolved at
 * run time, so an entry on one is exactly the entry that goes inert when a
 * command reads the file too early.
 */
#[CoversClass(BaselineRun::class)]
#[CoversClass(BaselineGenerateCommand::class)]
#[CoversClass(BaselineCleanupCommand::class)]
final class BaselineMeasuredSetSeamTest extends TestCase
{
    private const string COMPUTED_CHANNEL = 'computed.health#health.complexity';

    /**
     * Complex enough that `health.complexity` scores well below the threshold
     * the fixture configures, and simple enough that the score does not
     * depend on anything but this file.
     */
    private const string SOURCE = <<<'PHP'
        <?php

        class Legacy
        {
            public function tangle(int $a, int $b): int
            {
                if ($a > 1) { $a++; } elseif ($a > 2) { $a--; } elseif ($a > 3) { $a *= 2; }
                if ($b > 1) { $b++; } elseif ($b > 2) { $b--; } elseif ($b > 3) { $b *= 2; }
                foreach ([1, 2, 3] as $i) { if ($i > $a && $b < 2) { $a += $i; } }
                while ($a > 100) { $a -= 3; if ($a === 7) { break; } }
                return match (true) { $a > 5 => $a, $b > 5 => $b, default => 0 };
            }
        }
        PHP;

    private const string CONFIG = <<<'YAML'
        computed_metrics:
          health.complexity:
            warning: 99
            error: 5
        YAML;

    private string $tempDir;
    private string $configPath;
    private string $baselinePath;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-baseline-seam-');
        $this->configPath = $this->tempDir . '/qmx.yaml';
        $this->baselinePath = $this->tempDir . '/baseline.json';

        file_put_contents($this->tempDir . '/Legacy.php', self::SOURCE . "\n");
        file_put_contents($this->configPath, self::CONFIG . "\n");
    }

    protected function tearDown(): void
    {
        ComputedMetricDefinitionHolder::reset();
        TempDirectory::remove($this->tempDir);
    }

    /**
     * What was captured is what `check` measures: `generate` immediately
     * followed by `check --baseline` reports nothing at all.
     *
     * This is the property §5.5 exists for, and it is asserted on both sides.
     * The same run without the baseline reports the findings, so "nothing
     * reported" cannot be satisfied by a project that has nothing to report,
     * and the captured channel is named, so it cannot be satisfied by a
     * capture that quietly skipped the family under test.
     */
    #[Test]
    public function itCapturesExactlyWhatCheckMeasuresOverTheSamePaths(): void
    {
        $bare = $this->runCheck([]);

        self::assertStringContainsString('health.complexity', $bare->getDisplay());

        $this->runGenerate();

        self::assertContains(self::COMPUTED_CHANNEL, self::capturedChannels($this->baselinePath));

        $checked = $this->runCheck(['--baseline' => $this->baselinePath]);

        self::assertSame(0, $checked->getStatusCode(), $checked->getDisplay());
        self::assertStringContainsString('No violations found', $checked->getDisplay());
    }

    /**
     * The same file, read by a command that loads it rather than writes it.
     *
     * `cleanup` is where reading too early does visible damage: an entry it
     * could not parse is offered for removal, so a user following the
     * command's own advice deletes an acceptance `check` is still applying.
     */
    #[Test]
    public function itReadsBackACapturedComputedEntryAsAnApplicableOne(): void
    {
        $this->runGenerate();

        $cleanup = $this->runCleanup();

        self::assertSame(0, $cleanup->getStatusCode(), $cleanup->getDisplay());
        self::assertStringContainsString('No entry is a removal candidate', $cleanup->getDisplay());
        self::assertStringNotContainsString('cannot be applied', $cleanup->getDisplay());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCheck(array $options): CommandTester
    {
        return $this->execute(CheckCommand::class, [
            'paths' => [$this->tempDir],
            '--config' => $this->configPath,
            '--no-progress' => true,
            ...$options,
        ]);
    }

    private function runGenerate(): void
    {
        $tester = $this->execute(BaselineGenerateCommand::class, [
            'baseline' => $this->baselinePath,
            'paths' => [$this->tempDir],
            '--config' => $this->configPath,
        ]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    private function runCleanup(): CommandTester
    {
        return $this->execute(BaselineCleanupCommand::class, [
            'baseline' => $this->baselinePath,
            'paths' => [$this->tempDir],
            '--config' => $this->configPath,
        ]);
    }

    /**
     * A fresh container *and* a cleared computed-metric holder per command,
     * because that is what the CLI gives each invocation: a new process.
     *
     * The reset is the load-bearing half, and leaving it out is how this test
     * would have proved nothing. {@see ComputedMetricDefinitionHolder} is
     * static, so the definitions the preceding `baseline:generate` resolved
     * survive into the next command inside one PHPUnit process — and a
     * command reading its baseline before resolving anything would still find
     * a fully populated vocabulary, passing whichever order it used. On a
     * real machine the second command starts with nothing.
     *
     * @param class-string<Command> $commandClass
     * @param array<string, mixed> $input
     */
    private function execute(string $commandClass, array $input): CommandTester
    {
        ComputedMetricDefinitionHolder::reset();

        /** @var Command $command */
        $command = (new ContainerFactory())->create()->get($commandClass);

        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    /**
     * @return list<string>
     */
    private static function capturedChannels(string $path): array
    {
        /** @var array{entries: array<string, list<array{channel: string}>>} $data */
        $data = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        $channels = [];
        foreach ($data['entries'] as $forSymbol) {
            foreach ($forSymbol as $entry) {
                $channels[] = $entry['channel'];
            }
        }

        return $channels;
    }
}
