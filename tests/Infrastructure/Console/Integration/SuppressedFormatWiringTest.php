<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end regression guard for Ш6 decision (д): capture of the per-rule
 * exclusion ledger — and therefore the `suppressed` format's completeness —
 * must arm on `--show-suppressed` OR on the resolved format being
 * `suppressed`, from either the CLI flag or `qmx.yaml`'s `format:` key. A
 * unit test against {@see RuntimeConfigurator} in isolation cannot see a
 * wiring regression here: only a real container run proves the two routes
 * to the format agree and that `--show-suppressed` on `-f text` still works.
 */
#[CoversClass(RuntimeConfigurator::class)]
final class SuppressedFormatWiringTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-suppressed-format-wiring-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);

        file_put_contents(
            $this->tempDir . '/LongParams.php',
            <<<'PHP'
            <?php

            namespace App\Excluded;

            function longParams(int $a, int $b, int $c, int $d, int $e): int
            {
                return $a + $b + $c + $d + $e;
            }
            PHP,
        );

        file_put_contents(
            $this->tempDir . '/qmx.yaml',
            <<<YAML
            rules:
              code-smell.long-parameter-list:
                suppress_namespaces:
                  - App\Excluded
                suppress_paths:
                  - src/DoesNotExist.php
            YAML,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * The per-rule ledger's `excludedFindings` is opt-in
     * ({@see \Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats}), and
     * before Ш6 the only thing that turned it on was `--show-suppressed`.
     * Selecting `--format=suppressed` alone must arm it too, or the format
     * would silently publish an empty ledger half.
     */
    #[Test]
    public function itPopulatesTheLedgerHalvesWhenOnlyTheFormatFlagAsksForThem(): void
    {
        [$exitCode, $display] = $this->runCheck([
            'paths' => [$this->tempDir],
            '--config' => $this->tempDir . '/qmx.yaml',
            '--format' => 'suppressed',
            '--no-progress' => true,
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation'],
        ]);

        self::assertSame(0, $exitCode, $display);

        $payload = $this->decode($display);
        self::assertGreaterThanOrEqual(1, $payload['byMechanism']['rule-namespace-suppression']);
        self::assertContainsEquals(
            ['mechanism' => 'rule-path-suppression', 'suppressor' => 'code-smell.long-parameter-list: src/DoesNotExist.php'],
            $payload['neverMatched'],
        );
    }

    /**
     * `--format=suppressed` and `format: suppressed` in `qmx.yaml` are two
     * routes to the same output ({@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator::configure()}'s
     * dual gate) — a config-only selection must not silently under-report
     * relative to the CLI flag.
     */
    #[Test]
    public function itReportsTheSameCompositionWhetherTheFormatCameFromTheCliOrFromConfig(): void
    {
        [$cliExitCode, $cliDisplay] = $this->runCheck([
            'paths' => [$this->tempDir],
            '--config' => $this->tempDir . '/qmx.yaml',
            '--format' => 'suppressed',
            '--no-progress' => true,
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation'],
        ]);

        $configPath = $this->tempDir . '/qmx-format.yaml';
        file_put_contents(
            $configPath,
            <<<YAML
            format: suppressed
            rules:
              code-smell.long-parameter-list:
                suppress_namespaces:
                  - App\Excluded
                suppress_paths:
                  - src/DoesNotExist.php
            YAML,
        );

        [$configExitCode, $configDisplay] = $this->runCheck([
            'paths' => [$this->tempDir],
            '--config' => $configPath,
            '--no-progress' => true,
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation'],
        ]);

        self::assertSame(0, $cliExitCode, $cliDisplay);
        self::assertSame(0, $configExitCode, $configDisplay);

        $cliPayload = $this->decode($cliDisplay);
        $configPayload = $this->decode($configDisplay);

        unset($cliPayload['meta'], $configPayload['meta']);
        self::assertSame($cliPayload, $configPayload);
    }

    /**
     * `--show-suppressed` on the ordinary text format is a separate route
     * that must keep working unchanged — Ш6 decision (д) is a disjunction,
     * not a migration off the flag.
     */
    #[Test]
    public function itKeepsShowSuppressedWorkingOnTextFormat(): void
    {
        $diagnostics = '';
        $display = $this->runCheckCapturingDiagnostics([
            'paths' => [$this->tempDir],
            '--config' => $this->tempDir . '/qmx.yaml',
            '--format' => 'text',
            '--no-progress' => true,
            '--show-suppressed' => true,
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation'],
        ], $diagnostics);

        self::assertStringContainsString('0 error(s), 0 warning(s)', $display);
        self::assertStringContainsString(
            'suppressed by per-rule suppress_namespaces/suppress_namespace_channels/suppress_paths',
            $diagnostics,
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{0: int, 1: string}
     */
    private function runCheck(array $input): array
    {
        $container = (new ContainerFactory())->create();
        $command = $container->get(CheckCommand::class);
        \assert($command instanceof CheckCommand);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input, ['capture_stderr_separately' => true]);

        return [$exitCode, $tester->getDisplay()];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @param-out string $diagnostics
     */
    private function runCheckCapturingDiagnostics(array $input, string &$diagnostics): string
    {
        $container = (new ContainerFactory())->create();
        $command = $container->get(CheckCommand::class);
        \assert($command instanceof CheckCommand);

        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true, 'verbosity' => OutputInterface::VERBOSITY_NORMAL]);
        $diagnostics = $tester->getErrorOutput();

        return $tester->getDisplay();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff((array) scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
