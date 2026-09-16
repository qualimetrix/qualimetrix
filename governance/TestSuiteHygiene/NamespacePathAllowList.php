<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use LogicException;
use Throwable;

/**
 * The namespace-versus-path violations this tree is known to carry.
 *
 * Closing them is a migration, not a side effect of moving files around: the
 * 60 offending test files have nothing in common with the files any later stage
 * relocates. So the check ships first and the list ships with it, and emptying
 * the list is its own piece of work.
 *
 * **The list is measured, never typed.** It is written by
 * `php governance/TestSuiteHygiene/derive-namespace-path-allow-list.php`, and a
 * row nobody measured would be a claim about the tree that nothing checks. That
 * command is a write and not a check, so it never exits 0 — {@see WROTE} when
 * it wrote, {@see MEASUREMENT_FAILED} when the scan it would have written from
 * failed and the tracked file was left alone.
 *
 * **It is a tracked file of its own, not a constant inside the guard.** A green
 * check with a silent exemption for 60 files would be a lie; a list in the diff
 * is an admission with a size.
 */
final class NamespacePathAllowList
{
    public const PATH = 'governance/TestSuiteHygiene/namespace-path-allow-list.php';

    /** A derive run wrote the tracked list; re-run the guard to be judged against it. */
    public const WROTE = 4;

    /** The scan the list would have been measured from failed, so nothing was written. */
    public const MEASUREMENT_FAILED = 5;

    /**
     * @return array<string, string> project-relative path => the namespace the
     *                               file declares today
     */
    public static function load(): array
    {
        $allowed = require TestTree::absolute(self::PATH);
        if (!\is_array($allowed)) {
            throw new LogicException(self::PATH . ' does not return an array');
        }

        $rows = [];
        foreach ($allowed as $path => $namespace) {
            if (!\is_string($path) || !\is_string($namespace)) {
                throw new LogicException(self::PATH . ' carries a row that is not path => namespace');
            }

            $rows[$path] = $namespace;
        }

        return $rows;
    }

    /**
     * Every `*Test.php` whose declared namespace disagrees with its path today.
     *
     * @return array<string, string> project-relative path => declared namespace
     */
    public static function measure(): array
    {
        $declarations = [];
        foreach (TestTree::testFiles() as $path) {
            $declarations[$path] = TestTree::declarationsIn($path)['namespaces'];
        }

        return self::violationsIn(TestTree::autoloadDevRoots(), $declarations);
    }

    /**
     * @param array<string, string> $roots PSR-4 prefix => project-relative directory
     * @param array<string, list<string>> $declarations project-relative path => the namespaces it opens
     *
     * @return array<string, string> path => declared namespace, mismatches only
     */
    public static function violationsIn(array $roots, array $declarations): array
    {
        $violations = [];
        foreach ($declarations as $path => $namespaces) {
            $expected = self::expectedNamespace($roots, $path);
            if ($expected === null) {
                throw new LogicException($path . ' lies under no PSR-4 dev root');
            }

            $declared = self::declaredNamespace($namespaces);
            if ($declared !== $expected) {
                $violations[$path] = $declared;
            }
        }

        ksort($violations);

        return $violations;
    }

    /**
     * The namespace PSR-4 requires of a file, or null when no root claims it.
     *
     * The longest matching root wins, so a root nested inside another is read
     * the way the autoloader reads it rather than the way the map is ordered.
     *
     * @param array<string, string> $roots PSR-4 prefix => project-relative directory
     */
    public static function expectedNamespace(array $roots, string $relativePath): ?string
    {
        $bestRoot = null;
        $bestPrefix = null;
        foreach ($roots as $prefix => $root) {
            if (!str_starts_with($relativePath, $root . '/')) {
                continue;
            }
            if ($bestRoot === null || \strlen($root) > \strlen($bestRoot)) {
                $bestRoot = $root;
                $bestPrefix = $prefix;
            }
        }

        if ($bestRoot === null || $bestPrefix === null) {
            return null;
        }

        $directory = \dirname(substr($relativePath, \strlen($bestRoot) + 1));

        return $directory === '.' ? $bestPrefix : $bestPrefix . '\\' . str_replace('/', '\\', $directory);
    }

    /**
     * A file's namespace as one comparable string.
     *
     * A file that opens none is the global namespace, which PSR-4 cannot place
     * either; a file that opens several has no single answer, and saying so is
     * more use than picking one.
     *
     * @param list<string> $namespaces
     */
    public static function declaredNamespace(array $namespaces): string
    {
        return match (\count($namespaces)) {
            0 => '(global namespace)',
            1 => $namespaces[0],
            default => implode(' + ', $namespaces),
        };
    }

    /** @param array<string, string> $violations */
    public static function render(array $violations): string
    {
        $rows = '';
        foreach ($violations as $path => $namespace) {
            $rows .= \sprintf("    %s => %s,\n", var_export($path, true), var_export($namespace, true));
        }

        // The day the list is empty is the day this whole file can go; until
        // then the rendered form has to be one PER-CS accepts unedited.
        $body = $rows === '' ? '[];' : "[\n" . $rows . '];';

        return <<<PHP
            <?php

            declare(strict_types=1);

            /*
             * The namespace-versus-path violations this tree is known to carry, measured by
             * `php governance/TestSuiteHygiene/derive-namespace-path-allow-list.php`.
             *
             * Do not add a row by hand: a row nobody measured is a claim about the tree that
             * nothing checks, and TestNamespacesFollowTheirPathTest refuses a row that no
             * longer describes a violation exactly as loudly as it refuses one that is missing.
             * The way out is to empty the list, one renamed namespace at a time.
             */

            return {$body}

            PHP;
    }

    /**
     * Writes the tracked list from a fresh scan. A write, not a check: it never
     * returns 0, and it writes nothing when the scan it measures from fails.
     */
    public static function derive(): int
    {
        try {
            $violations = self::measure();
        } catch (Throwable $error) {
            fwrite(\STDERR, 'The scan this list would be measured from failed, so nothing was written: '
                . $error->getMessage() . "\n");

            return self::MEASUREMENT_FAILED;
        }

        if (file_put_contents(TestTree::absolute(self::PATH), self::render($violations)) === false) {
            fwrite(\STDERR, 'Cannot write ' . self::PATH . "\n");

            return self::MEASUREMENT_FAILED;
        }

        echo 'Measured ' . \count($violations) . ' violation(s) into ' . self::PATH . ".\n";
        echo "This was a write, not a check: run the Governance suite to be judged against it.\n";

        return self::WROTE;
    }
}
