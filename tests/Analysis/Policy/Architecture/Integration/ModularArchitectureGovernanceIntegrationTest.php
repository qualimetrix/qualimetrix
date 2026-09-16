<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use FilesystemIterator;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

final class ModularArchitectureGovernanceIntegrationTest extends TestCase
{
    #[Group('live-freshness')]
    #[Test]
    public function itChecksEveryGeneratedProjectionWithoutWriting(): void
    {
        [$exitCode, $output] = $this->runProcess([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture.php',
            '--check',
        ]);

        self::assertSame(0, $exitCode, $output);
    }

    #[Test]
    public function itRoutesFreshnessOraclesExactlyOnceThroughAggregateCheck(): void
    {
        $this->assertFreshnessScriptGraph($this->composer());
    }

    /** @param array{scripts: array<string, string|list<string>>} $composer */
    private function assertFreshnessScriptGraph(array $composer): void
    {
        $scripts = $composer['scripts'];

        self::assertSame(
            ['Composer\\Config::disableProcessTimeout', 'phpunit --no-coverage --exclude-group=benchmark'],
            $scripts['test'],
            'Standalone composer test must retain its full freshness coverage.',
        );
        self::assertSame(
            [
                'Composer\\Config::disableProcessTimeout',
                'python3 scripts/phpunit-aggregate.py',
            ],
            $scripts['test:aggregate'],
        );
        self::assertSame(['@architecture:check', '@selfcheck:analysis'], $scripts['selfcheck']);
        self::assertSame(
            'php bin/qmx check src/ --baseline=qmx-baseline.json --fail-on=warning --memory-limit=512M',
            $scripts['selfcheck:analysis'],
        );
        self::assertSame('@test:aggregate', $this->scriptSteps($scripts, 'check:code')[2]);
        self::assertContains('@architecture:check', $this->scriptSteps($scripts, 'check:artifacts'));
        self::assertContains('@suppression-snapshot:check', $this->scriptSteps($scripts, 'check:artifacts'));
        self::assertContains(
            "python3 -m unittest discover -s tests/System/TestRunnerConfiguration/Tests -p 'test_*.py'",
            $this->scriptSteps($scripts, 'test:cross-tool'),
        );
        self::assertSame(['@gate:self-test', '@selfcheck:analysis', '@directives:audit'], $scripts['check:self']);
    }

    #[Test]
    public function itPublishesOnlyPermanentExactCompositionBindingsForDiInternals(): void
    {
        $manifest = $this->manifest();
        self::assertSame(2, $manifest['version']);
        self::assertArrayNotHasKey('temporary_internal_grants', $manifest);

        $bindings = [];
        foreach ($manifest['declarations'] as $target => $declaration) {
            foreach ($declaration['consumers'] as $consumer) {
                if (($consumer['relation'] ?? 'import') !== 'composition_binding') {
                    continue;
                }
                self::assertSame('internal', $declaration['visibility']);
                self::assertSame('Infrastructure.DependencyInjection', $consumer['owner']);
                self::assertNull($consumer['closes_in']);
                self::assertNotEmpty($consumer['operations']);
                self::assertArrayHasKey($consumer['source_fqcn'], $manifest['declarations']);
                self::assertNotSame($consumer['source_fqcn'], $target);
                $bindings[$consumer['source_fqcn'] . "\0" . $target] = true;
            }
        }
        self::assertNotEmpty($bindings);

        $rows = $this->tsv('production-composition-bindings.tsv');
        self::assertCount(\count($bindings), $rows);
        foreach ($rows as $row) {
            self::assertSame('used', $row['behavioral_verdict']);
            self::assertNotSame('', $row['qmx_projection']);
            self::assertSame($row['declared_operations'], $row['observed_operations']);
            self::assertNotSame('', $row['observed_operations']);
        }
        self::assertContains('service_alias,service_reference,service_registration', array_column($rows, 'observed_operations'));
        self::assertContains('conditional_service_reference', array_column($rows, 'observed_operations'));
        self::assertContains('definition_argument_mutation', array_column($rows, 'observed_operations'));
    }

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
     */
    #[Test]
    public function itFailsWhenAPhpunitTestClassHasNoConfiguredSuite(): void
    {
        $this->withIsolatedProject(function (string $projectRoot): void {
            $directory = $projectRoot . '/tests/Reporting/Formatter/Suppressed/UnwiredLevelProbe';
            $probePath = $directory . '/GuardProbeTest.php';

            self::assertDirectoryDoesNotExist($directory);
            self::assertTrue(mkdir($directory));
            self::assertNotFalse(file_put_contents($probePath, <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Qualimetrix\Tests\Reporting\Formatter\Suppressed\UnwiredLevelProbe;

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
            self::assertStringContainsString('tests/Reporting/Formatter/Suppressed/UnwiredLevelProbe/GuardProbeTest.php', $output);
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

    #[Test]
    public function itPublishesTheReviewedTopologyEvidenceAndRejectsProductionToTestImports(): void
    {
        self::assertCount(28, $this->tsv('test-orphan-dispositions.tsv'));
        self::assertCount(6, $this->tsv('test-system-support-owners.tsv'));
        self::assertSame([], $this->tsv('production-to-test-imports.tsv'));
        self::assertNotEmpty($this->tsv('production-public-imports.tsv'));
        self::assertNotEmpty($this->tsv('production-module-fan-in.tsv'));
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $contents = file_get_contents($this->root() . '/docs/internal/modular-architecture-manifest.json');
        self::assertIsString($contents);
        $manifest = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }

    /** @return array{scripts: array<string, string|list<string>>} */
    private function composer(): array
    {
        $contents = file_get_contents($this->root() . '/composer.json');
        self::assertIsString($contents);
        $composer = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertArrayHasKey('scripts', $composer);
        self::assertIsArray($composer['scripts']);

        return $composer;
    }

    /**
     * @param array<string, string|list<string>> $scripts
     *
     * @return list<string>
     */
    private function scriptSteps(array $scripts, string $name): array
    {
        self::assertArrayHasKey($name, $scripts);
        $steps = $scripts[$name];

        return \is_string($steps) ? [$steps] : $steps;
    }

    /** @return list<array<string, string>> */
    private function tsv(string $name): array
    {
        $lines = file($this->root() . '/docs/internal/generated/modular-architecture/' . $name, \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $headerLine = array_shift($lines);
        self::assertIsString($headerLine);
        $header = array_map(
            static function (?string $column): string {
                if (!\is_string($column)) {
                    throw new LogicException('TSV header contains a non-string column.');
                }

                return $column;
            },
            str_getcsv($headerLine, "\t", '"', '\\'),
        );

        return array_map(
            static function (string $line) use ($header): array {
                $row = array_combine($header, str_getcsv($line, "\t", '"', '\\'));

                return array_map(static fn(?string $value): string => $value ?? '', $row);
            },
            array_values(array_filter($lines, static fn(string $line): bool => $line !== '')),
        );
    }

    /** @param list<string> $command
     * @return array{int, string}
     */
    private function runProcess(array $command, ?string $workingDirectory = null): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory ?? $this->root());
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../../../../..');
        self::assertIsString($root);

        return $root;
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
            $this->copyDirectory($sourceRoot . '/src', $projectRoot . '/src');
            $this->copyDirectory(
                $sourceRoot . '/docs/internal/generated/modular-architecture',
                $projectRoot . '/docs/internal/generated/modular-architecture',
            );
            self::assertTrue(mkdir($projectRoot . '/scripts'));
            self::assertTrue(copy(
                $sourceRoot . '/scripts/generate-modular-architecture-test-inventory.php',
                $projectRoot . '/scripts/generate-modular-architecture-test-inventory.php',
            ));
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
                'scripts',
                'src/Reporting/Template',
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
}
