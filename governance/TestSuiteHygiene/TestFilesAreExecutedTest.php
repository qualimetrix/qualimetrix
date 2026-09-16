<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use FilesystemIterator;
use JsonException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every test class the tree carries is one the suite actually runs.
 *
 * `phpunit.xml.dist` enumerates its test directories by hand, so a directory
 * created without being registered holds files that exist, compile, read as
 * tests and are never executed. This repository has already paid for that
 * defect once: 110 tests sat unexecuted for three runs under a green
 * `composer check`.
 *
 * **Nothing here re-implements a rule PHPUnit or the runner owns; both are
 * asked.** A guard that re-derived which files a `<directory>` entry claims, or
 * which arguments the runner passes, would be a second copy of the thing being
 * guarded — and a second copy is exactly the defect class. So:
 *
 * - what a suite *could* run is `--list-tests` for that suite with no argument
 *   but the configuration;
 * - what `composer check` *does* run is the runner's own per-suite command,
 *   printed by `scripts/phpunit-aggregate.py --print-commands` and executed
 *   verbatim with `--list-tests` appended;
 * - the difference between the two is whatever the runner excludes, in whatever
 *   form it excludes it. No selector is modelled, so a `--filter`, a `--group`
 *   or a separated `--exclude-group live-freshness` narrows the measurement the
 *   same way it narrows the run.
 *
 * **The unit judged is the class, not the file.** PHPUnit does not run every
 * class a file declares, and it says nothing when it drops one — no warning, no
 * non-zero exit. Two shapes were measured here: a `*Test.php` declaring two test
 * classes ran one of them, and a `*Test.php` declaring two that the file is not
 * named after ran neither. Which class it keeps is deliberately not
 * characterised, here or in any refusal below: nothing in this guard depends on
 * the answer, and a sentence claiming one would be the second copy this whole
 * group exists to prevent. Judging the file instead of the class would call a
 * file executed because one of its classes was.
 *
 * **`--list-tests` prints class names, not paths**, and the tree still carries
 * test files whose namespace does not follow their path, which `composer
 * dump-autoload -o` skips outright. Deriving a path from a class name, or
 * loading a class by the name its path implies, would therefore fail exactly on
 * the files most likely to be wrong. The mapping runs the other way: each file
 * on disk is parsed for what it declares, and that name is looked for in the
 * listing.
 *
 * The refusals, one per way a case can go missing:
 *
 * 1. a class the tree declares and PHPUnit would run, that no configured suite
 *    lists at all;
 * 2. a class a suite lists that no file in this corpus declares — the two
 *    definitions of "the test tree", `phpunit.xml.dist`'s directories and the
 *    PSR-4 dev roots, reconciled in the direction the first one cannot see;
 * 3. a case the runner's arguments remove that {@see SILENTLY_EXCLUDED} does
 *    not name;
 * 4. a name in {@see SILENTLY_EXCLUDED} that the runner no longer removes;
 * 5. a suite PHPUnit knows about that the runner does not shard, or the reverse.
 *
 * Refusals 3–5 are why `SuppressionSnapshotFreshnessTest` and
 * `ModularArchitectureGovernanceIntegrationTest` are visible at all: they sit in
 * listed directories, carry `#[Test]`, are named `itXxx` and have correct
 * namespaces — and still do not run under `composer check`. Nothing else in the
 * tree sees that. They also make `--exclude-group=benchmark` a measured fact:
 * it removes nothing today, and the day it removes something, refusal 3 names
 * what.
 *
 * Refusal 1 is judged against the listing taken *without* the runner's
 * arguments, so a class whose every case carries an excluded group — which is
 * true of `SuppressionSnapshotFreshnessTest` today — is reachable, not orphaned.
 * What happens to its cases afterwards is refusals 3–4's subject, and folding
 * the two questions together would have needed a silent exemption inside
 * refusal 1.
 */
final class TestFilesAreExecutedTest extends TestCase
{
    private const AGGREGATE = 'scripts/phpunit-aggregate.py';

    private const CONFIGURATION = 'phpunit.xml.dist';

    /**
     * Every case `composer check` does not run although the suite reaches it.
     *
     * Named one by one, because the number is the point: a third silently
     * excluded case is a decision, and it has to be made in this list rather
     * than in an attribute nobody reads.
     */
    private const SILENTLY_EXCLUDED = [
        'Qualimetrix\Tests\Analysis\Policy\Architecture\Integration\ModularArchitectureGovernanceIntegrationTest'
            . '::itChecksEveryGeneratedProjectionWithoutWriting',
        'Qualimetrix\Tests\Reporting\Formatter\Suppressed\Integration\SuppressionSnapshotFreshnessTest'
            . '::itMatchesAFreshSelfAnalysisOfSrc',
    ];

    /** @var array<string, list<string>> */
    private static array $listings = [];

    /** @var array{phpunit: string, commands: array<string, list<string>>}|null */
    private static ?array $aggregate = null;

    private static ?string $cacheRoot = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$cacheRoot !== null) {
            self::removeTree(self::$cacheRoot);
            self::$cacheRoot = null;
        }

        self::$listings = [];
        self::$aggregate = null;
    }

    #[Test]
    public function itExecutesEveryTestClassTheTreeDeclares(): void
    {
        $declarations = [];
        foreach (TestTree::testFiles() as $path) {
            $declared = TestTree::declarationsIn($path);
            $declarations[$path] = [
                'classes' => $declared['classes'],
                'executable' => $declared['executableClasses'],
            ];
        }

        $unreachable = self::unreachableIn($declarations, self::classesIn(self::reachableIds()));

        self::assertSame([], $unreachable, \sprintf(
            "%d test class(es) exist in the tree and no configured suite runs them:\n%s",
            \count($unreachable),
            implode("\n", $unreachable),
        ));
    }

    #[Test]
    public function itFindsNoExecutedClassOutsideTheCorpusItJudges(): void
    {
        $declared = [];
        foreach (TestTree::testFiles() as $path) {
            foreach (TestTree::declarationsIn($path)['classes'] as $class) {
                $declared[$class] = true;
            }
        }

        $unjudged = array_values(array_diff(self::classesIn(self::reachableIds()), array_keys($declared)));

        self::assertSame([], $unjudged, \sprintf(
            "%d class(es) run under a configured suite and lie outside the corpus the hygiene guards judge.\n"
            . "phpunit.xml.dist reaches a file that is not a *Test.php under a PSR-4 dev root, so nothing here\n"
            . "checks its methods or its namespace. Move it under a dev root, or stop running it:\n%s",
            \count($unjudged),
            implode("\n", $unjudged),
        ));
    }

    #[Test]
    public function itNamesEveryCaseTheRunnerExcludesFromCheck(): void
    {
        $undeclared = self::undeclaredExclusions(self::excludedIds(), self::SILENTLY_EXCLUDED);

        self::assertSame([], $undeclared, \sprintf(
            "%d case(s) are reachable by a suite but excluded from `composer check` without being named.\n"
            . "Either drop the group from the method, or add the case to SILENTLY_EXCLUDED with a reason:\n%s",
            \count($undeclared),
            implode("\n", $undeclared),
        ));
    }

    #[Test]
    public function itCarriesNoStaleSilentExclusionDeclaration(): void
    {
        $stale = self::staleExclusions(self::excludedIds(), self::SILENTLY_EXCLUDED);

        self::assertSame([], $stale, \sprintf(
            "%d name(s) in SILENTLY_EXCLUDED are not excluded by %s any more.\n"
            . "A declaration that describes nothing hides the next one that would: remove these:\n%s",
            \count($stale),
            self::AGGREGATE,
            implode("\n", $stale),
        ));
    }

    /**
     * A suite the configuration declares is a suite the runner shards.
     *
     * This is the one question PHPUnit cannot be asked. The runner proves its
     * shards partition the aggregate by comparing test identifiers, and
     * `--list-suites` omits a suite that holds no test, so a `<testsuite>`
     * declared with nothing in it contributes no identifier to either and is
     * invisible to both. The only place it exists is the configuration file,
     * which is therefore read for its suite names — the names alone, never for
     * which files a `<directory>` entry claims.
     *
     * An empty suite is not harmless: it is how a suite gets declared ahead of
     * the directories it will hold, and the day those directories arrive they
     * run under a shard nobody added.
     */
    #[Test]
    public function itShardsEverySuiteTheConfigurationDeclares(): void
    {
        $shardedInOrder = self::suites();
        sort($shardedInOrder);

        self::assertSame(
            self::declaredSuites(),
            $shardedInOrder,
            \sprintf(
                "phpunit.xml.dist and %s disagree about which suites exist.\n"
                . 'A suite the runner does not shard is never run by `composer check`, '
                . 'and a suite it shards that the configuration does not declare refuses the run.',
                self::AGGREGATE,
            ),
        );
    }

    /**
     * Proves each refusal refuses at all, on input it is handed rather than on
     * the tree: a scan that read nothing would report nothing and stay green.
     */
    #[Test]
    public function itRefusesEachWayOnTheSetsItIsGiven(): void
    {
        self::assertSame(
            ['elsewhere/OrphanTest.php declares Acme\OrphanTest, which no configured suite lists,'
                . ' and nothing in elsewhere is listed.'
                . ' Register the directory in phpunit.xml.dist and in the matching branch of currentSuite()'
                . ' in scripts/generate-modular-architecture-test-inventory.php'],
            self::unreachableIn(
                [
                    'acme/RunsTest.php' => ['classes' => ['Acme\RunsTest'], 'executable' => ['Acme\RunsTest']],
                    'elsewhere/OrphanTest.php' => [
                        'classes' => ['Acme\OrphanTest'],
                        'executable' => ['Acme\OrphanTest'],
                    ],
                ],
                ['Acme\RunsTest'],
            ),
        );

        self::assertSame(
            ['acme/RunsTest.php declares Acme\SecondTest, which no configured suite lists,'
                . ' although Acme\RunsTest in the same file is listed.'
                . ' PHPUnit does not run every class a file declares:'
                . ' give this class a file of its own'],
            self::unreachableIn(
                ['acme/RunsTest.php' => [
                    'classes' => ['Acme\RunsTest', 'Acme\SecondTest'],
                    'executable' => ['Acme\RunsTest', 'Acme\SecondTest'],
                ]],
                ['Acme\RunsTest'],
            ),
        );

        // No class this file declares is listed, and a sibling in the same
        // directory is: the directory is reached, so registering it is not the
        // cure. Measured, not inferred from how PHPUnit picks a class.
        self::assertSame(
            ['acme/NamedElsewhereTest.php declares Acme\AlphaTest, which no configured suite lists,'
                . ' and no class this file declares is listed although other files in acme are.'
                . ' The directory is reached, so registering it is not the cure:'
                . ' PHPUnit does not run every class a file declares'],
            self::unreachableIn(
                [
                    'acme/RunsTest.php' => ['classes' => ['Acme\RunsTest'], 'executable' => ['Acme\RunsTest']],
                    'acme/NamedElsewhereTest.php' => [
                        'classes' => ['Acme\AlphaTest'],
                        'executable' => ['Acme\AlphaTest'],
                    ],
                ],
                ['Acme\RunsTest'],
            ),
        );

        self::assertSame(
            ['acme/EmptyTest.php declares Acme\EmptyTest, and PHPUnit would run no case in this file.'
                . ' Its directory is not the suspect: give the file a case, or delete it'],
            self::unreachableIn(
                ['acme/EmptyTest.php' => ['classes' => ['Acme\EmptyTest'], 'executable' => []]],
                ['Acme\RunsTest'],
            ),
        );

        self::assertSame(
            ['acme/NothingTest.php declares no class at all'],
            self::unreachableIn(
                ['acme/NothingTest.php' => ['classes' => [], 'executable' => []]],
                ['Acme\RunsTest'],
            ),
        );

        self::assertSame(
            ['Acme\ThirdTest::itIsSilentlyDropped'],
            self::undeclaredExclusions(['Acme\KnownTest::itIsKnown', 'Acme\ThirdTest::itIsSilentlyDropped'], ['Acme\KnownTest::itIsKnown']),
        );
        self::assertSame([], self::undeclaredExclusions(['Acme\KnownTest::itIsKnown'], ['Acme\KnownTest::itIsKnown']));

        self::assertSame(
            ['Acme\GoneTest::itNoLongerCarriesTheGroup'],
            self::staleExclusions(['Acme\KnownTest::itIsKnown'], ['Acme\KnownTest::itIsKnown', 'Acme\GoneTest::itNoLongerCarriesTheGroup']),
        );
        self::assertSame([], self::staleExclusions(['Acme\KnownTest::itIsKnown'], ['Acme\KnownTest::itIsKnown']));

        // Both data-set spellings PHPUnit prints, including a named one that
        // carries the numeric marker inside its own label.
        self::assertSame('Acme\Test::itRuns', self::withoutDataSet('Acme\Test::itRuns#3'));
        self::assertSame('Acme\Test::itRuns', self::withoutDataSet('Acme\Test::itRuns"case #1"'));
        self::assertSame('Acme\Test::itRuns', self::withoutDataSet('Acme\Test::itRuns'));
    }

    /**
     * Names what the guard actually read, as a floor rather than a ceiling: a
     * listing that silently came back short would otherwise make every other
     * refusal in this class vacuously green.
     */
    #[Test]
    public function itAsksPhpunitAboutEverySuiteTheRunnerShards(): void
    {
        $suites = self::suites();

        self::assertContains('Unit', $suites);
        self::assertContains('Governance', $suites);
        self::assertGreaterThanOrEqual(5, \count($suites));

        foreach ($suites as $suite) {
            self::assertNotEmpty(self::listing(self::reachableCommand($suite)), $suite);
        }

        self::assertGreaterThan(500, \count(TestTree::testFiles()));
        self::assertGreaterThan(600, \count(self::classesIn(self::reachableIds())));
        self::assertGreaterThan(5000, \count(self::reachableIds()));
    }

    /**
     * @param array<string, array{classes: list<string>, executable: list<string>}> $declarations
     * @param list<string> $reachable classes some suite lists
     *
     * @return list<string>
     */
    private static function unreachableIn(array $declarations, array $reachable): array
    {
        // Whether a directory is reached at all is a measurement over the same
        // two sets, and it is what separates "nobody registered this directory"
        // from "the directory runs and this file's classes do not".
        $reachedDirectories = [];
        foreach ($declarations as $path => $declared) {
            if (array_intersect($declared['classes'], $reachable) !== []) {
                $reachedDirectories[\dirname($path)] = true;
            }
        }

        $unreachable = [];
        foreach ($declarations as $path => $declared) {
            if ($declared['classes'] === []) {
                $unreachable[] = $path . ' declares no class at all';

                continue;
            }

            if ($declared['executable'] === []) {
                $unreachable[] = \sprintf(
                    '%s declares %s, and PHPUnit would run no case in this file.'
                    . ' Its directory is not the suspect: give the file a case, or delete it',
                    $path,
                    implode(', ', $declared['classes']),
                );

                continue;
            }

            $directory = \dirname($path);
            $listed = array_intersect($declared['classes'], $reachable);
            foreach ($declared['executable'] as $class) {
                if (\in_array($class, $reachable, true)) {
                    continue;
                }

                if ($listed !== []) {
                    $unreachable[] = \sprintf(
                        '%s declares %s, which no configured suite lists, although %s in the same file is listed.'
                        . ' PHPUnit does not run every class a file declares:'
                        . ' give this class a file of its own',
                        $path,
                        $class,
                        implode(', ', $listed),
                    );

                    continue;
                }

                $unreachable[] = isset($reachedDirectories[$directory])
                    ? \sprintf(
                        '%s declares %s, which no configured suite lists,'
                        . ' and no class this file declares is listed although other files in %s are.'
                        . ' The directory is reached, so registering it is not the cure:'
                        . ' PHPUnit does not run every class a file declares',
                        $path,
                        $class,
                        $directory,
                    )
                    : \sprintf(
                        '%s declares %s, which no configured suite lists, and nothing in %s is listed.'
                        . ' Register the directory in phpunit.xml.dist and in the matching branch of currentSuite()'
                        . ' in scripts/generate-modular-architecture-test-inventory.php',
                        $path,
                        $class,
                        $directory,
                    );
            }
        }

        return $unreachable;
    }

    /**
     * @param list<string> $excluded
     * @param list<string> $declared
     *
     * @return list<string>
     */
    private static function undeclaredExclusions(array $excluded, array $declared): array
    {
        return array_values(array_diff($excluded, $declared));
    }

    /**
     * @param list<string> $excluded
     * @param list<string> $declared
     *
     * @return list<string>
     */
    private static function staleExclusions(array $excluded, array $declared): array
    {
        return array_values(array_diff($declared, $excluded));
    }

    /** @return list<string> every `Class::method` the configured suites reach, the runner's arguments ignored */
    private static function reachableIds(): array
    {
        return self::idsAcrossSuites(self::reachableCommand(...));
    }

    /** @return list<string> every `Class::method` the runner's own arguments remove */
    private static function excludedIds(): array
    {
        return array_values(array_diff(self::reachableIds(), self::idsAcrossSuites(self::executedCommand(...))));
    }

    /**
     * @param callable(string): list<string> $commandFor
     *
     * @return list<string> sorted, deduplicated `Class::method` identifiers
     */
    private static function idsAcrossSuites(callable $commandFor): array
    {
        $ids = [];
        foreach (self::suites() as $suite) {
            foreach (self::listing($commandFor($suite)) as $identifier) {
                $ids[self::withoutDataSet($identifier)] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * A data set widens one method into many identifiers, and the method is the
     * unit a group is declared on. PHPUnit spells a numbered set `#0` and a
     * named one `"label"`, and a label may itself contain `#`, so the cut is at
     * whichever marker comes first.
     */
    private static function withoutDataSet(string $identifier): string
    {
        $positions = [];
        foreach (['#', '"'] as $marker) {
            $position = strpos($identifier, $marker);
            if ($position !== false) {
                $positions[] = $position;
            }
        }

        return $positions === [] ? $identifier : substr($identifier, 0, min($positions));
    }

    /**
     * @param list<string> $identifiers
     *
     * @return list<string> sorted, deduplicated class names
     */
    private static function classesIn(array $identifiers): array
    {
        $classes = [];
        foreach ($identifiers as $identifier) {
            $position = strpos($identifier, '::');
            if ($position === false) {
                throw new LogicException('PHPUnit listed an identifier without a method: ' . $identifier);
            }

            $classes[substr($identifier, 0, $position)] = true;
        }

        $classes = array_keys($classes);
        sort($classes);

        return $classes;
    }

    /** @return list<string> the suites the runner shards, in its own order */
    private static function suites(): array
    {
        return array_keys(self::aggregate()['commands']);
    }

    /** @return list<string> the suite names phpunit.xml.dist declares, sorted */
    private static function declaredSuites(): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string(TestTree::read(self::CONFIGURATION));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw new LogicException(self::CONFIGURATION . ' is not readable XML');
        }

        $names = [];
        foreach ($document->testsuites->testsuite as $suite) {
            $name = (string) $suite['name'];
            if ($name === '') {
                throw new LogicException(self::CONFIGURATION . ' declares a testsuite without a name');
            }

            $names[] = $name;
        }

        if ($names === []) {
            throw new LogicException(self::CONFIGURATION . ' declares no testsuite');
        }

        sort($names);

        return $names;
    }

    /**
     * What a suite could run: the configuration and nothing else.
     *
     * @return list<string>
     */
    private static function reachableCommand(string $suite): array
    {
        return [self::aggregate()['phpunit'], '--list-tests', '--testsuite=' . $suite];
    }

    /**
     * What `composer check` does run, as the runner itself prints it.
     *
     * @return list<string>
     */
    private static function executedCommand(string $suite): array
    {
        $commands = self::aggregate()['commands'];
        if (!isset($commands[$suite])) {
            throw new LogicException(self::AGGREGATE . ' prints no command for suite ' . $suite);
        }

        return [...$commands[$suite], '--list-tests'];
    }

    /**
     * The runner's own per-suite commands, asked of the runner.
     *
     * @return array{phpunit: string, commands: array<string, list<string>>}
     */
    private static function aggregate(): array
    {
        if (self::$aggregate !== null) {
            return self::$aggregate;
        }

        $output = self::runCommand(
            ['python3', self::AGGREGATE, '--print-commands', '--cache-root=' . self::cacheRoot()],
            self::AGGREGATE . ' --print-commands',
        );

        try {
            $printed = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LogicException(self::AGGREGATE . ' did not print readable JSON', 0, $exception);
        }

        if (!\is_array($printed) || !\is_string($printed['phpunit'] ?? null) || !\is_array($printed['commands'] ?? null)) {
            throw new LogicException(self::AGGREGATE . ' printed no phpunit path and command map');
        }

        $commands = [];
        foreach ($printed['commands'] as $suite => $command) {
            if (!\is_string($suite) || !\is_array($command) || $command === []) {
                throw new LogicException(self::AGGREGATE . ' printed an unreadable command entry');
            }

            $arguments = [];
            foreach ($command as $argument) {
                if (!\is_string($argument)) {
                    throw new LogicException(self::AGGREGATE . ' printed a non-string argument for ' . $suite);
                }

                $arguments[] = $argument;
            }

            $commands[$suite] = $arguments;
        }

        if ($commands === []) {
            throw new LogicException(self::AGGREGATE . ' printed no suite command');
        }

        return self::$aggregate = ['phpunit' => $printed['phpunit'], 'commands' => $commands];
    }

    /**
     * A scratch cache root the printed commands point at. PHPUnit creates the
     * per-suite directories under it; this class removes the lot afterwards.
     */
    private static function cacheRoot(): string
    {
        if (self::$cacheRoot !== null) {
            return self::$cacheRoot;
        }

        $path = sys_get_temp_dir() . '/qmx-suite-hygiene-' . bin2hex(random_bytes(6));
        if (!mkdir($path, 0o777, true) && !is_dir($path)) {
            throw new LogicException('Cannot create a scratch cache root at ' . $path);
        }

        return self::$cacheRoot = $path;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($walk as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }

    /**
     * @param list<string> $command
     *
     * @return list<string> the identifiers PHPUnit lists for one command
     */
    private static function listing(array $command): array
    {
        $key = implode(' ', $command);

        return self::$listings[$key] ??= self::parseListing(self::runCommand($command, $key), $key);
    }

    /**
     * Mirrors the runner's own refusals: a command that exited non-zero or wrote
     * to stderr is not a measurement.
     *
     * @param list<string> $command
     */
    private static function runCommand(array $command, string $label): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, TestTree::projectRoot());
        if ($process === false) {
            throw new LogicException('Cannot start ' . $label);
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($stdout === false || $stderr === false) {
            throw new LogicException($label . ' produced no readable output');
        }
        if ($status !== 0) {
            throw new LogicException(\sprintf('%s exited %d', $label, $status));
        }
        if ($stderr !== '') {
            throw new LogicException($label . ' wrote to stderr');
        }

        return $stdout;
    }

    /** @return list<string> */
    private static function parseListing(string $output, string $label): array
    {
        $lines = explode("\n", rtrim($output, "\n"));
        // PHPUnit spells the header in the singular when it lists one test or
        // none, so requiring the plural would refuse a narrow listing with the
        // wrong sentence — and an empty one is the case worth naming exactly.
        $headers = [];
        foreach ($lines as $index => $line) {
            if ($line === 'Available tests:' || $line === 'Available test:') {
                $headers[] = $index;
            }
        }

        if (\count($headers) !== 1) {
            throw new LogicException($label . ': expected exactly one "Available tests:" header');
        }

        $identifiers = [];
        foreach (\array_slice($lines, $headers[0] + 1) as $line) {
            if (!str_starts_with($line, ' - ')) {
                throw new LogicException($label . ': unexpected line after the header');
            }

            $identifier = substr($line, 3);
            if ($identifier === '') {
                throw new LogicException($label . ': empty test identifier');
            }

            $identifiers[] = $identifier;
        }

        if ($identifiers === []) {
            throw new LogicException($label . ': no test identifiers');
        }

        return $identifiers;
    }
}
