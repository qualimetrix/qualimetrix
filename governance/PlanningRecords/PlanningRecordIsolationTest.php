<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\PlanningRecords;

use FilesystemIterator;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;
use SplFileInfo;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * The plan index (`docs/internal/plans/README.md`) must list every active
 * plan directory exactly once, and sources must reach no planning record: a
 * plan path, a plan-local filename, or a package-chronology marker inside a
 * comment.
 *
 * The population is what `git ls-files` reports, less documentation (`docs/`,
 * `website/`, where the plans themselves live) and vendored trees. It is
 * derived rather than listed because the list this control used to carry went
 * stale in two directions at once, and both were silent. Five roots were named
 * -- `bin governance scripts src tests` -- so `finding-gate/`,
 * `promise-effect/`, `input-doors/`, `tools/`, `directive-audit/`, `.github/`,
 * `benchmarks/`, `.claude/` and every file at the repository root went unread:
 * 274 files, of which 251 carried one of the nine extensions the filter also
 * accepted. That filter made one of the five named roots inert on its own --
 * `bin` matched nothing at all, because `bin/qmx` carries no extension -- and
 * it is why widening the root list alone would have fixed nothing: `.githooks/`
 * would have stayed unreadable for the same reason.
 *
 * **Comments are read for a minority of the population, by design.** Syntax
 * comes from the extension, and failing that from the shebang; the roughly
 * 230 files that offer neither -- `json`, `md`, `tsv`, `yaml`, `Dockerfile`,
 * `LICENSE` -- are still read for plan paths, and only their comments go
 * unexamined. YAML is the consequential one: `qmx.yaml` carries 16 chronology
 * markers and `finding-gate/cases/health/qmx.yaml` one more, and reading
 * `#` comments there would make all seventeen violations. Leaving them is the
 * owner's decision about that file's content, recorded here so the next reader
 * sees a decision rather than one more silent gap.
 *
 * `git ls-files` is read here rather than shared with
 * `RegisteredDirectoriesReachTrackedFilesTest`, whose copy is private to its
 * own class: governance groups are flat and do not import one another.
 */
final class PlanningRecordIsolationTest extends TestCase
{
    private const PLAN_LOCAL_REFERENCE_PATTERN = '~(?<![A-Za-z0-9_.-])(?:PLAN\.md|03-cure\.md|01-freeze-kind\.md|followups/c2\.md)\b~';

    private const PACKAGE_CHRONOLOGY_PATTERN = '~(?<![A-Za-z0-9_,])[XP\x{0420}\x{0425}\x{0428}]\d+[A-Za-z\x{0410}-\x{044F}0-9.-]*(?![A-Za-z0-9_])~u';

    private static string $projectRoot;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = \dirname(__DIR__, 2);
    }

    #[Test]
    public function itIndexesEveryActivePlanDirectory(): void
    {
        $plansRoot = self::$projectRoot . '/docs/internal/plans';
        $directories = [];

        foreach (new FilesystemIterator($plansRoot, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir()) {
                $directories[] = $entry->getBasename();
            }
        }

        sort($directories);
        $index = $this->readFile('docs/internal/plans/README.md');
        preg_match_all('~\]\(([^/)]+)/~', $index, $matches);
        $indexed = array_values(array_unique($matches[1]));
        sort($indexed);

        self::assertSame($directories, $indexed, 'The plan index must list every active plan directory exactly once.');
    }

    #[Test]
    public function itKeepsExecutableSourcesIndependentFromPlanningRecords(): void
    {
        $dependencies = [];
        $unexaminable = [];
        $patterns = [
            'concrete plan path' => '~docs/internal/plans/(?!README\.md\b)[A-Za-z0-9._/-]+~',
            'plan-local filename' => self::PLAN_LOCAL_REFERENCE_PATTERN,
        ];

        foreach (self::judgedFiles() as $relativePath) {
            $content = file_get_contents(self::$projectRoot . '/' . $relativePath);
            \assert($content !== false);

            foreach ($patterns as $kind => $pattern) {
                preg_match_all($pattern, $content, $matches, \PREG_OFFSET_CAPTURE);

                foreach ($matches[0] as [$match, $offset]) {
                    $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                    $dependencies[] = "{$relativePath}:{$line}: {$kind}: {$match}";
                }
            }

            $syntax = self::commentSyntax($relativePath, $content);
            if ($syntax === null) {
                continue;
            }

            // The chronology pattern carries `u`, so invalid UTF-8 makes
            // preg_match_all return false with an empty match set and no
            // warning -- a file that was never examined, counted as clean.
            // Collected rather than asserted here: asserting inside the loop
            // would abandon the dependency list this control exists to print.
            if (preg_match_all(self::PACKAGE_CHRONOLOGY_PATTERN, self::commentContent($syntax, $content), $matches) === false) {
                $unexaminable[] = "{$relativePath}: " . preg_last_error_msg();

                continue;
            }

            foreach ($matches[0] as $match) {
                $dependencies[] = "{$relativePath}: package chronology in comment: {$match}";
            }
        }

        // Both lists are reported by one assertion. Asserted separately, the
        // first failure hides the second, and a file that could not be read
        // would cost the run the list of violations it did find.
        $report = [];
        if ($dependencies !== []) {
            $report[] = "Executable sources depend on planning records or package chronology:\n"
                . implode("\n", array_values(array_unique($dependencies)));
        }
        if ($unexaminable !== []) {
            $report[] = "Comments could not be examined at all, so these files were judged against nothing:\n"
                . implode("\n", $unexaminable);
        }

        self::assertSame([], $report, implode("\n\n", $report));
    }

    #[Test]
    public function itRecognizesPlanningChronologyOnlyInsideComments(): void
    {
        self::assertSame('', self::commentContent('slash', 'const X12 = 1;'));
        self::assertSame('', self::commentContent('slash', 'const marker = "// P5 is data";'));
        self::assertMatchesRegularExpression(
            self::PACKAGE_CHRONOLOGY_PATTERN,
            self::commentContent('slash', '// P5 was the implementation package.'),
        );
        self::assertMatchesRegularExpression(
            self::PACKAGE_CHRONOLOGY_PATTERN,
            self::commentContent('hash', "# \u{0420}5 was the implementation package."),
        );
        self::assertMatchesRegularExpression(
            self::PACKAGE_CHRONOLOGY_PATTERN,
            self::commentContent('hash', 'value = 1 # P5 was the implementation package.'),
        );
        self::assertMatchesRegularExpression(self::PLAN_LOCAL_REFERENCE_PATTERN, 'See 01-freeze-kind.md.');
    }

    /**
     * The spellings that used to be unreachable, and the ones still refused.
     *
     * `bin/qmx` and `.githooks/*` are the tree's extensionless executables; an
     * extension filter could never reach either, which is why widening the root
     * list alone would have fixed nothing. The interpreter is the last path
     * segment matched whole, because `#!/opt/php-tools/bin/bash` read as PHP
     * would hand a shell script to the PHP tokenizer and get no comment back --
     * examined in appearance only.
     */
    #[Test]
    public function itReadsCommentSyntaxFromAShebangWhenTheExtensionCannot(): void
    {
        self::assertSame('php', self::commentSyntax('bin/qmx', "#!/usr/bin/env php\n<?php\n"));
        self::assertSame('hash', self::commentSyntax('.githooks/pre-commit', "#!/bin/bash\nset -e\n"));
        self::assertSame('hash', self::commentSyntax('.githooks/commit-msg', "#!/usr/bin/env sh\n"));
        self::assertSame('hash', self::commentSyntax('hook', "#!/bin/sh -eu\n"));
        self::assertSame('hash', self::commentSyntax('hook', "#!/usr/bin/env -S bash -euo pipefail\n"));
        self::assertSame('hash', self::commentSyntax('hook', "#!/usr/bin/python3\n"));
        self::assertSame('slash', self::commentSyntax('hook', "#!/usr/bin/env node\n"));

        // The interpreter is the last segment, not any substring of the line.
        self::assertSame('hash', self::commentSyntax('hook', "#!/opt/php-tools/bin/bash\n"));

        // An unrecognised extension must not suppress the shebang.
        self::assertSame('hash', self::commentSyntax('tool.unknown', "#!/bin/bash\n"));

        // Extension wins where it speaks.
        self::assertSame('hash', self::commentSyntax('scripts/thing.sh', "set -e\n"));

        // Neither extension nor shebang: read for plan paths, not for comments.
        self::assertNull(self::commentSyntax('Dockerfile', "FROM php:8.4-cli\n"));
        self::assertNull(self::commentSyntax('LICENSE', "# PolyForm Shield License\n"));
        self::assertNull(self::commentSyntax('qmx.yaml', "layers: []\n"));
        self::assertNull(self::commentSyntax('hook', "#!/usr/bin/env awk -f\n"));
    }

    /**
     * The population is the whole index minus the exclusions, and nothing else.
     *
     * Stated as the rule rather than as witnesses, because witnesses trade one
     * blind spot for another. The first draft asserted "some judged file has no
     * extension", which `Dockerfile` and `LICENSE` satisfy -- exactly the files
     * whose comments are never read. The second asserted "some judged file is
     * classified by its shebang", which three files satisfy, so dropping
     * `finding-gate/` or every file at the repository root stayed green.
     *
     * The subtracted set is spelled out here rather than read from
     * `isExcluded()`. Taken from there it would grow with the filter, and the
     * equality would hold for any new exclusion -- the tautology this
     * repository has already paid for once in `ChannelRenameMapTest`.
     */
    #[Test]
    public function itJudgesTheWholeIndexMinusTheDeclaredExclusions(): void
    {
        $self = substr(
            (string) realpath(__DIR__ . '/PlanningRecordIsolationTest.php'),
            \strlen(self::$projectRoot) + 1,
        );

        $expected = [];
        foreach (self::trackedPaths() as $path) {
            if ($path === $self) {
                continue;
            }
            if (str_starts_with($path, 'docs/') || str_starts_with($path, 'website/')) {
                continue;
            }

            $directories = explode('/', $path);
            array_pop($directories);
            if (array_intersect($directories, ['node_modules', 'vendor', 'dist']) !== []) {
                continue;
            }
            if (!is_file(self::$projectRoot . '/' . $path)) {
                continue;
            }

            $expected[] = $path;
        }

        self::assertSame(
            $expected,
            self::judgedFiles(),
            'The judged set no longer equals the index minus the exclusions named in this test. '
            . 'A new exclusion belongs in both places, or it is one nothing measures.',
        );
    }

    /**
     * The spelling that only a shebang supplies is still reached.
     *
     * Kept alongside the rule above because the two fail for different reasons:
     * the rule catches a narrowed population, this catches a classifier that
     * stops recognising the tree's extensionless executables while the
     * population stays whole.
     */
    #[Test]
    public function itClassifiesTheExtensionlessExecutablesByShebang(): void
    {
        $viaShebang = [];

        foreach (self::judgedFiles() as $relativePath) {
            if (self::extensionSyntax($relativePath) !== null) {
                continue;
            }

            $content = file_get_contents(self::$projectRoot . '/' . $relativePath);
            \assert($content !== false);

            if (self::shebangSyntax($content) !== null) {
                $viaShebang[] = $relativePath;
            }
        }

        self::assertNotEmpty(
            $viaShebang,
            'No judged file is classified by its shebang, so the branch that reaches '
            . "the tree's extensionless executables is carrying nothing.",
        );
    }

    private function readFile(string $relativePath): string
    {
        $path = self::$projectRoot . '/' . $relativePath;
        self::assertFileExists($path, "Documentation file not found: {$relativePath}");

        $content = file_get_contents($path);
        \assert($content !== false);

        return $content;
    }

    private static function commentContent(string $syntax, string $content): string
    {
        if ($syntax === 'php') {
            $comments = [];

            foreach (token_get_all($content) as $token) {
                if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                    $comments[] = $token[1];
                }
            }

            return implode("\n", $comments);
        }

        return match ($syntax) {
            'hash' => self::hashCommentsOutsideStrings($content),
            'slash' => self::javascriptCommentsOutsideStrings($content),
            default => '',
        };
    }

    /**
     * The files this control judges: what a fresh clone receives, less the
     * documentation trees and the vendored ones.
     *
     * Asked of git rather than of this disk, so a file that exists only here
     * is not judged and a tracked file cannot be missed by a walk. Two losses
     * that a walk would take silently are refused instead: an index entry
     * whose file is gone, and a listing with nothing in it.
     *
     * @return list<string> project-relative paths
     */
    private static function judgedFiles(): array
    {
        $self = substr(__FILE__, \strlen(self::$projectRoot) + 1);
        $judged = [];
        $missing = [];

        foreach (self::trackedPaths() as $path) {
            if ($path === $self || self::isExcluded($path)) {
                continue;
            }

            $absolute = self::$projectRoot . '/' . $path;
            if (!file_exists($absolute)) {
                $missing[] = $path;

                continue;
            }
            if (!is_file($absolute)) {
                continue;
            }

            $judged[] = $path;
        }

        if ($missing !== []) {
            throw new LogicException(
                'git lists files that are not on disk, so they were judged against nothing '
                . '(stage the deletion with `git rm`, or restore them): '
                . implode(', ', \array_slice($missing, 0, 5)),
            );
        }

        if ($judged === []) {
            throw new LogicException('git ls-files listed no judgeable file, so nothing here was judged against anything');
        }

        return $judged;
    }

    /**
     * Whether a tracked path is outside this control's subject.
     *
     * `docs/` and `website/` are the documentation trees, where the plans
     * themselves live. The three directory names are vendored output, matched
     * as whole segments: `str_contains('/vendor/')` misses a `vendor/` at the
     * repository root, which is exactly where it would be.
     */
    private static function isExcluded(string $path): bool
    {
        if (str_starts_with($path, 'docs/') || str_starts_with($path, 'website/')) {
            return true;
        }

        $segments = explode('/', $path);
        array_pop($segments);

        return array_intersect($segments, ['node_modules', 'vendor', 'dist']) !== [];
    }

    /**
     * Every path in the index, unfiltered.
     *
     * @return list<string> project-relative paths
     */
    private static function trackedPaths(): array
    {
        try {
            $result = ChildProcess::run(['git', 'ls-files', '-z'], self::$projectRoot);
        } catch (RuntimeException $exception) {
            throw new LogicException('Cannot start git ls-files: ' . $exception->getMessage(), 0, $exception);
        }

        // Failure is the exit code. git writes environment advice to stderr at
        // status 0, and treating that as fatal would redden this control for a
        // reason indistinguishable from a real violation.
        if ($result['exitCode'] !== 0) {
            throw new LogicException(\sprintf('git ls-files exited %d: %s', $result['exitCode'], $result['stderr']));
        }

        $paths = array_values(array_filter(
            explode("\0", $result['stdout']),
            static fn(string $path): bool => $path !== '',
        ));
        if ($paths === []) {
            throw new LogicException('git ls-files listed no file at all');
        }

        return $paths;
    }

    /**
     * How a file spells a comment, or null when nothing here can tell.
     *
     * Extension first, then the shebang, which is the only thing an
     * extensionless file offers. Returning null leaves the file read for plan
     * paths and unread for chronology, rather than silently absent from both.
     */
    private static function commentSyntax(string $relativePath, string $content): ?string
    {
        // An unrecognised extension is not evidence that there is no shebang,
        // so the interpreter is consulted either way.
        return self::extensionSyntax($relativePath) ?? self::shebangSyntax($content);
    }

    private static function extensionSyntax(string $relativePath): ?string
    {
        return match (strtolower(pathinfo($relativePath, \PATHINFO_EXTENSION))) {
            'php' => 'php',
            'py', 'sh', 'bash', 'zsh' => 'hash',
            'js', 'mjs', 'cjs', 'ts' => 'slash',
            default => null,
        };
    }

    /**
     * The comment syntax a shebang names, or null when it names none.
     *
     * The interpreter is the last path segment, matched whole. Matched as a
     * substring instead, `#!/opt/php-tools/bin/bash` reads as PHP, and the PHP
     * tokenizer returns no comment at all for a shell script -- a file that
     * looks examined and is not.
     */
    private static function shebangSyntax(string $content): ?string
    {
        $first = strtok($content, "\n");
        if ($first === false || !str_starts_with($first, '#!')) {
            return null;
        }

        $words = preg_split('~\s+~', substr($first, 2), -1, \PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return null;
        }

        foreach ($words as $word) {
            if (str_starts_with($word, '-')) {
                continue;
            }

            $interpreter = strtolower(basename($word));
            if ($interpreter === 'env') {
                continue;
            }

            $interpreter = preg_replace('~[0-9.]+$~', '', $interpreter) ?? $interpreter;

            return match ($interpreter) {
                'php' => 'php',
                'sh', 'bash', 'dash', 'zsh', 'ksh', 'csh', 'tcsh', 'fish', 'python', 'perl', 'ruby' => 'hash',
                'node' => 'slash',
                default => null,
            };
        }

        return null;
    }

    private static function hashCommentsOutsideStrings(string $content): string
    {
        $comments = [];
        $length = \strlen($content);
        $quote = null;
        $triple = false;

        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $content[$offset];

            if ($quote !== null) {
                if ($character === '\\') {
                    ++$offset;
                    continue;
                }

                if ($triple && substr($content, $offset, 3) === str_repeat($quote, 3)) {
                    $offset += 2;
                    $quote = null;
                    $triple = false;
                } elseif (!$triple && $character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if (($character === "'" || $character === '"')) {
                $quote = $character;
                $triple = substr($content, $offset, 3) === str_repeat($character, 3);
                if ($triple) {
                    $offset += 2;
                }
                continue;
            }

            if ($character === '#') {
                $end = strpos($content, "\n", $offset);
                $end = $end === false ? $length : $end;
                $comments[] = substr($content, $offset, $end - $offset);
                $offset = $end;
            }
        }

        return implode("\n", $comments);
    }

    private static function javascriptCommentsOutsideStrings(string $content): string
    {
        $comments = [];
        $length = \strlen($content);
        $quote = null;

        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $content[$offset];

            if ($quote !== null) {
                if ($character === '\\') {
                    ++$offset;
                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                continue;
            }

            $pair = substr($content, $offset, 2);
            if ($pair === '//') {
                $end = strpos($content, "\n", $offset);
                $end = $end === false ? $length : $end;
                $comments[] = substr($content, $offset, $end - $offset);
                $offset = $end;
            } elseif ($pair === '/*') {
                $end = strpos($content, '*/', $offset + 2);
                $end = $end === false ? $length - 2 : $end;
                $comments[] = substr($content, $offset, $end + 2 - $offset);
                $offset = $end + 1;
            }
        }

        return implode("\n", $comments);
    }
}
