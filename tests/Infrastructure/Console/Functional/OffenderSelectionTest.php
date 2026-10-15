<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ConsoleDiagnostics;
use Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Infrastructure\Console\Support\SplitStreamConsoleOutput;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Process\Process;

#[CoversClass(CheckCommand::class)]
final class OffenderSelectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-offenders-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o755, true);
        file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":{"Sample\\\\":"src/"}}}');
        for ($index = 1; $index <= 15; ++$index) {
            $namespace = 'Sample\\N' . str_pad((string) $index, 2, '0', \STR_PAD_LEFT);
            $branches = '';
            for ($branch = 1; $branch <= $index + 20; ++$branch) {
                $branches .= "if (\$x > $branch) { \$x += $branch; }\n";
            }
            file_put_contents($this->root . '/src/C' . $index . '.php', "<?php\nnamespace $namespace;\nfinal class C { public function run(int \$x): int {\n$branches return \$x; } }\n");
        }
    }

    protected function tearDown(): void
    {
        for ($index = 1; $index <= 15; ++$index) {
            unlink($this->root . '/src/C' . $index . '.php');
        }
        unlink($this->root . '/composer.json');
        rmdir($this->root . '/src');
        rmdir($this->root);
    }

    #[Test]
    public function itSelectsAndRanksEveryMeasuredCandidateBeforeTop(): void
    {
        $all = $this->json(['--format-opt=top=20']);
        self::assertCount(15, $all['worstClasses']);
        self::assertCount(15, $all['worstNamespaces']);
        $scores = array_column($all['worstClasses'], 'healthOverall');
        $orderedScores = $scores;
        sort($orderedScores);
        self::assertSame($orderedScores, $scores);
        self::assertSame($all['worstClasses'], $this->json(['--format-opt=rank-by=score', '--format-opt=top=20'])['worstClasses']);
        $last = $all['worstClasses'][14]['symbolPath'];
        $namespace = substr($last, 0, (int) strrpos($last, '\\'));
        foreach (['--namespace=exact:' . $namespace, '--namespace=regex:^' . preg_quote($last, '/') . '$', '--class=' . $last] as $selection) {
            $selected = $this->json([$selection, '--format-opt=top=20']);
            self::assertCount(1, $selected['worstClasses']);
            self::assertSame($last, $selected['worstClasses'][0]['symbolPath']);
            self::assertSame($all['worstClasses'][14]['healthScores'], $selected['worstClasses'][0]['healthScores']);
            self::assertArrayNotHasKey('overall', $selected['worstClasses'][0]['healthScores']);
        }
        $density = $this->json(['--format-opt=rank-by=density', '--format-opt=top=20']);
        $expected = $all['worstClasses'];
        usort($expected, static function (array $a, array $b): int {
            $density = $b['violationDensity'] <=> $a['violationDensity'];

            return $density !== 0 ? $density : ($a['symbolPath'] <=> $b['symbolPath']);
        });
        self::assertSame(array_column($expected, 'symbolPath'), array_column($density['worstClasses'], 'symbolPath'));
        $classDensity = $this->json(['--class=' . $last, '--format-opt=rank-by=density']);
        self::assertSame($last, $classDensity['worstClasses'][0]['symbolPath']);
        $namespaced = $this->json(['--namespace=exact:' . $namespace]);
        self::assertCount(1, $namespaced['worstNamespaces']);
        self::assertSame($namespace, $namespaced['worstNamespaces'][0]['symbolPath']);
        self::assertSame(1, $namespaced['worstNamespaces'][0]['size.class-count.sum']);
        self::assertArrayNotHasKey('size.class-count', $namespaced['worstNamespaces'][0]);
        foreach ([3 => 12, 12 => 3] as $top => $remaining) {
            $summary = $this->runApplication(['--format=summary', '--format-opt=top=' . $top])['stdout'];
            self::assertSame(2, substr_count($summary, '+' . $remaining . ' more (use --format-opt=top=15)'));
            self::assertStringNotContainsString('+' . $remaining . ' more (use --format=html', $summary);
        }
    }

    #[Test]
    public function itRefusesCountInBothSpellingsBeforeReadingSources(): void
    {
        foreach ([['--format-opt=rank-by=count'], ['--format-opt', 'rank-by=count']] as $spelling) {
            $process = $this->runCli(['--format=json', ...$spelling], 'missing');
            self::assertSame(3, $process->getExitCode());
            $output = $process->getOutput() . $process->getErrorOutput();
            self::assertStringContainsString('expected one of: score, density', $output);
            self::assertStringNotContainsString('does not exist', $output);
        }
        foreach (['score', 'density'] as $ranking) {
            $result = $this->runApplication(['--format=json', '--format-opt', 'rank-by=' . $ranking]);
            self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        }
    }

    #[Test]
    public function itCarriesConfiguredThresholdsAndOnlyDimensionScoresThroughBothViews(): void
    {
        $config = $this->root . '/thresholds.yaml';
        $definitions = "computed_metrics:\n  health.overall:\n    formula: '82.8'\n    warning: 90\n    error: 85\n  health.complexity:\n    formula: '55'\n    warning: 60\n    error: 50\n";
        foreach (['cohesion', 'coupling', 'typing', 'maintainability'] as $dimension) {
            $definitions .= "  health.$dimension:\n    formula: '100'\n";
        }
        file_put_contents($config, $definitions);
        try {
            $global = $this->json(['--config=' . $config, '--format-opt=top=20']);
            $scoped = $this->json(['--config=' . $config, '--namespace=subtree:Sample', '--format-opt=top=20']);
            foreach (['worstNamespaces', 'worstClasses'] as $kind) {
                self::assertCount(15, $global[$kind]);
                self::assertSame($global[$kind], $scoped[$kind]);
                self::assertSame('high complexity', $global[$kind][0]['reason']);
                self::assertCount(5, $global[$kind][0]['healthScores']);
                self::assertArrayNotHasKey('overall', $global[$kind][0]['healthScores']);
            }
            $summary = $this->runApplication(['--config=' . $config, '--format=summary', '--ansi'])['stdout'];
            self::assertStringContainsString("\033[31m82.8\033[0m", $summary);
            file_put_contents($config, str_replace("formula: '82.8'", "formula: 'min(m[\"health.complexity\"], 82.8)'", $definitions));
            $minimum = $this->json(['--config=' . $config]);
            self::assertSame('high complexity', $minimum['worstClasses'][0]['reason']);
            self::assertSame(array_keys($global['worstClasses'][0]['healthScores']), array_keys($minimum['worstClasses'][0]['healthScores']));
        } finally {
            unlink($config);
        }
    }

    /** @param list<string> $options
     * @return array<string, mixed>
     */
    private function json(array $options): array
    {
        $result = $this->runApplication(['--format=json', ...$options]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        $decoded = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Keep collection, configuration and presentation real for every invocation;
     * only the native entrypoint refusals need another interpreter process.
     *
     * @param list<string> $options
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runApplication(array $options): array
    {
        $previousDirectory = getcwd();
        if ($previousDirectory === false) {
            throw new RuntimeException('Cannot determine the current directory.');
        }
        $previousErrorReporting = error_reporting();
        $previousDisplayErrors = \ini_get('display_errors');
        $terminalEnvironment = ['LINES' => getenv('LINES'), 'COLUMNS' => getenv('COLUMNS')];

        try {
            $application = Application::forProduct(
                static fn(ConsoleDiagnostics $diagnostics) => (new ContainerFactory())->create($diagnostics),
                OutOfMemoryHint::register(),
            );
            $application->setAutoExit(false);
            $output = new SplitStreamConsoleOutput(stderrDecorated: false);
            $input = new ArgvInput(['qmx', 'check', 'src', '--working-dir=' . $this->root, '--no-cache', '--workers=0', '--fail-on=none', ...$options]);
            $exitCode = $application->run($input, $output);

            return [
                'stdout' => $output->standardOutputContent(),
                'stderr' => $output->errorOutputContent(),
                'exitCode' => $exitCode,
            ];
        } finally {
            error_reporting($previousErrorReporting);
            if ($previousDisplayErrors !== false) {
                ini_set('display_errors', $previousDisplayErrors);
            }
            foreach ($terminalEnvironment as $name => $value) {
                putenv($name . ($value === false ? '' : '=' . $value));
            }
            chdir($previousDirectory);
        }
    }

    /** @param list<string> $options */
    private function runCli(array $options, string $path = 'src'): Process
    {
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 4) . '/bin/qmx', 'check', $path, '--working-dir=' . $this->root, '--no-cache', '--workers=0', '--fail-on=none', ...$options]);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }
}
