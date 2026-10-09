<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Integration\Inheritance;

use Error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\DitGlobalCollector;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Analysing a class whose external parent cannot load still reports complete coverage.
 *
 * The separate fixture-shape test deliberately loads the parent to prove that
 * its file remains reachable and broken. The analysis runs in child processes;
 * source-based ancestry must not execute that file. The unit collector probe
 * measures the absence of autoloader calls directly.
 *
 * Runs use an empty working directory so the repository's qmx.yaml cannot
 * contribute unrelated layer findings.
 */
#[CoversClass(DitGlobalCollector::class)]
final class UnloadableExternalParentRunTest extends TestCase
{
    // A string, not `::class`: the fixture's directory is excluded from static
    // analysis, so a resolved reference to it would be an unknown class there.
    // Nothing checks this spelling, which is why the shape guard below exists.
    private const UNLOADABLE_PARENT =
        'Qualimetrix\\Tests\\Analysis\\Evidence\\Design\\Fixtures\\UnloadableParent\\ReachableChild';

    private string $workingDirectory;

    private string $analysedFile;

    protected function setUp(): void
    {
        $this->workingDirectory = \sprintf(
            '%s/qmx_unloadable_parent_%s',
            sys_get_temp_dir(),
            bin2hex(random_bytes(6)),
        );

        if (!mkdir($this->workingDirectory) && !is_dir($this->workingDirectory)) {
            throw new RuntimeException('Failed to create the working directory');
        }

        $this->analysedFile = $this->workingDirectory . '/Local.php';

        $written = file_put_contents($this->analysedFile, \sprintf(
            "<?php\n\nnamespace Probe;\n\nclass Local extends \\%s\n{\n}\n",
            self::UNLOADABLE_PARENT,
        ));

        if ($written === false) {
            throw new RuntimeException('Failed to create the analysed fixture');
        }
    }

    protected function tearDown(): void
    {
        // Removed as a tree: the run writes a `.qmx-cache/` into its working
        // directory even under `--no-cache`, so the directory is never empty.
        self::removeTree($this->workingDirectory);
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
        }

        rmdir($path);
    }

    /**
     * Without this, every case below would pass on a fixture that had been
     * renamed, moved or repaired: a parent nobody can find scores the same
     * depth as one whose file throws, and the run stays complete either way.
     */
    #[Test]
    public function itConfirmsTheFixtureIsReachableAndStillCannotFinishLoading(): void
    {
        try {
            class_exists(self::UNLOADABLE_PARENT, true);
        } catch (Error $error) {
            self::assertStringContainsString('QmxNeverShipped', $error->getMessage());
            self::assertFalse(class_exists(self::UNLOADABLE_PARENT, false));

            return;
        }

        self::fail(\sprintf(
            'Loading %s no longer throws, so every run case here proves nothing.',
            self::UNLOADABLE_PARENT,
        ));
    }

    /**
     * The unrestricted case is not redundant: under `--only-rule` the run
     * executes a fraction of the system, so a future resolution of an analysed
     * name somewhere else would not be reached by the narrowed cases at all.
     *
     * @param list<string> $ruleScope
     */
    #[TestWith([0, ['--only-rule=design.dit']])]
    #[TestWith([1, ['--only-rule=design.dit']])]
    #[TestWith([0, []])]
    #[Test]
    public function itKeepsCoverageCompleteWhenAnExternalParentCannotFinishLoading(
        int $workers,
        array $ruleScope,
    ): void {
        $process = new Process([
            \PHP_BINARY,
            \dirname(__DIR__, 6) . '/bin/qmx',
            'check',
            $this->analysedFile,
            ...$ruleScope,
            '--workers=' . $workers,
            '--no-cache',
            '--no-progress',
            '--format=json',
            '--fail-on=none',
        ], $this->workingDirectory);
        $process->run();

        /** @var array<string, mixed> $report */
        $report = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('error', $report, 'The run ended with an internal error');
        self::assertTrue($report['coverage']['complete'], 'The run reported incomplete coverage');
        self::assertSame(0, $report['coverage']['failed']);
        self::assertSame(1, $report['coverage']['analyzed']);
        self::assertSame(0, $process->getExitCode());
    }
}
