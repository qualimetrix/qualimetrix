<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ViolationFilterOrchestrator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use ReflectionProperty;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regression guard for the assumption behind `--show-suppressed` reporting
 * per-rule `exclude_namespaces` / `exclude_paths` suppressions
 * ({@see RuleExclusionStats}): {@see ViolationFilterOrchestrator} reads
 * {@see RuleExecutorInterface::getRuleExclusionStats()} and implicitly relies
 * on the container handing it the *same shared instance* that
 * `AnalysisPipeline` just ran `execute()` on.
 *
 * The unit test ({@see \Qualimetrix\Tests\Unit\Infrastructure\Console\ViolationFilterOrchestratorTest})
 * substitutes a stub `RuleExecutorInterface`, so it cannot see a wiring
 * regression (e.g. the service becoming non-shared or wrapped in a
 * decorator) — that would silently turn the feature into a no-op with every
 * existing test still green. This test runs the real production container
 * end-to-end via `CommandTester`, mirroring
 * {@see \Qualimetrix\Tests\Integration\Infrastructure\Console\RulesCommandWiringTest}.
 */
#[CoversClass(ViolationFilterOrchestrator::class)]
final class RuleExclusionStatsWiringTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-rule-exclusion-wiring-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function itSharesTheSameRuleExecutorInstanceBetweenThePipelineAndTheOrchestrator(): void
    {
        // Cheap, precise complement to the end-to-end test below: proves the
        // exact assumption ViolationFilterOrchestrator relies on, without
        // needing a real analysis run to observe it. RuleExecutorInterface
        // itself is private/inlined in the compiled container (not reachable
        // via $container->get()), so both instances are recovered via
        // reflection from the two public consumers that are wired to it.
        $container = (new ContainerFactory())->create();

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        $command = $container->get(CheckCommand::class);
        \assert($command instanceof CheckCommand);

        $orchestrator = $this->readPrivateProperty($command, 'violationFilterOrchestrator');
        self::assertInstanceOf(ViolationFilterOrchestrator::class, $orchestrator);

        $ruleExecutorFromPipeline = $this->readPrivateProperty($pipeline, 'ruleExecutor');
        $ruleExecutorFromOrchestrator = $this->readPrivateProperty($orchestrator, 'ruleExecutor');

        self::assertSame(
            $ruleExecutorFromPipeline,
            $ruleExecutorFromOrchestrator,
            'RuleExecutorInterface must be a shared service — ViolationFilterOrchestrator '
                . 'reads stats produced by whichever instance the pipeline executed rules on.',
        );
    }

    private function readPrivateProperty(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }

    #[Test]
    public function itSurfacesPerRuleExclusionStatsFromARealRunThroughTheContainer(): void
    {
        // 5 parameters exceeds the default LongParameterListOptions warning
        // threshold (4) but stays under error (6) — a Warning-level violation.
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

        $configPath = $this->tempDir . '/qmx.yaml';
        file_put_contents(
            $configPath,
            <<<YAML
            rules:
              code-smell.long-parameter-list:
                exclude_namespaces:
                  - App\Excluded
            YAML,
        );

        $diagnostics = '';
        $display = $this->runCheck([
            'paths' => [$this->tempDir],
            '--config' => $configPath,
            '--format' => 'text',
            '--no-progress' => true,
            '--show-suppressed' => true,
            '--disable-rule' => ['computed.health', 'architecture.layer-violation'],
        ], $exitCode, $diagnostics);

        // The only violation this fixture could produce is excluded — the
        // report itself must be clean.
        self::assertSame(0, $exitCode, $display);
        self::assertStringContainsString('0 error(s), 0 warning(s)', $display);

        // But the per-rule exclusion mechanism must still have run inside
        // RuleExecutor and its stats must have reached the orchestrator's
        // output — proving the shared-instance assumption holds end-to-end,
        // not just in isolation.
        self::assertStringContainsString(
            '1 violation(s) suppressed by per-rule exclude_namespaces/exclude_paths',
            $diagnostics,
        );
        self::assertStringContainsString('LongParams.php', $diagnostics);
        self::assertStringContainsString('code-smell.long-parameter-list', $diagnostics);
    }

    #[Test]
    public function itOmitsPerRuleExclusionDetailsWithoutShowSuppressed(): void
    {
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

        $configPath = $this->tempDir . '/qmx.yaml';
        file_put_contents(
            $configPath,
            <<<YAML
            rules:
              code-smell.long-parameter-list:
                exclude_namespaces:
                  - App\Excluded
            YAML,
        );

        $diagnostics = '';
        $display = $this->runCheck([
            'paths' => [$this->tempDir],
            '--config' => $configPath,
            '--format' => 'text',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health', 'architecture.layer-violation'],
        ], $exitCode, $diagnostics);

        self::assertSame(0, $exitCode, $display);
        self::assertStringNotContainsString('suppressed by per-rule exclude_namespaces', $diagnostics);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @param-out int $exitCode
     * @param-out string $diagnostics
     */
    private function runCheck(array $input, ?int &$exitCode, string &$diagnostics): string
    {
        $container = (new ContainerFactory())->create();

        $command = $container->get(CheckCommand::class);
        \assert($command instanceof CheckCommand);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input, ['capture_stderr_separately' => true]);
        $diagnostics = $tester->getErrorOutput();

        return $tester->getDisplay();
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
