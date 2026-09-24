<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Console\ScopeWarningChecker;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass(ScopeWarningChecker::class)]
final class ScopeWarningCheckerTest extends TestCase
{
    private string $tempDir;
    private AbsolutePath $projectRoot;
    private ScopeWarningChecker $checker;
    private ProjectScopeCoverage $coverage;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_scope_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
        $this->projectRoot = AbsolutePath::fromString($this->tempDir);
        $this->checker = new ScopeWarningChecker();
        $this->coverage = new ProjectScopeCoverage(new ComposerReader());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function itReturnsNoWarningsWhenComposerJsonIsMissing(): void
    {
        // Missing composer.json is reported by CheckCommand, not ScopeWarningChecker
        $warnings = $this->checker->describe($this->coverage->uncoveredAutoloadRoots($this->projectRoot, [$this->subPath('src')], AutoloadDevPolicy::Exclude));

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itReturnsNoWarningsForFullCoverage(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);

        $warnings = $this->checker->describe($this->coverage->uncoveredAutoloadRoots($this->projectRoot, [$this->subPath('src')], AutoloadDevPolicy::Exclude));

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itReturnsWarningForPartialCoverage(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'Lib\\' => 'lib/',
                ],
            ],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);
        mkdir($this->tempDir . '/lib', 0o755, true);

        $warnings = $this->checker->describe($this->coverage->uncoveredAutoloadRoots($this->projectRoot, [$this->subPath('src')], AutoloadDevPolicy::Exclude));

        self::assertCount(1, $warnings);
        self::assertSame(
            'Analyzed paths do not cover all autoload entries (missing: lib). Coupling and instability metrics may be incomplete.',
            $warnings[0],
        );
    }

    #[Test]
    public function itDoesNotCheckAutoloadDev(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\' => 'tests/',
                ],
            ],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);
        mkdir($this->tempDir . '/tests', 0o755, true);

        // Analyzing only src/ should NOT warn about missing tests/ (autoload-dev)
        $warnings = $this->checker->describe($this->coverage->uncoveredAutoloadRoots($this->projectRoot, [$this->subPath('src')], AutoloadDevPolicy::Exclude));

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itTreatsDotPathAsFullCoverage(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\' => 'tests/',
                ],
            ],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);
        mkdir($this->tempDir . '/tests', 0o755, true);

        // Passing the project root itself models the `qmx check .` invocation
        $warnings = $this->checker->describe($this->coverage->uncoveredAutoloadRoots($this->projectRoot, [$this->projectRoot], AutoloadDevPolicy::Exclude));

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itSkipsNonexistentAutoloadPaths(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'Lib\\' => 'lib/', // does not exist on disk
                ],
            ],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);

        // Analyzing src covers src; lib doesn't exist so it's skipped — no warning
        $warnings = $this->checker->describe($this->coverage->uncoveredAutoloadRoots($this->projectRoot, [$this->subPath('src')], AutoloadDevPolicy::Exclude));

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itNamesAutoloadEntriesThatDiscoveryNeverEnters(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => ['App\\' => 'src/'],
                'files' => ['vendor/x/helpers.php'],
                'classmap' => ['lib/vendor'],
            ],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);
        mkdir($this->tempDir . '/vendor/x', 0o755, true);
        mkdir($this->tempDir . '/lib/vendor', 0o755, true);
        file_put_contents($this->tempDir . '/vendor/x/helpers.php', '<?php');

        // A whole-project run: nothing is uncovered, and the pruned line still speaks.
        $measurement = $this->coverage->measure($this->projectRoot, [$this->projectRoot], AutoloadDevPolicy::Exclude);

        self::assertSame(
            ['Autoload entries that are, or lie inside, a vendor, node_modules or .git directory are not counted as project scope,'
                . ' and discovery skips them unless a path you name lies inside that directory: lib/vendor, vendor/x/helpers.php (inside vendor).'],
            $this->checker->describe($measurement->uncoveredRoots, $measurement->prunedTargets),
        );
    }

    /**
     * A path named inside a vendor directory is analyzed, and the line still
     * names the entry it covers — so the line may not say the entry goes
     * unanalyzed.
     */
    #[Test]
    public function itMakesNoClaimAboutAnalysisOfAPrunedEntryARunNamesExplicitly(): void
    {
        $this->writeComposerJson([
            'autoload' => ['psr-4' => ['App\\' => 'src/'], 'files' => ['vendor/x/helpers.php']],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);
        mkdir($this->tempDir . '/vendor/x', 0o755, true);
        file_put_contents($this->tempDir . '/vendor/x/helpers.php', '<?php');

        $measurement = $this->coverage->measure(
            $this->projectRoot,
            [$this->subPath('src'), $this->subPath('vendor/x/helpers.php')],
            AutoloadDevPolicy::Exclude,
        );
        $warnings = $this->checker->describe($measurement->uncoveredRoots, $measurement->prunedTargets);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('vendor/x/helpers.php (inside vendor)', $warnings[0]);
        self::assertStringNotContainsString('analyzed', $warnings[0]);
    }

    #[Test]
    public function itPrintsBothLinesWhenASliceAlsoDropsPrunedEntries(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => ['App\\' => 'src/', 'Lib\\' => 'lib/'],
                'classmap' => ['node_modules/pkg'],
            ],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);
        mkdir($this->tempDir . '/lib', 0o755, true);
        mkdir($this->tempDir . '/node_modules/pkg', 0o755, true);

        $measurement = $this->coverage->measure($this->projectRoot, [$this->subPath('src')], AutoloadDevPolicy::Exclude);

        self::assertSame(
            [
                'Analyzed paths do not cover all autoload entries (missing: lib). Coupling and instability metrics may be incomplete.',
                'Autoload entries that are, or lie inside, a vendor, node_modules or .git directory are not counted as project scope,'
                    . ' and discovery skips them unless a path you name lies inside that directory: node_modules/pkg (inside node_modules).',
            ],
            $this->checker->describe($measurement->uncoveredRoots, $measurement->prunedTargets),
        );
    }

    #[Test]
    public function itPrintsNoPrunedLineForAManifestWithoutSuchEntries(): void
    {
        $this->writeComposerJson(['autoload' => ['psr-4' => ['App\\' => 'src/']]]);
        mkdir($this->tempDir . '/src', 0o755, true);
        // Present on disk but undeclared: discovery prunes it, and the manifest never promised it.
        mkdir($this->tempDir . '/vendor/x', 0o755, true);

        $measurement = $this->coverage->measure($this->projectRoot, [$this->projectRoot], AutoloadDevPolicy::Exclude);

        self::assertSame([], $measurement->prunedTargets);
        self::assertSame([], $this->checker->describe($measurement->uncoveredRoots, $measurement->prunedTargets));
    }

    private function subPath(string $relative): AbsolutePath
    {
        return $this->projectRoot->joinRelative(RelativePath::fromString($relative));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
