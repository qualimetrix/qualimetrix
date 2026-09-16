<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every test file the tree carries is one the suite actually runs.
 *
 * `phpunit.xml.dist` enumerates its test directories by hand, so a directory
 * created without being registered holds files that exist, compile, read as
 * tests and are never executed. This repository has already paid for that
 * defect once: 110 tests sat unexecuted for three runs under a green
 * `composer check`. Every later stage of the test-structure plan creates
 * directories, and this is what makes a forgotten registration loud.
 *
 * **The executed set comes from PHPUnit's own `--list-tests`, never from a
 * reimplementation of its matching rules.** A guard that re-derives which files
 * a `<directory>` entry claims is a second copy of PHPUnit's discovery, and a
 * second copy is the thing being guarded against.
 *
 * **`--list-tests` prints class names, not paths**, and the tree still carries
 * 60 `*Test.php` files whose namespace does not follow their path — part of the
 * 146 files, declaring 147 classes, that `composer dump-autoload -o` skips
 * outright, the rest being analyser fixtures. Deriving a path from a class name,
 * or loading a class by the name its path implies, would therefore fail exactly
 * on the files most likely to be wrong. The mapping runs the other way: each
 * file on disk is parsed for what it declares, and that name is looked for in
 * the listing.
 *
 * **The suites and the exclusions are read out of `scripts/phpunit-aggregate.py`.**
 * That file is what `composer check` actually runs; a guard that declared its
 * own suite list would be a sixth copy of a map this repository already keeps
 * five copies of.
 *
 * Four refusals, one per way a case can go missing:
 *
 * 1. a `*Test.php` on disk that no configured suite reaches at all;
 * 2. an `--exclude-group` the aggregate passes that this guard does not account
 *    for — a new exclusion silently shrinks `composer check`;
 * 3. a case removed by one of those exclusions that {@see SILENTLY_EXCLUDED}
 *    does not name;
 * 4. a name in {@see SILENTLY_EXCLUDED} that no exclusion removes any more.
 *
 * Refusals 2–4 are the plan's fourth axis: `SuppressionSnapshotFreshnessTest`
 * and `ModularArchitectureGovernanceIntegrationTest` sit in listed directories,
 * carry `#[Test]`, are named `itXxx` and have correct namespaces — and still do
 * not run under `composer check`. Nothing else in the tree sees that.
 * `--exclude-group=benchmark` is the standing counter-example: the aggregate
 * passes it and no method carries the group, so it removes nothing, and
 * refusal 3 is what keeps that a measured fact rather than a claim.
 *
 * Refusal 1 is judged against the listing taken *without* the exclusions, so a
 * file whose every case carries an excluded group — which is true of
 * `SuppressionSnapshotFreshnessTest` today — is reachable, not orphaned. What
 * happens to its cases afterwards is refusals 2–4's subject, and folding the
 * two questions together would have needed a silent exemption inside refusal 1.
 */
final class TestFilesAreExecutedTest extends TestCase
{
    private const AGGREGATE = 'scripts/phpunit-aggregate.py';

    /**
     * The groups the aggregate excludes, and therefore the only exclusions this
     * guard knows how to account for.
     */
    private const DECLARED_EXCLUDED_GROUPS = ['benchmark', 'live-freshness'];

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

    #[Test]
    public function itExecutesEveryTestFileTheTreeCarries(): void
    {
        $declarations = [];
        foreach (TestTree::testFiles() as $path) {
            $declarations[$path] = TestTree::declarationsIn($path)['classes'];
        }

        $orphans = self::orphansIn($declarations, self::classesIn(self::reachableIds()));

        self::assertSame([], $orphans, \sprintf(
            "%d test file(s) exist but no configured suite runs them.\n"
            . "Register the directory in phpunit.xml.dist and in the matching branch of currentSuite()\n"
            . "in scripts/generate-modular-architecture-test-inventory.php:\n%s",
            \count($orphans),
            implode("\n", $orphans),
        ));
    }

    #[Test]
    public function itAccountsForEveryGroupTheAggregateExcludes(): void
    {
        self::assertSame(
            self::DECLARED_EXCLUDED_GROUPS,
            self::aggregate()['excludedGroups'],
            self::AGGREGATE . " excludes a different set of groups than this guard accounts for.\n"
            . 'An exclusion added there removes cases from `composer check`; name it here and record what it removes.',
        );
    }

    #[Test]
    public function itNamesEveryCaseTheAggregateExcludesFromCheck(): void
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
            "%d name(s) in SILENTLY_EXCLUDED are not excluded by the aggregate any more.\n"
            . "A declaration that describes nothing hides the next one that would: remove these:\n%s",
            \count($stale),
            implode("\n", $stale),
        ));
    }

    /**
     * Proves each refusal refuses at all, on input it is handed rather than on
     * the tree: a scan that read nothing would report nothing and stay green.
     */
    #[Test]
    public function itRefusesEachWayOnTheSetsItIsGiven(): void
    {
        self::assertSame(['acme/OrphanTest.php declares Acme\OrphanTest'], self::orphansIn(
            ['acme/RunsTest.php' => ['Acme\RunsTest'], 'acme/OrphanTest.php' => ['Acme\OrphanTest']],
            ['Acme\RunsTest'],
        ));
        self::assertSame(['acme/EmptyTest.php declares no class at all'], self::orphansIn(
            ['acme/EmptyTest.php' => []],
            ['Acme\RunsTest'],
        ));

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

        self::assertSame(
            ['live-freshness', 'slow'],
            self::excludedGroupsIn("COMMON_ARGUMENTS = (\n    \"--no-coverage\",\n"
                . "    \"--exclude-group=live-freshness\",\n    \"--exclude-group=slow\",\n)\n"),
        );
    }

    /**
     * Names what the guard actually read, as a floor rather than a ceiling: a
     * listing that silently came back short would otherwise make every other
     * refusal in this class vacuously green.
     */
    #[Test]
    public function itAsksPhpunitAboutEverySuiteTheAggregateRuns(): void
    {
        $suites = self::aggregate()['suites'];

        self::assertContains('Unit', $suites);
        self::assertContains('Governance', $suites);
        self::assertGreaterThanOrEqual(5, \count($suites));

        foreach ($suites as $suite) {
            self::assertNotEmpty(self::listing(self::aggregate()['reachableArguments'], $suite), $suite);
        }

        self::assertGreaterThan(500, \count(TestTree::testFiles()));
        self::assertGreaterThan(600, \count(self::classesIn(self::reachableIds())));
        self::assertGreaterThan(5000, \count(self::reachableIds()));
    }

    /**
     * @param array<string, list<string>> $declarations file => the class-likes it declares
     * @param list<string> $reachable classes some suite lists
     *
     * @return list<string>
     */
    private static function orphansIn(array $declarations, array $reachable): array
    {
        $orphans = [];
        foreach ($declarations as $path => $declared) {
            if (array_intersect($declared, $reachable) === []) {
                $orphans[] = $path . ' declares ' . (
                    $declared === [] ? 'no class at all' : implode(', ', $declared)
                );
            }
        }

        return $orphans;
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

    /** @return list<string> every `Class::method` the configured suites reach, exclusions ignored */
    private static function reachableIds(): array
    {
        return self::idsAcrossSuites(self::aggregate()['reachableArguments']);
    }

    /** @return list<string> every `Class::method` the aggregate's exclusions remove */
    private static function excludedIds(): array
    {
        return array_values(array_diff(self::reachableIds(), self::idsAcrossSuites(self::aggregate()['executedArguments'])));
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string> sorted, deduplicated `Class::method` identifiers
     */
    private static function idsAcrossSuites(array $arguments): array
    {
        $ids = [];
        foreach (self::aggregate()['suites'] as $suite) {
            foreach (self::listing($arguments, $suite) as $identifier) {
                // A data set widens one method into many identifiers; the
                // method is the unit a group is declared on, so the suffix is
                // dropped rather than carried into every comparison.
                $position = strpos($identifier, '#');
                $ids[$position === false ? $identifier : substr($identifier, 0, $position)] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
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

    /**
     * What `composer check` runs, read from the runner that runs it.
     *
     * @return array{suites: list<string>, excludedGroups: list<string>, reachableArguments: list<string>, executedArguments: list<string>}
     */
    private static function aggregate(): array
    {
        $source = TestTree::read(self::AGGREGATE);
        $arguments = self::tupleIn($source, 'COMMON_ARGUMENTS');

        return [
            'suites' => self::tupleIn($source, 'SUITES'),
            'excludedGroups' => self::excludedGroupsIn($source),
            'reachableArguments' => array_values(array_filter(
                $arguments,
                static fn(string $argument): bool => !str_starts_with($argument, '--exclude-group='),
            )),
            'executedArguments' => $arguments,
        ];
    }

    /** @return list<string> */
    private static function excludedGroupsIn(string $source): array
    {
        $groups = [];
        foreach (self::tupleIn($source, 'COMMON_ARGUMENTS') as $argument) {
            if (str_starts_with($argument, '--exclude-group=')) {
                $groups[] = substr($argument, \strlen('--exclude-group='));
            }
        }

        sort($groups);

        return $groups;
    }

    /**
     * Reads one module-level tuple of string literals out of the Python runner.
     *
     * A tuple this guard cannot parse stops it, rather than leaving it with an
     * empty suite list that would make every refusal here silently vacuous.
     *
     * @return list<string>
     */
    private static function tupleIn(string $source, string $name): array
    {
        if (preg_match('/^' . preg_quote($name, '/') . ' = \((.*?)\)$/ms', $source, $matches) !== 1) {
            throw new LogicException(self::AGGREGATE . ' declares no readable ' . $name . ' tuple');
        }

        if (preg_match_all('/"([^"]*)"/', $matches[1], $entries) < 1) {
            throw new LogicException(self::AGGREGATE . ' declares an empty ' . $name . ' tuple');
        }

        return $entries[1];
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string> the identifiers PHPUnit lists for one suite
     */
    private static function listing(array $arguments, string $suite): array
    {
        $command = [\PHP_BINARY, 'vendor/bin/phpunit', '--list-tests', ...$arguments, '--testsuite=' . $suite];
        $key = implode(' ', $command);

        return self::$listings[$key] ??= self::parseListing(self::runListing($command), $key);
    }

    /**
     * Mirrors the aggregate's own refusals: a listing that exited non-zero, wrote
     * to stderr, or does not have the documented shape is not a measurement.
     *
     * @param list<string> $command
     */
    private static function runListing(array $command): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, TestTree::projectRoot());
        if ($process === false) {
            throw new LogicException('Cannot start ' . implode(' ', $command));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($stdout === false || $stderr === false) {
            throw new LogicException(implode(' ', $command) . ' produced no readable output');
        }
        if ($status !== 0) {
            throw new LogicException(\sprintf('%s exited %d', implode(' ', $command), $status));
        }
        if ($stderr !== '') {
            throw new LogicException(implode(' ', $command) . ' wrote to stderr');
        }

        return $stdout;
    }

    /** @return list<string> */
    private static function parseListing(string $output, string $label): array
    {
        $lines = explode("\n", rtrim($output, "\n"));
        $headers = array_keys($lines, 'Available tests:', true);
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
