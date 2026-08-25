<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRun;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The real {@see BaselineRun}, against a real project on disk.
 *
 * Every other functional test of these commands goes through
 * {@see \Qualimetrix\Tests\Analysis\Policy\Baseline\Support\StubBaselineRun}, which is the
 * right call for enumerating what a command does with a set of findings —
 * but it leaves the seam that *produces* the set untested, and that seam is
 * the whole of ADR 0017. The one defect that lived there was invisible to every
 * stubbed test by construction: a stub resolves no configuration, so it
 * cannot show that reading the file before the configuration exists changes
 * what the file means.
 *
 * The fixture configures a threshold on `health.complexity` because that
 * family is the one ADR 0017 cannot declare statically: a `computed.*` /
 * `health.*` channel's shape and direction come from a definition resolved at
 * run time, so an entry on one is exactly the entry that goes inert when a
 * command reads the file too early.
 */
#[CoversClass(BaselineRun::class)]
#[CoversClass(BaselineGenerateCommand::class)]
#[CoversClass(BaselineCleanupCommand::class)]
final class BaselineMeasuredSetSeamTest extends TestCase
{
    private const string COMPUTED_CHANNEL = 'health.complexity';

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
        exclude:
          - Excluded
        YAML;

    private const string CONFIG_WITHOUT_EXCLUSIONS = <<<'YAML'
        computed_metrics:
          health.complexity:
            warning: 99
            error: 5
        YAML;

    private const string EVAL_CHANNEL = 'code-smell.eval';

    private string $tempDir;
    private string $configPath;
    private string $baselinePath;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-baseline-seam-');
        $this->configPath = $this->tempDir . '/qmx.yaml';
        $this->baselinePath = $this->tempDir . '/baseline.json';

        file_put_contents($this->tempDir . '/Legacy.php', self::SOURCE . "\n");
        mkdir($this->tempDir . '/Excluded', 0o755, true);
        file_put_contents($this->tempDir . '/Excluded/ConfiguredPath.php', "<?php\neval('return 1;');\n");
        file_put_contents($this->configPath, self::CONFIG . "\n");
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->tempDir);
    }

    /**
     * What was captured is what `check` measures: `generate` immediately
     * followed by `check --baseline` reports nothing at all.
     *
     * This is the property ADR 0017 exists for, and it is asserted on both sides.
     * The same run without the baseline reports the findings, so "nothing
     * reported" cannot be satisfied by a project that has nothing to report,
     * and the captured channel is named, so it cannot be satisfied by a
     * capture that quietly skipped the family under test.
     */
    #[Test]
    public function itCapturesExactlyWhatCheckMeasuresOverTheSamePaths(): void
    {
        file_put_contents($this->configPath, self::CONFIG_WITHOUT_EXCLUSIONS . "\n");
        $this->runGenerate();
        $unfilteredChannels = self::capturedChannels($this->baselinePath);
        self::assertContains(self::EVAL_CHANNEL, $unfilteredChannels);

        file_put_contents($this->configPath, self::CONFIG . "\n");
        $bare = $this->runCheck([]);

        self::assertStringContainsString('health.complexity', $bare->getDisplay());

        $this->runGenerate(['--force' => true]);

        $filteredChannels = self::capturedChannels($this->baselinePath);
        self::assertContains(self::COMPUTED_CHANNEL, $filteredChannels);
        self::assertNotContains(self::EVAL_CHANNEL, $filteredChannels);

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

    #[Test]
    public function itAppliesDiscoveryExclusionBeforeEveryBaselineLifecycleMeasurement(): void
    {
        file_put_contents($this->configPath, self::CONFIG_WITHOUT_EXCLUSIONS . "\n");
        $this->runGenerate(['--only-rule' => ['code-smell.eval']]);
        $subject = self::capturedSubject($this->baselinePath, self::EVAL_CHANNEL);

        file_put_contents($this->configPath, self::CONFIG . "\n");

        $update = $this->runUpdate(['--only-rule' => ['code-smell.eval']]);
        self::assertSame(0, $update->getStatusCode(), $update->getDisplay());
        self::assertMatchesRegularExpression(
            '/skipped  ' . preg_quote($subject . ' ' . self::EVAL_CHANNEL, '/')
            . ' \[[a-f0-9]{16}\] \(not reported by this run\)/',
            $update->getDisplay(),
        );
        self::assertStringContainsString('0 updated, 0 refused, 1 skipped', $update->getDisplay());
        self::assertStringContainsString('No entry moved', $update->getDisplay());

        $cleanup = $this->runCleanup();
        self::assertSame(0, $cleanup->getStatusCode(), $cleanup->getDisplay());
        self::assertStringContainsString(self::EVAL_CHANNEL, $cleanup->getDisplay());

        $explain = $this->runExplain($subject);
        self::assertSame(0, $explain->getStatusCode(), $explain->getDisplay());
        self::assertStringContainsString(self::EVAL_CHANNEL, $explain->getDisplay());
        self::assertStringContainsString('nothing reported', $explain->getDisplay());

        $this->runGenerate(['--force' => true]);
        self::assertNotContains(self::EVAL_CHANNEL, self::capturedChannels($this->baselinePath));
    }

    #[Test]
    public function itValidatesDynamicComputedSelectorsForEveryBaselineLifecycleCommand(): void
    {
        $this->runGenerate();
        $subject = self::capturedSubject($this->baselinePath, self::COMPUTED_CHANNEL);
        // Three distinct spellings, because the output path is a hash of the
        // selector: the producing rule, the channel itself, and the group above
        // it. The pair form used to be the third and is gone.
        $selectors = ['computed.health', self::COMPUTED_CHANNEL, 'health.*'];

        foreach ([
            BaselineGenerateCommand::class,
            BaselineUpdateCommand::class,
            BaselineCleanupCommand::class,
            BaselineExplainCommand::class,
        ] as $commandClass) {
            foreach ($selectors as $selector) {
                $tester = $this->execute($commandClass, $this->baselineInput($commandClass, $subject, $selector));
                self::assertSame(0, $tester->getStatusCode(), $commandClass . ': ' . $tester->getDisplay());
                self::assertStringNotContainsString('does not match any registered', $tester->getDisplay());
            }

            $tester = $this->execute($commandClass, $this->baselineInput($commandClass, $subject, 'computed.stale'));
            self::assertNotSame(0, $tester->getStatusCode(), $commandClass . ': ' . $tester->getDisplay());
            self::assertStringContainsString('does not match any registered', $tester->getDisplay());
        }
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

    /**
     * @param array<string, mixed> $options
     */
    private function runGenerate(array $options = []): CommandTester
    {
        $tester = $this->execute(BaselineGenerateCommand::class, [
            'baseline' => $this->baselinePath,
            'paths' => [$this->tempDir],
            '--config' => $this->configPath,
            ...$options,
        ]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        return $tester;
    }

    private function runCleanup(): CommandTester
    {
        return $this->execute(BaselineCleanupCommand::class, [
            'baseline' => $this->baselinePath,
            'paths' => [$this->tempDir],
            '--config' => $this->configPath,
        ]);
    }

    /** @param array<string, mixed> $options */
    private function runUpdate(array $options = []): CommandTester
    {
        return $this->execute(BaselineUpdateCommand::class, [
            'baseline' => $this->baselinePath,
            'paths' => [$this->tempDir],
            '--config' => $this->configPath,
            ...$options,
        ]);
    }

    private function runExplain(string $subject): CommandTester
    {
        return $this->execute(BaselineExplainCommand::class, [
            'subject' => $subject,
            'paths' => [$this->tempDir],
            '--config' => $this->configPath,
            '--baseline' => $this->baselinePath,
            '--channel' => self::EVAL_CHANNEL,
        ]);
    }

    /**
     * @param class-string<Command> $commandClass
     *
     * @return array<string, mixed>
     */
    private function baselineInput(string $commandClass, string $subject, string $selector): array
    {
        $baseline = $commandClass === BaselineGenerateCommand::class
            ? $this->tempDir . '/selector-' . hash('sha256', $selector) . '.json'
            : $this->baselinePath;
        $input = [
            'paths' => [$this->tempDir],
            '--config' => $this->configPath,
            '--only-rule' => [$selector],
        ];

        if ($commandClass === BaselineExplainCommand::class) {
            $input['subject'] = $subject;
            $input['--channel'] = self::COMPUTED_CHANNEL;
            $input['--baseline'] = $baseline;
        } else {
            $input['baseline'] = $baseline;
        }

        return $input;
    }

    /**
     * A fresh container per command,
     * because that is what the CLI gives each invocation: a new process.
     *
     * matching the fresh capability instance supplied to each CLI invocation.
     *
     * @param class-string<Command> $commandClass
     * @param array<string, mixed> $input
     */
    private function execute(string $commandClass, array $input): CommandTester
    {
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

    private static function capturedSubject(string $path, string $channel): string
    {
        /** @var array{entries: array<string, list<array{channel: string}>>} $data */
        $data = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        foreach ($data['entries'] as $subject => $entries) {
            foreach ($entries as $entry) {
                if ($entry['channel'] === $channel) {
                    return $subject;
                }
            }
        }

        self::fail(\sprintf('Baseline does not contain expected channel "%s".', $channel));
    }
}
