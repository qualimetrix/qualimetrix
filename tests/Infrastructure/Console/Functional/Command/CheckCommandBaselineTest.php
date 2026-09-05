<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * `check` with a baseline, end to end: what gets captured, what a breach
 * does to the exit code, and what an entry that cannot be applied does not
 * do.
 *
 * The fixture channel is `code-smell.error-suppression` on purpose: it is an
 * occurrence channel reported at Warning severity, so the default
 * `fail_on: error` makes the promotion of ADR 0017 visible as the difference
 * between a green run and a red one rather than as a word in the output.
 */
#[CoversClass(CheckCommand::class)]
final class CheckCommandBaselineTest extends TestCase
{
    /**
     * Two findings on one occurrence channel of one file, one of them
     * annotated — the smallest source in which the measured set and the
     * analysis output differ.
     */
    private const string FIXTURE_WITH_ONE_IGNORED_MEMBER = <<<'PHP'
        <?php

        class Legacy
        {
            public function first(): void
            {
                echo @$this->missing();
                // @qmx-ignore-next-line code-smell.error-suppression reviewed and accepted
                echo @$this->other();
            }
        }
        PHP;

    private string $tempDir;
    private string $configPath;
    private string $baselinePath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-baseline-check-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);

        $this->configPath = $this->tempDir . '/qmx.yaml';
        $this->baselinePath = $this->tempDir . '/baseline.json';

        // A config of its own, so the repository's `qmx.yaml` — which
        // whitelists functions for this very rule — cannot reach the fixture.
        //
        // The rule selection lives in the config file rather than on the
        // command line because `baseline:generate` accepts no flag that could
        // move the set it measures (ADR 0017): both commands must narrow through
        // the same configuration or they are not measuring one set.
        file_put_contents($this->configPath, <<<'YAML'
            failOn: error
            onlyRules: ['code-smell.error-suppression', 'size.property-count']
            rules:
              size.property-count:
                warning: 1
                error: 2
            suppress_paths: ['*ConfiguredPath.php']
            suppress_namespaces: ['App\ConfiguredNamespace']
            YAML . "\n");
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
    }

    /**
     * A group with one `@qmx-ignore`d member survives `generate` followed by
     * `check` without a word being reported.
     *
     * The entry's count is asserted, not just the silence: capture reads the
     * measured set, so it records the one member the run measured. Reading
     * the raw analysis output instead would store two, and the identity the
     * ceiling measures would then hold one — which is a shrink, still
     * accepted, silently wrong, and one member away from being a breach.
     */
    #[Test]
    public function itKeepsAGroupWithAnIgnoredMemberAcceptedAcrossGenerateAndCheck(): void
    {
        $this->writeFixture(self::FIXTURE_WITH_ONE_IGNORED_MEMBER);

        $this->runGenerate();

        self::assertSame([1], self::capturedCounts());

        $check = $this->runCheck(['--baseline' => $this->baselinePath]);

        self::assertSame(0, $check->getStatusCode(), $check->getDisplay());
        self::assertStringContainsString('No violations found', $check->getDisplay());
    }

    /**
     * `--no-suppression-annotations` shows what the annotations hide; it does
     * not make the run stricter about the baseline.
     *
     * Two runs over identical source: with the flag the ignored finding is
     * reported, and because the ceiling never measured it, it is reported at
     * its own Warning severity and the build stays green. Under the shape
     * this replaces, the flag removed the suppression stage from the measured
     * set: the group then held two members against an entry bounding one, and
     * the run came back with `2 errors` and exit 2 on code nobody had
     * touched. Both halves are asserted, because "green" alone would also
     * hold if the flag had silently become a no-op.
     */
    #[Test]
    public function itCombinesConfiguredAndCliExclusionsWithoutLosingBaselineAnnotationOrGit(): void
    {
        $this->writeFixture(self::FIXTURE_WITH_ONE_IGNORED_MEMBER);
        file_put_contents($this->tempDir . '/ConfiguredPath.php', "<?php\n@strlen('configured path');\n");
        file_put_contents($this->tempDir . '/ConfiguredNamespace.php', "<?php\nnamespace App\\ConfiguredNamespace;\nfinal class Fixture { public int \$first; public int \$second; }\n");
        $this->git('git init -q');
        $this->git('git config user.email test@example.com');
        $this->git('git config user.name Test');
        $this->git('git add .');
        $this->git('git commit -qm initial');

        $originalWorkingDirectory = getcwd();
        if ($originalWorkingDirectory === false || !chdir($this->tempDir)) {
            throw new RuntimeException('Unable to enter the fixture working directory');
        }

        try {
            $this->runGenerate();

            self::assertSame([1], self::capturedCounts());

            file_put_contents($this->tempDir . '/CliPath.php', "<?php\n@strlen('cli path');\n");
            file_put_contents($this->tempDir . '/CliNamespace.php', "<?php\nnamespace App\\CliNamespace;\nfinal class Fixture { public int \$first; public int \$second; }\n");
            file_put_contents($this->tempDir . '/Legacy.php', "\n", \FILE_APPEND);
            $this->git('git add -- *.php');

            $check = $this->runCheck([
                '--baseline' => $this->baselinePath,
                '--no-suppression-annotations' => true,
                '--suppress-path' => ['*CliPath.php'],
                '--suppress-namespace' => ['App\\CliNamespace'],
                '--report' => 'git:staged',
            ]);

            self::assertSame(0, $check->getStatusCode(), $check->getDisplay());
            self::assertStringContainsString('1 warning', $check->getDisplay());
        } finally {
            if (!chdir($originalWorkingDirectory)) {
                throw new RuntimeException('Unable to restore the original working directory');
            }
        }
    }

    /**
     * Capture reads the measured set, and nothing on a command line can widen
     * it: `baseline:generate` does not accept the flag at all, and the count
     * it writes is the one member the annotations left standing.
     *
     * Both halves matter. The refusal alone would be satisfied by a command
     * that took no options; the count alone would be satisfied by a command
     * that took the flag and happened to ignore it — which is the shape that
     * once let a capture record debt a later run could not see.
     */
    #[Test]
    public function itWritesNoEntryForAnAnnotatedFindingAndRefusesToBeAskedTo(): void
    {
        $this->writeFixture(self::FIXTURE_WITH_ONE_IGNORED_MEMBER);

        $this->runGenerate();

        self::assertSame([1], self::capturedCounts());

        $this->expectException(InvalidOptionException::class);

        $this->runGenerate(['--no-suppression-annotations' => true]);
    }

    /**
     * A group that outgrows its entry is a measured breach: every member is
     * reported, promoted to Error, and the run fails — where the same run
     * without the baseline only warns.
     */
    #[Test]
    public function itPromotesAMeasuredBreachToErrorAndFailsTheRun(): void
    {
        $this->writeFixture(<<<'PHP'
            <?php

            class Legacy
            {
                public function first(): void
                {
                    echo @$this->missing();
                }
            }
            PHP);

        $this->runGenerate();

        self::assertSame([1], self::capturedCounts());

        $this->writeFixture(<<<'PHP'
            <?php

            class Legacy
            {
                public function first(): void
                {
                    echo @$this->missing();
                    echo @$this->other();
                }
            }
            PHP);

        $withoutBaseline = $this->runCheck([]);

        self::assertSame(0, $withoutBaseline->getStatusCode(), $withoutBaseline->getDisplay());
        self::assertStringContainsString('2 warnings', $withoutBaseline->getDisplay());

        $withBaseline = $this->runCheck(['--baseline' => $this->baselinePath]);

        self::assertSame(2, $withBaseline->getStatusCode(), $withBaseline->getDisplay());
        self::assertStringContainsString('2 errors', $withBaseline->getDisplay());
    }

    /**
     * The other half of ADR 0017: promotion is scoped to a *measured* breach. An
     * entry the mechanism cannot apply — here one storing magnitudes on an
     * occurrence channel, the shape a later release could introduce for a
     * whole channel at once — says nothing about the debt, so the findings
     * keep their own severity and the build stays green.
     */
    #[Test]
    public function itDoesNotPromoteWhenTheEntryCannotBeApplied(): void
    {
        $this->writeFixture(<<<'PHP'
            <?php

            class Legacy
            {
                public function first(): void
                {
                    echo @$this->missing();
                    echo @$this->other();
                }
            }
            PHP);

        $this->runGenerate();

        self::mutateEntries(static function (array $entry): array {
            // Dropping "count" keeps the scenario purely the shape mismatch
            // the docblock names, rather than also tripping the unrelated
            // rejection of "count" alongside "magnitudes" (P1.1).
            unset($entry['count']);
            $entry['magnitudes'] = [1];

            return $entry;
        }, $this->baselinePath);

        $check = $this->runCheck(['--baseline' => $this->baselinePath]);

        self::assertSame(0, $check->getStatusCode(), $check->getDisplay());
        self::assertStringContainsString('2 warnings', $check->getDisplay());
    }

    private function writeFixture(string $code): void
    {
        file_put_contents($this->tempDir . '/Legacy.php', $code . "\n");
    }

    private function git(string $command): void
    {
        Process::fromShellCommandline($command, $this->tempDir)->mustRun();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCheck(array $options): CommandTester
    {
        $containerFactory = new ContainerFactory();
        $container = $containerFactory->create();

        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);

        $application = new Application();
        $application->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            'paths' => [$this->tempDir],
            '--config' => $this->configPath,
            '--no-progress' => true,
            ...$options,
        ]);

        return $tester;
    }

    /**
     * Capture goes through `baseline:generate`, out of the same container
     * `check` comes from — which is also what proves the command is wired and
     * reachable, not merely constructible.
     *
     * @param array<string, mixed> $options
     */
    private function runGenerate(array $options = []): CommandTester
    {
        $containerFactory = new ContainerFactory();
        $container = $containerFactory->create();

        /** @var BaselineGenerateCommand $command */
        $command = $container->get(BaselineGenerateCommand::class);

        $application = new Application();
        $application->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            'baseline' => $this->baselinePath,
            'paths' => [$this->tempDir],
            '--config' => $this->configPath,
            ...$options,
        ]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        return $tester;
    }

    /**
     * @return list<int>
     */
    private function capturedCounts(): array
    {
        /** @var array{entries: array<string, list<array{count: int}>>} $data */
        $data = json_decode((string) file_get_contents($this->baselinePath), true, flags: \JSON_THROW_ON_ERROR);

        $counts = [];

        foreach ($data['entries'] as $entries) {
            foreach ($entries as $entry) {
                $counts[] = $entry['count'];
            }
        }

        return $counts;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    private static function mutateEntries(callable $mutate, string $path): void
    {
        /** @var array{entries: array<string, list<array<string, mixed>>>} $data */
        $data = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        foreach ($data['entries'] as $symbolKey => $entries) {
            $data['entries'][$symbolKey] = array_map($mutate, $entries);
        }

        file_put_contents($path, json_encode($data, \JSON_THROW_ON_ERROR));
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
