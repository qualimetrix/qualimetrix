<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use DOMDocument;
use DOMElement;
use DOMXPath;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every test-root registration names a path a fresh clone will actually have.
 *
 * Git stores no empty directory. A directory emptied by a move therefore
 * survives only in the working copy that emptied it: there, every registration
 * naming it still resolves, and `composer check` is green. On any other clone
 * the path is simply absent, and PHPUnit answers an absent `<directory>` by
 * exiting 2 without executing a single test — not one, from any suite. So the
 * branch that looks most thoroughly validated is the one that runs no tests at
 * all. This repository has already paid for that shape once: `tests/Reporting/
 * Functional` lost both its files and kept its registration, and three green
 * `composer check` runs on the emptying branch were green for exactly that
 * reason.
 *
 * **Tracked, not present.** `is_dir()` answers about this machine and would
 * have called that registration healthy — it was a real directory here, all
 * three times. What decides whether a clone sees the path is whether git
 * carries a file under it, so that is what is asked, and it is asked of git
 * rather than of the filesystem.
 *
 * **Two doors, and both are shut.** Registering a test root means two edits
 * that must agree: a `<directory>` under a `<testsuite>` in `phpunit.xml.dist`,
 * and a row in `testSuitePrefixTable()` in
 * `scripts/generate-modular-architecture-test-inventory.php`. That generator
 * already reconciles the two registrations against *each other*, in both
 * directions — so a registration left behind is normally left behind on both
 * sides, and neither side notices, because agreeing about a path neither can
 * reach is still agreeing. This guard asks the question that reconciliation
 * cannot: does the thing they agree on exist for anyone but this worktree.
 *
 * **The prefix table is read, not modelled.** Its literals are lifted out of
 * the one function that declares them, and {@see
 * itReadsBothRegistrationsWholeAndTheIndexTheyAreJudgedAgainst} refuses a
 * reading narrower than the registrations it is supposed to cover — a regex
 * that silently matched half the rows would otherwise excuse half the table.
 */
final class RegisteredDirectoriesReachTrackedFilesTest extends TestCase
{
    private const CONFIGURATION = 'phpunit.xml.dist';

    private const INVENTORY_SCRIPT = 'scripts/generate-modular-architecture-test-inventory.php';

    private const PREFIX_TABLE = 'testSuitePrefixTable';

    /** @var list<string>|null */
    private static ?array $tracked = null;

    #[Test]
    public function itFindsATrackedFileUnderEverySuiteDirectory(): void
    {
        $unreachable = [];

        foreach (self::registeredDirectories() as ['suite' => $suite, 'directory' => $directory]) {
            if (self::tracksAFileUnder($directory)) {
                continue;
            }

            $unreachable[] = \sprintf(
                '%s is declared under <testsuite name="%s"> and git tracks no file under it%s',
                $directory,
                $suite,
                is_dir(TestTree::absolute($directory)) ? ' (it exists here, untracked, and nowhere else)' : '',
            );
        }

        self::assertSame([], $unreachable, \sprintf(
            "%d <directory> entr%s in %s name%s a path git tracks no file under. Git stores no empty directory, so a"
            . " fresh clone has no such path, and PHPUnit answers an absent <directory> by exiting 2 without"
            . " executing a single test — the whole suite, every root, nothing run and nothing reported. Drop the"
            . " registration, or commit the files it was registered for:\n%s",
            \count($unreachable),
            \count($unreachable) === 1 ? 'y' : 'ies',
            self::CONFIGURATION,
            \count($unreachable) === 1 ? 's' : '',
            implode("\n", $unreachable),
        ));
    }

    #[Test]
    public function itFindsATrackedFileUnderEverySuitePrefixLiteral(): void
    {
        $unreachable = [];

        foreach (self::prefixTableRows() as ['suite' => $suite, 'prefix' => $prefix]) {
            if (self::tracksAFileUnder($prefix)) {
                continue;
            }

            $unreachable[] = \sprintf('%s is classified as suite %s and git tracks no file under it', $prefix, $suite);
        }

        self::assertSame([], $unreachable, \sprintf(
            "%d row%s of %s() in %s classif%s a path git tracks no file under. Such a row classifies nothing on a"
            . " fresh clone: no inventory entry can ever match it, so it is invisible to every count derived from"
            . " the inventory — while the <directory> it must agree with, by the generator's own reconciliation,"
            . " exits PHPUnit with 2 there. Drop the row together with that <directory>, or commit the files both"
            . " were registered for:\n%s",
            \count($unreachable),
            \count($unreachable) === 1 ? '' : 's',
            self::PREFIX_TABLE,
            self::INVENTORY_SCRIPT,
            \count($unreachable) === 1 ? 'ies' : 'y',
            implode("\n", $unreachable),
        ));
    }

    /**
     * The denominator, made loud.
     *
     * Each of the three inputs can come back empty or short without any error:
     * an XPath that matches nothing, a regex that matches nothing, a `git
     * ls-files` narrowed by a future argument. A guard fed any of those accuses
     * nobody and passes forever.
     *
     * The last assertion is the one that cannot be satisfied by a floor: every
     * registered directory has to be covered by some literal this reading
     * produced. That is already true of the tree whenever the generator is
     * green — it is what `currentSuite()` has to be able to do for each
     * `<directory>` — so it never refuses a healthy tree, and it refuses any
     * reading of the table that is narrower than the table.
     */
    #[Test]
    public function itReadsBothRegistrationsWholeAndTheIndexTheyAreJudgedAgainst(): void
    {
        $directories = self::registeredDirectories();
        $rows = self::prefixTableRows();

        self::assertGreaterThan(50, \count($directories), 'Too few <directory> entries read to be the whole file.');
        self::assertGreaterThan(50, \count($rows), 'Too few prefix rows read to be the whole table.');
        self::assertGreaterThan(500, \count(self::trackedFiles()), 'Too few tracked files to be this repository.');

        $uncovered = [];

        foreach ($directories as ['suite' => $suite, 'directory' => $directory]) {
            foreach ($rows as $row) {
                if ($row['suite'] === $suite && str_starts_with($directory . '/', $row['prefix'])) {
                    continue 2;
                }
            }

            $uncovered[] = \sprintf('%s (suite %s)', $directory, $suite);
        }

        self::assertSame([], $uncovered, \sprintf(
            "This reading of %s() covers no literal for %d registered director%s, so it is narrower than the table"
            . " it read and would excuse whatever it failed to read:\n%s",
            self::PREFIX_TABLE,
            \count($uncovered),
            \count($uncovered) === 1 ? 'y' : 'ies',
            implode("\n", $uncovered),
        ));
    }

    /**
     * Every `<directory>` declared under a `<testsuite>`, and nothing else.
     *
     * The `<source>` block declares directories too, and they are analysed
     * rather than executed — an XPath naming the parents is how they stay out.
     *
     * @return list<array{suite: string, directory: string}>
     */
    private static function registeredDirectories(): array
    {
        $document = new DOMDocument();
        if (!$document->loadXML(TestTree::read(self::CONFIGURATION))) {
            throw new LogicException(self::CONFIGURATION . ' is not readable XML');
        }

        $nodes = (new DOMXPath($document))->query('/phpunit/testsuites/testsuite/directory');
        if ($nodes === false) {
            throw new LogicException('Cannot read the suite directories of ' . self::CONFIGURATION);
        }

        $directories = [];
        foreach ($nodes as $node) {
            $suite = $node->parentNode;
            if (!$node instanceof DOMElement || !$suite instanceof DOMElement) {
                throw new LogicException(self::CONFIGURATION . ' declares a <directory> outside an element');
            }

            $directories[] = [
                'suite' => $suite->getAttribute('name'),
                'directory' => rtrim(trim($node->textContent), '/'),
            ];
        }

        return $directories;
    }

    /**
     * The prefix table, lifted out of the function that declares it.
     *
     * The generator runs its work at the top level of the file, so requiring it
     * would generate artifacts instead of answering a question. Its body is
     * taken as text instead, and only that body — the file names `prefix`
     * elsewhere, and a reading that wandered outside the table would judge
     * literals the table does not carry.
     *
     * @return list<array{suite: string, prefix: string}>
     */
    private static function prefixTableRows(): array
    {
        $source = TestTree::read(self::INVENTORY_SCRIPT);
        $signature = \sprintf("function %s(): array\n{", self::PREFIX_TABLE);
        $start = strpos($source, $signature);
        if ($start === false) {
            throw new LogicException(\sprintf(
                '%s no longer declares %s() in a shape this guard can find, so the second registration is unjudged.',
                self::INVENTORY_SCRIPT,
                self::PREFIX_TABLE,
            ));
        }

        $end = strpos($source, "\n}\n", $start);
        if ($end === false) {
            throw new LogicException(self::INVENTORY_SCRIPT . ': ' . self::PREFIX_TABLE . '() does not end');
        }

        $body = substr($source, $start, $end - $start);
        preg_match_all("/'prefix' => '([^']+)',\\s*'suite' => '([^']+)'/", $body, $matches, \PREG_SET_ORDER);

        $rows = [];
        foreach ($matches as $match) {
            $rows[] = ['suite' => $match[2], 'prefix' => rtrim($match[1], '/') . '/'];
        }

        return $rows;
    }

    /**
     * The trailing separator is what keeps `tests/Reporting/Unit` from being
     * called reachable by a file under `tests/Reporting/UnitHelpers`.
     */
    private static function tracksAFileUnder(string $directory): bool
    {
        $prefix = rtrim($directory, '/') . '/';

        foreach (self::trackedFiles() as $file) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a fresh clone would receive, asked of git rather than of this disk.
     *
     * @return list<string> project-relative paths
     */
    private static function trackedFiles(): array
    {
        if (self::$tracked !== null) {
            return self::$tracked;
        }

        $command = ['git', 'ls-files', '-z'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, TestTree::projectRoot());
        if ($process === false) {
            throw new LogicException('Cannot start git ls-files');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($stdout === false || $stderr === false) {
            throw new LogicException('git ls-files produced no readable output');
        }
        if ($status !== 0) {
            throw new LogicException(\sprintf('git ls-files exited %d', $status));
        }
        if ($stderr !== '') {
            throw new LogicException('git ls-files wrote to stderr: ' . $stderr);
        }

        $files = array_values(array_filter(explode("\0", $stdout), static fn(string $path): bool => $path !== ''));
        if ($files === []) {
            throw new LogicException('git ls-files listed no file, so nothing here was judged against anything');
        }

        return self::$tracked = $files;
    }
}
