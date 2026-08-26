<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Integration;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\FindingFilterOrchestrator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use ReflectionProperty;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regression guard for the assumption behind `--show-suppressed` reporting
 * per-rule `exclude_namespaces`, `exclude_namespace_channels`, and `exclude_paths` suppressions
 * ({@see RuleExclusionStats}): {@see FindingFilterOrchestrator} reads
 * {@see RuleExecutionInterface::exclusionStats()} and implicitly relies
 * on the container handing it the *same shared instance* that
 * `AnalysisPipeline` just ran `execute()` on.
 *
 * The end-to-end cases come in a pair, and the pair is the point. One runs a
 * rule whose producer name *is* its class's `NAME`, where a breakdown keyed by
 * the instance and one keyed by the finding's producer are indistinguishable.
 * The other runs the computed-metric family, where they are not: one class
 * publishes under seven producer names, so the tally either says which
 * dimension was silenced or hides all seven behind the class.
 *
 * The unit test ({@see \Qualimetrix\Tests\Infrastructure\Console\Unit\FindingFilterOrchestratorTest})
 * substitutes a stub `RuleExecutionInterface`, so it cannot see a wiring
 * regression (e.g. the service becoming non-shared or wrapped in a
 * decorator) — that would silently turn the feature into a no-op with every
 * existing test still green. This test runs the real production container
 * end-to-end via `CommandTester`, mirroring
 * {@see \Qualimetrix\Tests\Integration\Infrastructure\Console\RulesCommandWiringTest}.
 */
#[CoversClass(FindingFilterOrchestrator::class)]
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
    public function itSharesTheSameRuleExecutionInstanceBetweenThePipelineAndTheOrchestrator(): void
    {
        // Cheap, precise complement to the end-to-end test below: proves the
        // exact assumption FindingFilterOrchestrator relies on, without
        // needing a real analysis run to observe it. RuleExecutionInterface
        // itself is private/inlined in the compiled container (not reachable
        // via $container->get()), so both instances are recovered via
        // reflection from the two public consumers that are wired to it.
        $container = (new ContainerFactory())->create();

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        $command = $container->get(CheckCommand::class);
        \assert($command instanceof CheckCommand);

        $orchestrator = $this->readPrivateProperty($command, 'findingFilterOrchestrator');
        self::assertInstanceOf(FindingFilterOrchestrator::class, $orchestrator);

        $ruleExecutorFromPipeline = $this->readPrivateProperty($pipeline, 'ruleExecutor');
        $ruleExecutorFromOrchestrator = $this->readPrivateProperty($orchestrator, 'ruleExecutor');

        self::assertSame(
            $ruleExecutorFromPipeline,
            $ruleExecutorFromOrchestrator,
            'RuleExecutionInterface must be a shared service — FindingFilterOrchestrator '
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
        // threshold (4) but stays under error (6) — a Warning-level finding.
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
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation'],
        ], $exitCode, $diagnostics);

        // The only finding this fixture could produce is excluded — the
        // report itself must be clean.
        self::assertSame(0, $exitCode, $display);
        self::assertStringContainsString('0 error(s), 0 warning(s)', $display);

        // But the per-rule exclusion mechanism must still have run inside
        // RuleExecution and its stats must have reached the orchestrator's
        // output — proving the shared-instance assumption holds end-to-end,
        // not just in isolation.
        self::assertStringContainsString(
            '1 violation(s) suppressed by per-rule exclude_namespaces/exclude_namespace_channels/exclude_paths',
            $diagnostics,
        );
        self::assertStringContainsString('LongParams.php', $diagnostics);
        self::assertStringContainsString('code-smell.long-parameter-list', $diagnostics);
    }

    /**
     * If this disappears, the per-rule tally can go back to naming the class
     * that ran instead of the producer that published — and a reader silencing
     * one health dimension would be told the whole computed-metric family had
     * been silenced, with no way to tell which of the seven it was.
     *
     * The owner is asked of the product rather than spelled: a literal owner
     * that the run does not recognise would be refused before analysis starts,
     * and this case would go red on option-owner addressability instead of on
     * the key it exists to check.
     */
    #[Test]
    public function itKeysThePerRuleBreakdownByTheProducerOfTheFindingNotTheRuleThatRan(): void
    {
        $owner = self::producerOfCohesionHealth();

        file_put_contents(
            $this->tempDir . '/Poor.php',
            <<<'PHP'
            <?php

            namespace App\Excluded;

            final class Poor
            {
                private $a;
                private $b;
                private $c;
                private $d;
                private $e;
                private $f;

                public function a() { return $this->a; }
                public function b() { return $this->b; }
                public function c() { return $this->c; }
                public function d() { return $this->d; }
                public function e() { return $this->e; }
                public function f() { return $this->f; }
            }
            PHP,
        );

        // warning == error == 100 makes the dimension fire on any score at all,
        // so the case does not depend on how badly the fixture scores.
        $configPath = $this->tempDir . '/qmx.yaml';
        file_put_contents(
            $configPath,
            <<<YAML
            computed_metrics:
              health.cohesion: { warning: 100, error: 100 }
            rules:
              {$owner}:
                exclude_namespaces:
                  - App\Excluded
            YAML,
        );

        $diagnostics = '';
        $this->runCheck([
            'paths' => [$this->tempDir],
            '--config' => $configPath,
            '--format' => 'text',
            '--no-progress' => true,
            '--only-rule' => [$owner],
        ], $exitCode, $diagnostics, OutputInterface::VERBOSITY_VERBOSE);

        self::assertStringContainsString('suppressed by per-rule exclude_namespaces', $diagnostics);
        self::assertMatchesRegularExpression(
            '/suppressed by per-rule [^(]+\(health\.cohesion: \d+\)/',
            $diagnostics,
            'The tally must name the dimension whose findings were excluded, not the class that ran.',
        );
    }

    /**
     * The producer the run itself says publishes `health.cohesion` — the name
     * both the `rules:` key and the tally are keyed by.
     *
     * It names no rule class, which is what makes this case able to tell a
     * breakdown keyed by the producer from one keyed by the instance.
     */
    private static function producerOfCohesionHealth(): string
    {
        $container = (new ContainerFactory())->create();

        $universe = $container->get(ChannelUniverse::class);
        \assert($universe instanceof ChannelUniverse);

        $producer = $universe->snapshot(new ResolvedComputedMetricDefinitions([
            new ComputedMetricDefinition(
                name: 'health.cohesion',
                formulas: ['namespace' => 'lcom'],
                description: 'Fixture dimension',
                levels: [SymbolLevel::Namespace_],
                inverted: true,
            ),
        ]))->producerOf('health.cohesion');

        self::assertIsString($producer, 'Channel "health.cohesion" names no producer in this run.');

        return $producer;
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
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation'],
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
    private function runCheck(
        array $input,
        ?int &$exitCode,
        string &$diagnostics,
        int $verbosity = OutputInterface::VERBOSITY_NORMAL,
    ): string {
        $container = (new ContainerFactory())->create();

        $command = $container->get(CheckCommand::class);
        \assert($command instanceof CheckCommand);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input, ['capture_stderr_separately' => true, 'verbosity' => $verbosity]);
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
