<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use LogicException;
use Throwable;

/**
 * The three ways this tree's test paths are known not to name their subject.
 *
 * Every row is a file whose path is a legal owner and level — parts 1 and 2 hold
 * for the whole population and have no exception list at all — and whose part-3
 * claim cannot be checked or does not hold:
 *
 * - **no coverage attribute**: the file declares no `#[CoversClass]`, so there
 *   is no subject to compare the path against. The rows distinguish the ones
 *   that declare `#[CoversNothing]`, a deliberate statement, from the ones that
 *   declare nothing at all, an omission someone could fix — so a row can be
 *   retired for the right reason rather than for either.
 * - **covers another owner**: every class the file covers belongs to a
 *   different owner. This is the adapter-exclusion principle showing through,
 *   and it is a real signal rather than noise.
 * - **remainder is not a prefix**: the path owner is among the covered owners
 *   and the path below the level is still not a prefix of the subject's
 *   namespace. Every current member truncates in the *middle* — `Unit/Collection/`
 *   against an actual `Contract/Collection` — which a reader scanning the tree
 *   does not notice and a prefix test does.
 *
 * **The lists are measured, never typed.** They are written by
 * `php governance/TestSuiteHygiene/derive-subject-path-exceptions.php`, and a
 * row nobody measured would be a claim about the tree that nothing checks. That
 * command is a write and not a check, so it never exits 0.
 *
 * **Deriving may shrink a list and rewrite its rows, and nothing else.** Each
 * list carries its own ceiling; a measurement above it exits
 * {@see ABOVE_CEILING} and writes nothing, so the cure for a fresh exception
 * cannot be to re-run the command. Raising a ceiling is one hand-edited number
 * in the diff, which is the admission this design wants.
 *
 * **The hole this keeps, named rather than left to be discovered:** retiring one
 * exception and introducing another in the same list leaves the count where it
 * was, and every row here is keyed on a path, so the derive cannot tell that
 * substitution from a file having moved. {@see NamespacePathAllowList} closes
 * the same hole by budgeting on the *value*, which it can because a namespace
 * survives a move and a path does not. Both refusals in
 * {@see TestPathsNameTheirSubjectTest} still fire on the substitution until
 * someone re-derives; what is not closed is the re-derive itself.
 */
final class SubjectPathExceptions
{
    public const PATH = 'governance/TestSuiteHygiene/subject-path-exceptions.php';

    /** A derive run wrote the tracked lists; re-run the guard to be judged against them. */
    public const WROTE = 4;

    /** The scan the lists would have been measured from failed, so nothing was written. */
    public const MEASUREMENT_FAILED = 5;

    /** A list measured more members than its tracked ceiling admits; nothing was written. */
    public const ABOVE_CEILING = 6;

    /** The tree carries a path that is not a legal owner and level at all; nothing was written. */
    public const UNJUDGEABLE_PATH = 7;

    /** The tracked file itself could not be read, so there was nothing to derive against. */
    public const UNREADABLE_LIST = 8;

    /**
     * The tracked lists, in the order they are rendered, and the verdict each
     * one holds. The key is what a refusal names, so it reads as a sentence
     * about the file rather than as a bucket letter.
     *
     * @var array<string, string>
     */
    public const LISTS = [
        'declares_no_coverage' => TestSubjectPaths::NO_COVERAGE,
        'covers_another_owner' => TestSubjectPaths::ANOTHER_OWNER,
        'remainder_is_not_a_prefix' => TestSubjectPaths::NOT_A_PREFIX,
    ];

    /**
     * The verdicts that are a defect in the tree rather than an exception in a
     * list: nothing may carry them, and the derive refuses to write while
     * anything does.
     *
     * @var list<string>
     */
    public const UNJUDGEABLE = [TestSubjectPaths::UNKNOWN_OWNER, TestSubjectPaths::LEVEL];

    /**
     * @return array<string, array{ceiling: int, rows: array<string, string>}>
     */
    public static function load(): array
    {
        $tracked = self::tracked();

        $lists = [];
        foreach (array_keys(self::LISTS) as $name) {
            $list = $tracked[$name] ?? null;
            if (!\is_array($list) || !\is_int($list['ceiling'] ?? null) || !\is_array($list['rows'] ?? null)) {
                throw new LogicException(self::PATH . ' carries no int ceiling and array of rows for ' . $name);
            }

            $rows = [];
            foreach ($list['rows'] as $path => $detail) {
                if (!\is_string($path) || !\is_string($detail)) {
                    throw new LogicException(self::PATH . ' carries a row of ' . $name . ' that is not path => detail');
                }

                $rows[$path] = $detail;
            }

            $lists[$name] = ['ceiling' => $list['ceiling'], 'rows' => $rows];
        }

        return $lists;
    }

    /**
     * The three lists as the tree measures them today.
     *
     * @return array<string, array<string, string>> list name => path => detail
     */
    public static function measure(): array
    {
        return self::split(TestSubjectPaths::measure());
    }

    /**
     * @param array<string, array{verdict: string, detail: string}> $judged
     *
     * @return array<string, array<string, string>> list name => path => detail
     */
    public static function split(array $judged): array
    {
        $lists = [];
        foreach (self::LISTS as $name => $verdict) {
            $lists[$name] = TestSubjectPaths::carrying($judged, $verdict);
        }

        return $lists;
    }

    /**
     * Paths the rule cannot judge at all, which is a defect and not an exception.
     *
     * @param array<string, array{verdict: string, detail: string}> $judged
     *
     * @return array<string, string> path => detail
     */
    public static function unjudgeable(array $judged): array
    {
        $unjudgeable = [];
        foreach (self::UNJUDGEABLE as $verdict) {
            foreach (TestSubjectPaths::carrying($judged, $verdict) as $path => $detail) {
                $unjudgeable[$path] = $detail;
            }
        }

        ksort($unjudgeable);

        return $unjudgeable;
    }

    /** @return array<mixed, mixed> */
    private static function tracked(): array
    {
        $lists = require TestTree::absolute(self::PATH);
        if (!\is_array($lists)) {
            throw new LogicException(self::PATH . ' does not return an array of lists');
        }

        return $lists;
    }

    /**
     * Writes the tracked lists from a fresh scan. A write, not a check: it never
     * returns 0, and it writes nothing when the scan fails, when the tree
     * carries a path no list can hold, or when any one list measures more
     * members than its tracked ceiling admits.
     */
    public static function derive(): int
    {
        // Read outside the measurement's try: a tracked file in the wrong shape
        // is the one failure the reader fixes in one move, and reporting it as
        // "the scan failed" would send them to look at the tree instead.
        try {
            $tracked = self::load();
        } catch (Throwable $error) {
            fwrite(\STDERR, 'Cannot read ' . self::PATH . ', so nothing was written: '
                . $error->getMessage() . "\n");

            return self::UNREADABLE_LIST;
        }

        try {
            $judged = TestSubjectPaths::measure();
        } catch (Throwable $error) {
            fwrite(\STDERR, 'The scan these lists would be measured from failed, so nothing was written: '
                . $error->getMessage() . "\n");

            return self::MEASUREMENT_FAILED;
        }

        $unjudgeable = self::unjudgeable($judged);
        if ($unjudgeable !== []) {
            fwrite(\STDERR, \sprintf(
                "%d test file(s) carry a path that names no owner and level, so nothing was written.\n"
                . "No list here can hold one: an exception is a path whose subject disagrees with it, and these\n"
                . "have no subject to disagree with. Move the file, or fix the segment:\n%s\n",
                \count($unjudgeable),
                self::describe($unjudgeable),
            ));

            return self::UNJUDGEABLE_PATH;
        }

        $measured = self::split($judged);

        $lists = [];
        foreach (self::LISTS as $name => $verdict) {
            $ceiling = $tracked[$name]['ceiling'];
            $rows = $measured[$name];
            if (\count($rows) > $ceiling) {
                fwrite(\STDERR, \sprintf(
                    "The tree carries %d file(s) that belong on %s and %s admits %d, so nothing was written.\n"
                    . "This command cannot absorb a new exception: file the test under the subject it covers, or\n"
                    . "raise that ceiling by hand and say in the commit why the tree is allowed to get worse.\n%s\n",
                    \count($rows),
                    $name,
                    self::PATH,
                    $ceiling,
                    self::describe($rows),
                ));

                return self::ABOVE_CEILING;
            }

            $lists[$name] = ['ceiling' => min($ceiling, \count($rows)), 'rows' => $rows];
        }

        if (!self::write(self::render($lists))) {
            return self::MEASUREMENT_FAILED;
        }

        foreach ($lists as $name => $list) {
            echo 'Measured ' . \count($list['rows']) . ' file(s) into ' . $name . ", ceiling " . $list['ceiling'] . ".\n";
        }

        echo 'Written to ' . self::PATH . ".\n";
        echo "This was a write, not a check: run the Governance suite to be judged against it.\n";

        return self::WROTE;
    }

    /**
     * @param array<string, string> $rows
     */
    public static function describe(array $rows): string
    {
        $lines = [];
        foreach ($rows as $path => $detail) {
            $lines[] = $path . ' ' . $detail;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, array{ceiling: int, rows: array<string, string>}> $lists
     */
    public static function render(array $lists): string
    {
        $body = '';
        foreach ($lists as $name => $list) {
            $rows = '';
            foreach ($list['rows'] as $path => $detail) {
                $rows .= \sprintf("            %s => %s,\n", var_export($path, true), var_export($detail, true));
            }

            $body .= \sprintf(
                "    %s => [\n        'ceiling' => %d,\n        'rows' => %s,\n    ],\n",
                var_export($name, true),
                $list['ceiling'],
                $rows === '' ? '[]' : "[\n" . $rows . '        ]',
            );
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            /*
             * The test paths that do not name the subject they cover, measured by
             * `php governance/TestSuiteHygiene/derive-subject-path-exceptions.php`.
             *
             * Do not add a row by hand: a row nobody measured is a claim about the tree that
             * nothing checks, and TestPathsNameTheirSubjectTest refuses a row that no longer
             * describes an exception exactly as loudly as it refuses one that is missing.
             * The way out is to empty a list, one refiled or re-covered test at a time.
             *
             * Each `ceiling` is how many rows its list may carry. Deriving only ever lowers
             * one, so a fresh exception cannot be absorbed by re-running the command; raising
             * one is a hand-edited number, which is what makes it a decision rather than a
             * side effect.
             */

            return [
            {$body}];

            PHP;
    }

    /**
     * A tracked file is replaced whole or not at all: a partial write leaves a
     * truncated list that the guard would read as the measured truth.
     */
    private static function write(string $contents): bool
    {
        $target = TestTree::absolute(self::PATH);
        $temporary = $target . '.tmp.' . getmypid();

        $written = file_put_contents($temporary, $contents);
        if ($written !== \strlen($contents)) {
            @unlink($temporary);
            fwrite(\STDERR, 'Cannot write ' . self::PATH . "\n");

            return false;
        }

        if (!rename($temporary, $target)) {
            @unlink($temporary);
            fwrite(\STDERR, 'Cannot replace ' . self::PATH . "\n");

            return false;
        }

        return true;
    }
}
