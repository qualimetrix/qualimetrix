<?php

declare(strict_types=1);

namespace Qualimetrix\ModularArchitecture\Tests;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\ModularArchitecture\ProcessOutput;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

require_once \dirname(__DIR__) . '/ProcessOutput.php';

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

    /**
     * `runProcess()` used to read stdout to EOF and only then read stderr. A
     * child that blocks writing more than the OS pipe buffer (64 KB) to
     * whichever stream is read second deadlocks that shape: the child is
     * stuck mid-write, so it never exits or closes the first stream, and the
     * parent's read of that first stream never reaches EOF. Both sides wait
     * forever — this is what hung
     * `itRejectsCompositionBindingsWhenOnlyIdentityEvidenceRemains` against a
     * schema-invalid manifest that made the generator write ~2.6 MB to
     * stderr in one `fwrite`.
     *
     * The property being guarded is "neither stream is left unread while the
     * other blocks", not "stderr, specifically, is read second" — the
     * historical bug happened to read stdout first, but its mirror (stderr
     * first) and a concurrent-looking drain that dropped
     * `stream_set_blocking(..., false)` deadlock the exact same way. A flood
     * on one stream only proves the test bites for the mutation that
     * happened; it does not bite for either of those two, since both leave
     * the *other*, single-flooded stream fully readable. Flooding **both**
     * streams — a plain child that writes past the buffer to stdout, then
     * to stderr, sequentially, nothing concurrent required on the child's
     * side — deadlocks all three shapes and still completes in well under a
     * second against the real fix, so the stronger test costs nothing.
     * Measured directly against the committed `drain()` copied to a scratch
     * directory plus three mutants (sequential stdout-first, sequential
     * stderr-first, `stream_select` kept but blocking left on): the
     * both-flooded child deadlocks all three mutants and only them.
     *
     * The child writes its **stderr** flood first and its **stdout** flood
     * second — reversed from the concatenation order on purpose.
     * `runProcess()` always returns `$stdout . $stderr`, regardless of which
     * descriptor's bytes arrived first, because a correct `drain()` tracks
     * which chunk came from which descriptor rather than accumulating
     * arrival order into one slot. That distinction only shows up in the
     * assertions below when arrival order and concatenation order disagree.
     * Under the child's *original* order in an earlier version of this test
     * (stdout first, then stderr), a mutant `drain()` that appended both
     * streams onto slot 0 and left slot 1 empty produced a byte-identical
     * result by coincidence — arrival order already matched the wanted
     * concatenation order — and passed every assertion here, including the
     * whole test class and `composer architecture:check`. Reversing the
     * child's write order breaks that coincidence: the same merge-onto-one-
     * slot mutant now produces "stderr-bytes-then-stdout-bytes", which
     * disagrees with the expected "stdout-bytes-then-stderr-bytes" below and
     * is caught. Measured, not reasoned, both ways: the merge mutant passes
     * under the old child order and fails under this one; the three deadlock
     * mutants above (sequential either-first, `stream_select` with blocking
     * left on) still deadlock under either child order.
     *
     * Each stream carries its own 256-byte cyclic payload — ascending
     * (`chr(0)..chr(255)`) on stdout, descending on stderr — not one
     * repeated byte, and not the *same* cyclic pattern on both. Measured,
     * both fixes: a homogeneous single-byte payload makes reordering the
     * chunks *within* one stream unobservable by construction (every byte
     * in it is identical), and using the *same* cyclic pattern on both
     * streams made a slot **swap** pass by coincidence wherever the two
     * floods' lengths overlap, since a prefix of one repeating pattern is
     * byte-identical to a fresh run of the same pattern. Two distinct
     * sequences close both of those.
     *
     * What this payload does **not** buy, also measured rather than
     * reasoned about: intra-stream chunk reordering stays a blind spot, at
     * 0 detections in 10 runs. `stream_get_contents()` chunk boundaries on
     * this system are always multiples of 256 — a probe of ~45 chunks
     * across both tests found none that were not — so a swapped-chunk
     * mutant never lands out of phase with the cyclic block; this isn't a
     * rare coincidence the payload mostly guards against, it is the
     * invariable case here. Closing it would need a payload that encodes
     * absolute position (so any reorder becomes visible regardless of chunk
     * alignment), which this test does not attempt. It sits alongside the
     * other known, accepted blind spot: a `stream_select` busy-spin
     * (`timeout = 0` instead of `null`) is invisible to a byte-content
     * assertion and would need a CPU-time or syscall-count check, which
     * would be flaky.
     *
     * This test's remit is the deadlock property specifically — "neither
     * stream is left unread while the other blocks" — not `drain()`
     * correctness in general. It cannot observe a `drain()` that returns as
     * soon as either stream reaches EOF: in this child's shape both
     * descriptors close simultaneously at exit, by which point even a
     * truncating drain has already read everything from both. That shape —
     * one descriptor closing while the other still has more than a buffer's
     * worth left to deliver — is covered separately, without a deadline
     * harness because it cannot hang, by
     * `itDrainsAChildThatClosesOneDescriptorWhileTheOtherKeepsWriting()`.
     *
     * `runProcess()` runs in-process, so a deadlocked call would hang this
     * very PHPUnit run rather than fail it — the exact failure mode this
     * guards against (a CI job burning its timeout instead of going red).
     * This test therefore drives the real `runProcess()` from an external
     * harness process and bounds only the outer supervision: it polls
     * `proc_get_status()` (never a blocking pipe read — see
     * `runWithDeadline()`) and kills the harness once the deadline passes, so
     * a regression here fails this test instead of hanging the suite.
     */
    #[Test]
    public function itDrainsAChildThatFloodsBothStreamsWithoutDeadlocking(): void
    {
        // Both well past the 64 KB default OS pipe buffer on macOS and
        // Linux, and deliberately unequal so neither assertion below could
        // pass by coincidence on a same-size, same-byte flood.
        $stdoutFloodBytes = 1_048_576;
        $stderrFloodBytes = 2_097_152;
        $outputFile = tempnam(sys_get_temp_dir(), 'qmx-pipe-harness-stdout-');
        $errorFile = tempnam(sys_get_temp_dir(), 'qmx-pipe-harness-stderr-');
        $harnessPath = tempnam(sys_get_temp_dir(), 'qmx-pipe-harness-');
        self::assertIsString($outputFile);
        self::assertIsString($errorFile);
        self::assertIsString($harnessPath);

        try {
            // 256 distinct bytes repeated per stream, not one byte repeated
            // -- see the docblock above for why -- and a *different*
            // sequence per stream (ascending on stdout, descending on
            // stderr), not the same one at two lengths: two streams of the
            // same repeating pattern are indistinguishable from each other
            // wherever their lengths overlap, which would make a slot swap
            // pass by coincidence (measured -- an earlier version of this
            // test used one shared pattern and missed exactly that mutant).
            // Both flood sizes are multiples of 256.
            $ascendingBlockExpr = 'implode("", array_map("chr", range(0, 255)))';
            $descendingBlockExpr = 'implode("", array_map("chr", range(255, 0, -1)))';
            $floodCommand = [
                \PHP_BINARY,
                '-r',
                \sprintf(
                    'fwrite(STDERR, str_repeat(%s, %d)); fwrite(STDOUT, str_repeat(%s, %d));',
                    $descendingBlockExpr,
                    intdiv($stderrFloodBytes, 256),
                    $ascendingBlockExpr,
                    intdiv($stdoutFloodBytes, 256),
                ),
            ];

            $template = <<<'PHP'
                <?php
                require __AUTOLOAD__;
                require __TEST_CLASS_FILE__;

                $reflection = new ReflectionClass(\Qualimetrix\ModularArchitecture\Tests\ModularArchitectureGeneratorRefusalTest::class);
                $instance = $reflection->newInstanceWithoutConstructor();
                $method = $reflection->getMethod('runProcess');

                [$exitCode, $output] = $method->invoke($instance, __FLOOD_COMMAND__);

                fwrite(STDOUT, $exitCode . "\n" . $output);
                PHP;

            $harnessSource = str_replace(
                ['__AUTOLOAD__', '__TEST_CLASS_FILE__', '__FLOOD_COMMAND__'],
                [
                    var_export($this->root() . '/vendor/autoload.php', true),
                    var_export(__FILE__, true),
                    var_export($floodCommand, true),
                ],
                $template,
            );
            self::assertNotFalse(file_put_contents($harnessPath, $harnessSource));

            [$exitCode, $timedOut] = $this->runWithDeadline([\PHP_BINARY, $harnessPath], 5.0, $outputFile, $errorFile);
            $harnessStderr = file_get_contents($errorFile);
            self::assertIsString($harnessStderr);

            self::assertFalse(
                $timedOut,
                'runProcess() did not finish within the deadline. Historically this meant a stream left '
                . 'unread while the child blocked writing the other past the OS pipe buffer, but the '
                . 'timeout only observes "did not finish" -- treat that as the leading hypothesis, not an '
                . 'established cause. Harness stderr: ' . $harnessStderr,
            );
            self::assertSame(0, $exitCode, 'harness process exit code; stderr: ' . $harnessStderr);

            $harnessOutput = file_get_contents($outputFile);
            self::assertIsString($harnessOutput);
            $parts = explode("\n", $harnessOutput, 2);
            self::assertCount(2, $parts, 'harness output missing the exit-code line; stderr: ' . $harnessStderr);
            [$childExitLine, $childOutput] = $parts;
            self::assertSame('0', $childExitLine, 'flood child exit code; harness stderr: ' . $harnessStderr);
            self::assertSame(
                $stdoutFloodBytes + $stderrFloodBytes,
                \strlen($childOutput),
                'combined drained byte count',
            );
            $ascendingBlock = implode('', array_map('chr', range(0, 255)));
            $descendingBlock = implode('', array_map('chr', range(255, 0, -1)));
            $this->assertStreamBytes(
                str_repeat($ascendingBlock, intdiv($stdoutFloodBytes, 256)),
                substr($childOutput, 0, $stdoutFloodBytes),
                'stdout slot',
            );
            $this->assertStreamBytes(
                str_repeat($descendingBlock, intdiv($stderrFloodBytes, 256)),
                substr($childOutput, $stdoutFloodBytes),
                'stderr slot',
            );
        } finally {
            @unlink($harnessPath);
            @unlink($outputFile);
            @unlink($errorFile);
        }
    }

    /**
     * Distinct from the deadlock case above: this shape cannot hang. Once
     * one descriptor closes, only one stream remains, and a well-behaved
     * `drain()` (or, for that matter, the historical buggy one) finishes
     * reading it and returns — it needs no deadline supervision. What it
     * targets is a different mutant: a `drain()` that returns as soon as
     * *either* stream reaches EOF, discarding whatever the other stream
     * still had in flight. `itDrainsAChildThatFloodsBothStreamsWithoutDeadlocking()`
     * cannot see that mutant, because both its streams close simultaneously
     * at child exit, after everything has already been read from both —
     * this test's child closes one stream while the other still has more
     * than a pipe buffer left to deliver, so a truncating `drain()` loses
     * real, measurable bytes rather than nothing — measured by the
     * `strlen($stderr)` assertion below, which must run, and be read,
     * before the child's own exit code: the defect abandons an unread pipe,
     * and `proc_close()` closing it out from under the still-writing child
     * is what turns that into a non-zero exit, a real but incidental
     * symptom rather than the loss itself.
     */
    #[Test]
    public function itDrainsAChildThatClosesOneDescriptorWhileTheOtherKeepsWriting(): void
    {
        // 8x the 64 KB default OS pipe buffer: guarantees the child cannot have
        // finished writing its tail before stdout closes and stderr's buffer
        // fills at least once. Not a guarantee about read-call counts --
        // stream_get_contents() sometimes returns all of it in a single call
        // (measured: 8/8 runs on this machine on one occasion, 4-5 calls on
        // another) -- only about how much is still in flight when the drain
        // this test targets would wrongly stop.
        $tailBytes = 524_288;
        $command = [
            \PHP_BINARY,
            '-r',
            \sprintf('fclose(STDOUT); fwrite(STDERR, str_repeat("E", %d));', $tailBytes),
        ];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        [$stdout, $stderr] = ProcessOutput::drain($pipes[1], $pipes[2], self::fail(...));
        $exitCode = proc_close($process);

        // The two length checks come before the exit-code check on purpose:
        // the defect this test targets (a drain() that returns at the first
        // feof()) leaves $pipes[2] open and unread while the child is still
        // blocked writing to it. proc_close() then closes that abandoned
        // pipe out from under the child, whose fwrite() fails and which
        // exits 255 -- a real but incidental symptom, not the defect. With
        // the exit-code check first (measured against this exact mutant),
        // PHPUnit stops there and the message below explaining the actual
        // data loss is never printed.
        //
        // A length check rather than assertSame('', $stdout, ...): a swapped
        // or merged drain() would otherwise put the whole stderr flood into
        // $stdout and dump it in full on failure -- the same 2 MB-report
        // problem the byte-content assertions above were rewritten to avoid.
        self::assertSame(
            0,
            \strlen($stdout),
            'stdout must stay empty -- closed before anything could be written to it (got '
            . \strlen($stdout) . ' bytes instead)',
        );
        self::assertSame(
            $tailBytes,
            \strlen($stderr),
            'stderr tail must not be lost after stdout closes early — a drain that returns on the '
            . 'first EOF loses whatever the other stream still had in flight',
        );
        self::assertSame(
            0,
            $exitCode,
            'flood child exit code -- reached only once the lengths above already matched, so a '
            . 'non-zero code here is a real, independent problem rather than the abandoned-pipe '
            . 'symptom the length checks above would otherwise have masked',
        );
    }

    /**
     * A cheap-first, short-on-failure comparison for the multi-megabyte
     * stream payloads above. `self::assertSame()` on two ~1-2 MB strings
     * would, on a mismatch, print both operands in full — measured at
     * roughly 2 MB across the two assertions this replaces, for a defect
     * whose useful description is a length and a byte offset.
     */
    private function assertStreamBytes(string $expected, string $actual, string $label): void
    {
        self::assertSame(\strlen($expected), \strlen($actual), $label . ': byte count');
        if ($expected === $actual) {
            return;
        }

        $offset = 0;
        $length = \strlen($expected);
        while ($offset < $length && $expected[$offset] === $actual[$offset]) {
            ++$offset;
        }
        self::fail(\sprintf(
            '%s: content differs at byte offset %d (expected 0x%02X, got 0x%02X)',
            $label,
            $offset,
            \ord($expected[$offset]),
            \ord($actual[$offset]),
        ));
    }

    /**
     * Bounds `$command` by wall clock without ever performing a blocking pipe
     * read on it: only `proc_get_status()` polling and, past the deadline,
     * `proc_terminate()`. A supervisor that itself read a pipe from `$command`
     * could deadlock exactly the way the code under test here might, which is
     * why `$command`'s own stdout and stderr are redirected straight to
     * files (`file` descriptors, not `pipe` ones) instead of being read by
     * this process at all.
     *
     * @param list<string> $command
     *
     * @return array{int, bool} the process exit code (meaningless when the
     *                          deadline was hit) and whether the deadline was hit
     */
    private function runWithDeadline(array $command, float $seconds, string $stdoutFile, string $stderrFile): array
    {
        $process = proc_open($command, [1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']], $pipes);
        self::assertIsResource($process);

        $deadline = microtime(true) + $seconds;
        $timedOut = false;
        while (true) {
            $status = proc_get_status($process);
            self::assertIsArray($status);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, \defined('SIGKILL') ? \SIGKILL : 9);
                break;
            }
            usleep(20_000);
        }

        return [proc_close($process), $timedOut];
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
     * miss. Before `assertEveryTestDirectoryIsClaimed()` this plant passed:
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
                'input-doors/fixtures/gone/tests/ is excused in NON_ROOT_TEST_DIRECTORIES but git carries'
                    . ' no file under it',
            ],
            [
                'const NON_ROOT_TEST_DIRECTORIES = [',
                "const NON_ROOT_TEST_DIRECTORIES = [\n    'tests/' => '   ',",
                'tests/ is excused in NON_ROOT_TEST_DIRECTORIES with an empty reason',
            ],
            [
                'const NON_ROOT_TEST_DIRECTORIES = [',
                "const NON_ROOT_TEST_DIRECTORIES = [\n    'html-report/tests/' => 'a planted probe',",
                // Not the shared opening of both exclusion refusals: that
                // prefix passes whichever of the two fired, and the stale one
                // is only unreachable here by the order of two branches.
                'html-report/tests/ is excused in NON_ROOT_TEST_DIRECTORIES as "a planted probe" and is at'
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
            self::assertTrue(mkdir($projectRoot . '/scripts/modular-architecture'));
            self::assertTrue(copy(
                $sourceRoot . '/scripts/modular-architecture/ProcessOutput.php',
                $projectRoot . '/scripts/modular-architecture/ProcessOutput.php',
            ));
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
            // NON_ROOT_TEST_DIRECTORIES excuses, and
            // assertEveryTestDirectoryIsClaimed() refuses an excuse whose path
            // git no longer carries. So the copy list now has to hold every
            // path the generator's literals name, not only every root the
            // tracked configuration declares. Without this copy step (and
            // 'input-doors' in the git add list below) the stale-exclusion
            // refusal preempts every planting case whose own refusal is raised
            // after assertEveryTestDirectoryIsClaimed() runs — stated as a
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
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory ?? $this->root());
        self::assertIsResource($process);
        [$stdout, $stderr] = ProcessOutput::drain($pipes[1], $pipes[2], self::fail(...));

        return [proc_close($process), $stdout . $stderr];
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../../../');
        self::assertIsString($root);

        return $root;
    }
}
