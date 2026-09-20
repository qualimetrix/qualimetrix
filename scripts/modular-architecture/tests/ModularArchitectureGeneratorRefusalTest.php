<?php

declare(strict_types=1);

namespace Qualimetrix\ModularArchitecture\Tests;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

require_once \dirname(__DIR__, 2) . '/subprocess/ChildProcess.php';

final class ModularArchitectureGeneratorRefusalTest extends TestCase
{
    #[Test]
    public function itRejectsCompositionBindingsWhenOnlyIdentityEvidenceRemains(): void
    {
        $cases = [
            ['OutputConfigurator.php', 'register(BaselineCleanupCommand::class)', "register('unrelated.service')"],
            ['AnalysisConfigurator.php', 'new Reference(DelegatingLogger::class)', "new Reference('unrelated.service')"],
            ['OutputConfigurator.php', 'setAlias(BaselineRunInterface::class, BaselineRun::class)', 'setUnrelatedAlias(BaselineRunInterface::class, BaselineRun::class)'],
            ['OutputConfigurator.php', '$container->register(BaselineCleanupCommand::class)', '$unrelated->register(BaselineCleanupCommand::class)'],
            ['OutputConfigurator.php', '$container->setAlias(BaselineRunInterface::class, BaselineRun::class)', '$unrelated->setAlias(BaselineRunInterface::class, BaselineRun::class)'],
            ['ChannelDeclarationCompilerPass.php', '->setArgument(', '->setUnrelatedArgument('],
            ['RuleOptionsCompilerPass.php', 'new Reference($serviceId)', "new Reference('unrelated.service')"],
        ];

        foreach ($cases as [$file, $needle, $replacement]) {
            $sourcePath = $this->sourcePath($file);
            $source = file_get_contents($sourcePath);
            self::assertIsString($source);
            self::assertStringContainsString($needle, $source, $file);
            $override = str_replace($needle, $replacement, $source, $replacements);
            self::assertGreaterThan(0, $replacements, $file);

            $overridePath = tempnam(sys_get_temp_dir(), 'qmx-composition-');
            $mappingPath = tempnam(sys_get_temp_dir(), 'qmx-composition-map-');
            self::assertIsString($overridePath);
            self::assertIsString($mappingPath);
            try {
                file_put_contents($overridePath, $override);
                file_put_contents($mappingPath, json_encode([
                    $this->relativePath($sourcePath) => $overridePath,
                ], \JSON_THROW_ON_ERROR));
                [$exitCode, $output] = $this->runProcess([
                    \PHP_BINARY,
                    $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                    '--check',
                    '--source-overrides=' . $mappingPath,
                ]);

                self::assertNotSame(0, $exitCode, $file . " unexpectedly retained its binding\n" . $output);
                self::assertMatchesRegularExpression('/(?:unclassified composition_binding|composition_binding operation mismatch)/', $output);
            } finally {
                @unlink($overridePath);
                @unlink($mappingPath);
            }
        }
    }

    /**
     * `currentSuite()` is a closed literal enumeration per directory, so a
     * test class under an unlisted directory is classified as `none` and
     * silently omitted from `composer test`. Plant a temporary class in an
     * isolated project under such a directory and assert that the inventory
     * check names it and fails.
     *
     * The probe directory has to be one no `<testsuite>` declares while still
     * parsing to a manifest owner and a level, or the generator refuses it
     * earlier for the wrong reason and the control never reaches the suite
     * classification it is about. The previous address stopped satisfying that
     * when `tests/Reporting/Functional` became a declared suite directory.
     * Registering this one breaks the control loudly rather than quietly: if it
     * is declared and filled, `assertDirectoryDoesNotExist()` fails; if it is
     * declared and empty, PHPUnit exits 2 inside the isolated project; and if
     * the probe class does land in a suite, the exit-code assertion fails.
     *
     * The probe declares no namespace on purpose. Both the suite classification
     * and the level check read the path, so a namespace adds nothing here, and
     * one written in this heredoc is a name the tree spells and no file
     * declares -- which is what `scripts/dangling-test-names.py` reports. A
     * probe per refusal would mean a pinned name per refusal, and a census that
     * grows one entry per instance of a recurring form has stopped being a set.
     */
    #[Test]
    public function itFailsWhenAPhpunitTestClassHasNoConfiguredSuite(): void
    {
        $this->withIsolatedProject(function (string $projectRoot): void {
            $directory = $projectRoot . '/tests/Reporting/GraphProjection/Functional';
            $probePath = $directory . '/GuardProbeTest.php';

            self::assertDirectoryDoesNotExist($directory);
            self::assertTrue(mkdir($directory));
            self::assertNotFalse(file_put_contents($probePath, <<<'PHP'
                <?php

                declare(strict_types=1);

                use PHPUnit\Framework\Attributes\Test;
                use PHPUnit\Framework\TestCase;

                final class GuardProbeTest extends TestCase
                {
                    #[Test]
                    public function itIsNeverActuallyRun(): void
                    {
                        self::assertTrue(true);
                    }
                }

                PHP));

            [$exitCode, $output] = $this->runProcess([
                \PHP_BINARY,
                $projectRoot . '/scripts/generate-modular-architecture-test-inventory.php',
                '--check',
            ], $projectRoot);

            self::assertNotSame(0, $exitCode, $output);
            self::assertStringContainsString('classified as suite "none"', $output);
            self::assertStringContainsString('tests/Reporting/GraphProjection/Functional/GuardProbeTest.php', $output);
        });
    }

    /**
     * A `phpunit.xml.dist` directory may also lack a matching `currentSuite()`
     * classification. This proves that direction fails too -- a literal with no
     * matching <directory> declared for that suite, the shape that let
     * `tests/Architecture/Unit/` and `tests/Architecture/Integration/` sit in
     * the classifier for a directory that was never created, and let
     * `tests/Infrastructure/Console/Functional/` claim suite Functional while
     * phpunit.xml.dist actually runs it under the recursive Infrastructure
     * directory. It perturbs an isolated phpunit.xml.dist by dropping one
     * declared <directory> the classifier still names and runs the inventory
     * script's `--check` against that fixture.
     */
    #[Test]
    public function itFailsWhenACurrentSuiteLiteralHasNoDeclaredDirectory(): void
    {
        $this->withIsolatedProject(function (string $projectRoot): void {
            $configurationPath = $projectRoot . '/phpunit.xml.dist';
            $original = file_get_contents($configurationPath);
            self::assertIsString($original);

            $needle = "            <directory>tests/Analysis/Policy/Baseline/Functional</directory>\n";
            self::assertStringContainsString($needle, $original, 'fixture assumes this declared <directory> line is present verbatim');
            $perturbed = str_replace($needle, '', $original, $replacements);
            self::assertSame(1, $replacements);
            self::assertNotFalse(file_put_contents($configurationPath, $perturbed));

            [$exitCode, $output] = $this->runProcess([
                \PHP_BINARY,
                $projectRoot . '/scripts/generate-modular-architecture-test-inventory.php',
                '--check',
            ], $projectRoot);

            self::assertNotSame(0, $exitCode, $output);
            self::assertStringContainsString(
                'tests/Analysis/Policy/Baseline/Functional is suite Functional in currentSuite()'
                . ' but is not declared under that <testsuite> in phpunit.xml.dist',
                $output,
            );
        });
    }

    /**
     * A test class whose path names two levels is refused by
     * `failUnownedTestClass()` with a sentence of its own, rather than published
     * under an owner parsed from whichever level the walk met first. No path in
     * the tree names two levels, so that branch has an empty population and
     * nothing but a hand plant has ever executed it -- and a refusal that never
     * fires reads from the outside exactly like one that cannot.
     *
     * The probe directory has to be a real level segment under a real manifest
     * owner, or the parse refuses it earlier for the wrong reason.
     *
     * The probe declares no namespace on purpose. Both the suite classification
     * and the level check read the path, so a namespace adds nothing here, and
     * one written in this heredoc is a name the tree spells and no file
     * declares -- which is what `scripts/dangling-test-names.py` reports. A
     * probe per refusal would mean a pinned name per refusal, and a census that
     * grows one entry per instance of a recurring form has stopped being a set.
     */
    #[Test]
    public function itFailsWhenATestClassNamesTwoLevels(): void
    {
        $this->withIsolatedProject(function (string $projectRoot): void {
            $directory = $projectRoot . '/tests/Core/Unit/Integration';
            $probePath = $directory . '/GuardProbeTest.php';

            self::assertDirectoryDoesNotExist($directory);
            self::assertTrue(mkdir($directory));
            self::assertNotFalse(file_put_contents($probePath, <<<'PHP'
                <?php

                declare(strict_types=1);

                use PHPUnit\Framework\Attributes\Test;
                use PHPUnit\Framework\TestCase;

                final class GuardProbeTest extends TestCase
                {
                    #[Test]
                    public function itIsNeverActuallyRun(): void
                    {
                        self::assertTrue(true);
                    }
                }

                PHP));

            [$exitCode, $output] = $this->runProcess([
                \PHP_BINARY,
                $projectRoot . '/scripts/generate-modular-architecture-test-inventory.php',
                '--check',
            ], $projectRoot);

            self::assertNotSame(0, $exitCode, $output);
            self::assertStringContainsString(
                'tests/Core/Unit/Integration/GuardProbeTest.php names 2 of Unit, Integration, Functional,'
                . ' and a test file names exactly one',
                $output,
            );
        });
    }

    /**
     * `assertTestOwnersAreManifestOwners()` refuses a row touching `tests/`
     * whose owner is neither a manifest owner nor one of the counted
     * `NON_MANIFEST_TEST_OWNERS` allowances. Every row in the tree takes one of
     * those two paths, so the refusing branch has no live population either --
     * the counted half of the same check runs on every row, this half runs on
     * none.
     *
     * The `.gitkeep` is the cheapest way in: `classifyOwner()` publishes the
     * non-owner `legacy-placeholder` for one by design. What this proves is the
     * refusal and not the placeholder, so an edit that retires that branch owes
     * this control another shape rather than its deletion.
     */
    #[Test]
    public function itFailsWhenARowUnderTestsPublishesANonManifestOwner(): void
    {
        $this->withIsolatedProject(function (string $projectRoot): void {
            $directory = $projectRoot . '/tests/Probe';

            self::assertDirectoryDoesNotExist($directory);
            self::assertTrue(mkdir($directory));
            self::assertNotFalse(file_put_contents($directory . '/.gitkeep', ''));

            [$exitCode, $output] = $this->runProcess([
                \PHP_BINARY,
                $projectRoot . '/scripts/generate-modular-architecture-test-inventory.php',
                '--check',
            ], $projectRoot);

            self::assertNotSame(0, $exitCode, $output);
            self::assertStringContainsString(
                'legacy-placeholder is not one of the',
                $output,
            );
            self::assertStringContainsString(
                'manifest owners, and 1 row(s) under tests/ publish it: tests/Probe/.gitkeep',
                $output,
            );
        });
    }

    /**
     * The ban on production importing a development namespace is a list of
     * prefixes, and a prefix the name collection never produces refuses
     * nothing while still reading as covered. Four of the thirteen entries
     * were in that state: the collection kept only names under the production
     * root, so every `Qmx*` development root was unreachable, and a probe
     * under one passed with exit 0.
     *
     * **What this proves is that the name collection produces a name under every
     * declared prefix.** Narrowing that filter back to the production root
     * reddens this test, naming the prefixes it stopped reaching -- that is the
     * regression guarded, and it is the defect that happened.
     *
     * What it does not prove is coverage of the tree. The prefixes are read from
     * the same `autoload-dev` section the ban reads, so a namespace declared
     * outside that section is outside the ban and outside this test alike --
     * see `developmentNamespacePrefixes()`.
     *
     * All prefixes are planted into one override and proven by one run: the
     * refusal reports every offence it found, so a prefix missing from the
     * output is one the ban produced no refusal for.
     *
     * The generator runs under `--check` so that a control whose subject has
     * regressed cannot reach `emitGenerated()` and rewrite tracked artifacts
     * from the planted tree. The refusal is reached before any comparison, so
     * the flag costs no discriminating power.
     *
     * The probe names are built by concatenation on purpose. Spelled out, they
     * would be names the tree contains and no file declares, which is what
     * `scripts/dangling-test-names.py` reports.
     */
    #[Test]
    public function itRefusesAProductionImportOfEveryDeclaredDevelopmentNamespace(): void
    {
        $manifest = json_decode((string) file_get_contents($this->root() . '/composer.json'), true);
        self::assertIsArray($manifest);
        $prefixes = array_keys($manifest['autoload-dev']['psr-4'] ?? []);
        self::assertNotSame([], $prefixes, 'no development root to prove anything about');

        $sourcePath = $this->root() . '/src/Core/Version.php';
        $source = file_get_contents($sourcePath);
        self::assertIsString($source);

        $anchor = "final class Version\n{";
        self::assertStringContainsString($anchor, $source);

        $probes = [];
        $expected = [];
        foreach ($prefixes as $index => $prefix) {
            $fqcn = $prefix . 'ReachabilityProbe';
            $expected[] = $fqcn;
            $probes[] = '    private const REACHABILITY_PROBE_' . $index . ' = \\' . $fqcn . '::class;';
        }
        $override = str_replace($anchor, $anchor . "\n" . implode("\n", $probes), $source, $replacements);
        self::assertSame(1, $replacements);

        $overridePath = tempnam(sys_get_temp_dir(), 'qmx-devns-');
        $mappingPath = tempnam(sys_get_temp_dir(), 'qmx-devns-map-');
        self::assertIsString($overridePath);
        self::assertIsString($mappingPath);

        try {
            file_put_contents($overridePath, $override);
            file_put_contents($mappingPath, json_encode([
                $this->relativePath($sourcePath) => $overridePath,
            ], \JSON_THROW_ON_ERROR));

            [$exitCode, $output] = $this->runProcess([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--check',
                '--source-overrides=' . $mappingPath,
            ]);

            self::assertNotSame(0, $exitCode, "production importing a development namespace was accepted\n" . $output);
            self::assertStringContainsString('development-only namespace', $output);

            foreach ($expected as $fqcn) {
                self::assertStringContainsString(
                    $fqcn,
                    $output,
                    $fqcn . ' is declared in autoload-dev but no refusal names it',
                );
            }
        } finally {
            @unlink($overridePath);
            @unlink($mappingPath);
        }
    }

    private function sourcePath(string $filename): string
    {
        $paths = glob($this->root() . '/src/Infrastructure/DependencyInjection/{Configurator,CompilerPass}/' . $filename, \GLOB_BRACE);
        self::assertIsArray($paths);
        self::assertCount(1, $paths, $filename);

        return $paths[0];
    }

    private function relativePath(string $path): string
    {
        return ltrim(substr($path, \strlen($this->root())), '/');
    }

    /** @param callable(string): void $test */
    private function withIsolatedProject(callable $test): void
    {
        $projectRoot = $this->createIsolatedProject();

        try {
            $test($projectRoot);
        } finally {
            $this->removeDirectory($projectRoot);
            self::assertDirectoryDoesNotExist($projectRoot, 'The isolated project fixture must be removed after every negative control.');
        }
    }

    /**
     * A root-level project with its own test directory and no registration is
     * refused by name. This is the direction
     * `assertToolingTestRootRegistrationIsComplete()` cannot answer: its
     * listing comes from a `glob()` of `scripts/*` and `tools/*`, so a root
     * outside those two parents is one it cannot produce and therefore cannot
     * miss. Before `assertEveryTestDirectoryIsScanned()` this plant passed:
     * the root was absent from the inventory entirely, with the check green.
     *
     * The probe is a `.test.js` file under a directory at the repository root,
     * because that is the shape the gap was accepted for -- a project with its
     * own runner, which no `<testsuite>` declares and whose only possible
     * registration is the map.
     *
     * **Both halves of the source are planted, because both are designed for.**
     * The sweep asks `git ls-files --cached --others --exclude-standard`, and
     * `--others` is the half that makes a root refused when it is created
     * rather than one commit later. A single staged plant does not exercise it:
     * a staged file is reported through `--cached` alone, so narrowing the
     * command to `--cached` would delete the refuse-at-creation property and
     * still leave a one-case test green. The unstaged case is what fails if
     * `--others` goes; the staged case is what fails if the sweep goes.
     */
    #[Test]
    public function itFailsWhenARootLevelProjectsTestDirectoryIsUnregistered(): void
    {
        foreach ([true, false] as $stage) {
            $this->withIsolatedProject(function (string $projectRoot) use ($stage): void {
                $directory = $projectRoot . '/probe-tool/tests';

                self::assertDirectoryDoesNotExist($directory);
                self::assertTrue(mkdir($directory, 0700, true));
                self::assertNotFalse(file_put_contents(
                    $directory . '/probe.test.js',
                    "// a probe for an unregistered root-level project\n",
                ));
                if ($stage) {
                    [$exitCode, $output] = $this->runProcess(
                        ['git', 'add', '--', 'probe-tool'],
                        $projectRoot,
                    );
                    self::assertSame(0, $exitCode, $output);
                }

                [$exitCode, $output] = $this->runProcess([
                    \PHP_BINARY,
                    $projectRoot . '/scripts/generate-modular-architecture-test-inventory.php',
                    '--check',
                ], $projectRoot);

                self::assertNotSame(0, $exitCode, $output);
                self::assertStringContainsString(
                    'probe-tool/tests/ holds files git reports and nothing scans it',
                    $output,
                    $stage ? 'staged plant' : 'unstaged plant — the --others half of the sweep',
                );
            });
        }
    }

    /**
     * The exclusion list cannot outlive what it excuses, in either direction.
     *
     * A literal list is what keeps the judged population closed, and a list
     * nothing prunes stops being one: an entry whose path is gone excuses
     * nothing and reads as coverage, and an entry a registration now claims
     * says two contradictory things about the same directory. Both are planted
     * here by perturbing the constant itself, because both are properties of
     * the list rather than of any tree it is run against.
     */
    #[Test]
    public function itFailsWhenANonRootExclusionNoLongerExcusesAnything(): void
    {
        $cases = [
            [
                "'input-doors/fixtures/main/tests/' =>",
                "'input-doors/fixtures/gone/tests/' =>",
                'input-doors/fixtures/gone/tests/ is excused in NON_SCANNED_TEST_DIRECTORIES but git carries'
                    . ' no file under it',
            ],
            [
                'const NON_SCANNED_TEST_DIRECTORIES = [',
                "const NON_SCANNED_TEST_DIRECTORIES = [\n    'tests/' => '   ',",
                'tests/ is excused in NON_SCANNED_TEST_DIRECTORIES with an empty reason',
            ],
            [
                'const NON_SCANNED_TEST_DIRECTORIES = [',
                "const NON_SCANNED_TEST_DIRECTORIES = [\n    'html-report/tests/' => 'a planted probe',",
                // Not the shared opening of both exclusion refusals: that
                // prefix passes whichever of the two fired, and the stale one
                // is only unreachable here by the order of two branches.
                'html-report/tests/ is excused in NON_SCANNED_TEST_DIRECTORIES as "a planted probe" and is at'
                    . ' the same time scanned through the inventory scan scope entry html-report/tests',
            ],
        ];

        foreach ($cases as [$needle, $replacement, $expected]) {
            $this->withIsolatedProject(function (string $projectRoot) use ($needle, $replacement, $expected): void {
                $scriptPath = $projectRoot . '/scripts/generate-modular-architecture-test-inventory.php';
                $source = file_get_contents($scriptPath);
                self::assertIsString($source);
                self::assertStringContainsString($needle, $source);
                $perturbed = str_replace($needle, $replacement, $source, $replacements);
                self::assertSame(1, $replacements, $needle);
                self::assertNotFalse(file_put_contents($scriptPath, $perturbed));

                [$exitCode, $output] = $this->runProcess([\PHP_BINARY, $scriptPath, '--check'], $projectRoot);

                self::assertNotSame(0, $exitCode, $output);
                self::assertStringContainsString($expected, $output);
            });
        }
    }

    private function createIsolatedProject(): string
    {
        $projectRoot = sys_get_temp_dir() . '/qmx-modular-architecture-' . bin2hex(random_bytes(16));
        self::assertTrue(mkdir($projectRoot, 0700));

        try {
            $sourceRoot = $this->root();
            $this->copyDirectory($sourceRoot . '/tests', $projectRoot . '/tests');
            // PHPUnit exits 2 when a <testsuite> names a directory that is not
            // there, so every root the tracked configuration declares has to
            // exist here before the inventory script can reach its own refusal.
            $this->copyDirectory($sourceRoot . '/governance', $projectRoot . '/governance');
            $this->copyDirectory($sourceRoot . '/tools', $projectRoot . '/tools');
            $this->copyDirectory($sourceRoot . '/src', $projectRoot . '/src');
            // A root-level, non-PSR-4 npm project, registered as three keys in
            // TOOLING_TEST_ROOT_OWNERS (html-report/tests/, package.json,
            // vite.config.js). assertToolingTestRootRegistrationIsComplete()
            // checks every registered key's existence directly — is_dir()/
            // is_file(), not only the scripts/*/tests | tools/*/tests glob —
            // so a missing one of these three refuses by itself, before the
            // generator ever reaches the refusal an individual test below
            // plants. Removing this copy step (and 'html-report' from the git
            // add list further down) makes that refusal preempt every planting
            // case whose own refusal is raised later than it. Stated as the
            // mechanism and not as a count: the count moves every time a case
            // is added below, and a stale number beside a growing list reads
            // as a measurement.
            // Only the scanned slice is copied, not node_modules/dist/src.
            $this->copyDirectory($sourceRoot . '/html-report/tests', $projectRoot . '/html-report/tests');
            self::assertTrue(copy(
                $sourceRoot . '/html-report/package.json',
                $projectRoot . '/html-report/package.json',
            ));
            self::assertTrue(copy(
                $sourceRoot . '/html-report/vite.config.js',
                $projectRoot . '/html-report/vite.config.js',
            ));
            $this->copyDirectory(
                $sourceRoot . '/docs/internal/generated/modular-architecture',
                $projectRoot . '/docs/internal/generated/modular-architecture',
            );
            // The generator validates every test path against the manifest owners,
            // so the isolated project needs the manifest itself, not only what
            // the generator writes from it.
            self::assertTrue(copy(
                $sourceRoot . '/docs/internal/modular-architecture-manifest.json',
                $projectRoot . '/docs/internal/modular-architecture-manifest.json',
            ));
            self::assertTrue(mkdir($projectRoot . '/scripts'));
            self::assertTrue(copy(
                $sourceRoot . '/scripts/generate-modular-architecture-test-inventory.php',
                $projectRoot . '/scripts/generate-modular-architecture-test-inventory.php',
            ));
            // The copied inventory script require_once's the module by path,
            // so the scratch project needs its own copy: without it the script
            // dies before it can reach the refusal each case below plants.
            self::assertTrue(mkdir($projectRoot . '/scripts/subprocess'));
            self::assertTrue(copy(
                $sourceRoot . '/scripts/subprocess/ChildProcess.php',
                $projectRoot . '/scripts/subprocess/ChildProcess.php',
            ));
            $this->copyDirectory(
                $sourceRoot . '/scripts/subprocess/tests',
                $projectRoot . '/scripts/subprocess/tests',
            );
            $this->copyDirectory(
                $sourceRoot . '/scripts/promise-effect/tests',
                $projectRoot . '/scripts/promise-effect/tests',
            );
            $this->copyDirectory(
                $sourceRoot . '/scripts/directive-audit/tests',
                $projectRoot . '/scripts/directive-audit/tests',
            );
            $this->copyDirectory(
                $sourceRoot . '/scripts/directive-audit-controls/tests',
                $projectRoot . '/scripts/directive-audit-controls/tests',
            );
            $this->copyDirectory(
                $sourceRoot . '/scripts/tautology-controls/tests',
                $projectRoot . '/scripts/tautology-controls/tests',
            );
            $this->copyDirectory(
                $sourceRoot . '/scripts/finding-gate/tests',
                $projectRoot . '/scripts/finding-gate/tests',
            );
            $this->copyDirectory(
                $sourceRoot . '/scripts/suppression-snapshot/tests',
                $projectRoot . '/scripts/suppression-snapshot/tests',
            );
            $this->copyDirectory(
                $sourceRoot . '/scripts/rename-enumeration/tests',
                $projectRoot . '/scripts/rename-enumeration/tests',
            );
            $this->copyDirectory(
                $sourceRoot . '/scripts/health-calibration/tests',
                $projectRoot . '/scripts/health-calibration/tests',
            );
            $this->copyDirectory(
                $sourceRoot . '/scripts/benchmark/tests',
                $projectRoot . '/scripts/benchmark/tests',
            );
            $this->copyDirectory(
                $sourceRoot . '/scripts/modular-architecture/tests',
                $projectRoot . '/scripts/modular-architecture/tests',
            );
            $this->copyDirectory(
                $sourceRoot . '/scripts/cross-tool-comparison/tests',
                $projectRoot . '/scripts/cross-tool-comparison/tests',
            );
            $this->copyDirectory(
                $sourceRoot . '/scripts/phpunit-aggregate/tests',
                $projectRoot . '/scripts/phpunit-aggregate/tests',
            );
            // Not a test root and not scanned: this is the one path
            // NON_SCANNED_TEST_DIRECTORIES excuses, and
            // assertEveryTestDirectoryIsScanned() refuses an excuse whose path
            // git no longer carries. So the copy list now has to hold every
            // path the generator's literals name, not only every root the
            // tracked configuration declares. Without this copy step (and
            // 'input-doors' in the git add list below) the stale-exclusion
            // refusal preempts every planting case whose own refusal is raised
            // after assertEveryTestDirectoryIsScanned() runs — stated as a
            // mechanism for the reason given beside the html-report copy.
            $this->copyDirectory(
                $sourceRoot . '/input-doors/fixtures/main/tests',
                $projectRoot . '/input-doors/fixtures/main/tests',
            );
            self::assertTrue(copy($sourceRoot . '/.gitignore', $projectRoot . '/.gitignore'));
            self::assertTrue(copy($sourceRoot . '/phpunit.xml.dist', $projectRoot . '/phpunit.xml.dist'));
            self::assertTrue(symlink($sourceRoot . '/vendor', $projectRoot . '/vendor'));

            [$exitCode, $output] = $this->runProcess(['git', 'init', '--quiet'], $projectRoot);
            self::assertSame(0, $exitCode, $output);
            [$exitCode, $output] = $this->runProcess([
                'git',
                'add',
                '--',
                'phpunit.xml.dist',
                'tests',
                'governance',
                'tools',
                'scripts',
                'html-report',
                'input-doors',
            ], $projectRoot);
            self::assertSame(0, $exitCode, $output);

            return $projectRoot;
        } catch (Throwable $exception) {
            $this->removeDirectory($projectRoot);

            throw $exception;
        }
    }

    private function copyDirectory(string $source, string $destination): void
    {
        self::assertTrue(is_dir($source), 'Fixture source directory is missing: ' . $source);
        self::assertTrue(mkdir($destination, 0700, true));

        $entries = new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS);
        foreach ($entries as $entry) {
            self::assertInstanceOf(SplFileInfo::class, $entry);
            $target = $destination . '/' . $entry->getFilename();
            if ($entry->isDir()) {
                $this->copyDirectory($entry->getPathname(), $target);

                continue;
            }

            self::assertTrue(copy($entry->getPathname(), $target));
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            self::assertInstanceOf(SplFileInfo::class, $entry);
            $entryPath = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) {
                self::assertTrue(rmdir($entryPath));

                continue;
            }

            self::assertTrue(unlink($entryPath));
        }
        self::assertTrue(rmdir($path));
    }

    /** @param list<string> $command
     * @return array{int, string}
     */
    private function runProcess(array $command, ?string $workingDirectory = null): array
    {
        $result = ChildProcess::run($command, $workingDirectory ?? $this->root());

        return [$result['exitCode'], $result['stdout'] . $result['stderr']];
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../../../');
        self::assertIsString($root);

        return $root;
    }
}
