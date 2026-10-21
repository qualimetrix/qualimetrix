<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\PlanningRecords;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * Tracked prose must name a tracked document; every section sign and
 * declared record-id spelling must have an exact reason.
 * Product regressions cannot reach a new planning dependency in another source.
 *
 * The population is the Git index minus vendored directory segments,
 * symbolic links and this control. Documentation is included; ADRs
 * are historical for paths and section signs, but not external record ids.
 * Path tokens are prose-only: Markdown, native PHP comments, the existing
 * shell/Python/JavaScript comment extraction and leading hash lines elsewhere.
 * Chronology is confined to known comment syntax. Other ids and section signs
 * are judged in the whole content, including code and data.
 *
 * Paths resolve from their file, the repository or the configured site root;
 * relative paths and link targets use every tracked publication location.
 * Basename and suffix searches are not resolution. The site root is read with
 * the docs environment's native YAML loader; missing dependencies refuse.
 *
 * Limits: prose without a path, section sign or declared id form; split path
 * tokens, HTML hrefs, inline hash comments in data and id forms not declared
 * here. The shell/JavaScript extraction is lexical, not their full grammars.
 * Legitimate lookalike ids and section signs cost one exact, expiring exception.
 */
final class PlanningRecordIsolationTest extends TestCase
{
    /** @var list<array{name: string, pattern: string, scope: 'comments'|'content', reason: string}> */
    private const array RECORD_ID_FORMS = [
        ['name' => 'chronology', 'pattern' => '~(?<![A-Za-z0-9_,])[XP\x{0420}\x{0425}\x{0428}]\d+[A-Za-z\x{0410}-\x{044F}0-9.-]*(?![A-Za-z0-9_])~u', 'scope' => 'comments', 'reason' => 'Package chronology is not a source contract.'],
        ['name' => 'registry-letter', 'pattern' => '~(?<![\w-])[XMRHYGO]-\d{2,3}[a-z]?(?![\w-])~', 'scope' => 'content', 'reason' => 'External campaign registry rows.'],
        ['name' => 'registry-e', 'pattern' => '~(?<![\w-])(?:RM-)?E-?\d{3}(?![\w-])~', 'scope' => 'content', 'reason' => 'External campaign registry rows.'],
        ['name' => 'registry-orch', 'pattern' => '~(?<![\w-])(?:ORCH-\d{2}|N-S\d{2}[a-z]?-\d+)(?![\w-])~', 'scope' => 'content', 'reason' => 'External orchestration records.'],
        ['name' => 'reviewer', 'pattern' => '~(?<![\w-])(?:(?:S-)?(?:claude|codex|cand|rv)(?:-[a-z][a-z0-9]*)*-\d{2}[a-z]?|refute-\d{2})(?![\w-])~', 'scope' => 'content', 'reason' => 'External review records.'],
        ['name' => 'stage', 'pattern' => '~(?<![\w-])S\d{2}[a-z]?(?:[-/]P\d+)?(?!\w)~', 'scope' => 'content', 'reason' => 'Campaign stage names are not source contracts.'],
        ['name' => 'legacy-plan', 'pattern' => '~(?<![\w-])RFC-0\d\d(?![\w-])~', 'scope' => 'content', 'reason' => 'Removed internal planning records.'],
    ];

    private const string MD_PATTERN = '~(?<![A-Za-z0-9_.\-/])((?:\.{1,2}/)*/?[A-Za-z0-9_.\-/]*[A-Za-z0-9_\-]\.md)(?![A-Za-z0-9_])~';

    /** @var list<array{rule: string, file: string, line: string, token: string, reason: string}> */
    private const array REFERENCE_EXCEPTIONS = [
        ['rule' => 'md', 'file' => 'website/CONTRIBUTING_DOCS.md', 'line' => '- English: `page.md`', 'token' => 'page.md', 'reason' => 'Page-name metavariable.'],
        ['rule' => 'md', 'file' => 'website/CONTRIBUTING_DOCS.md', 'line' => '- Russian: `page.ru.md`', 'token' => 'page.ru.md', 'reason' => 'Page-name metavariable.'],
        ['rule' => 'md', 'file' => 'website/CONTRIBUTING_DOCS.md', 'line' => 'Compact list for agents. See [Default Thresholds](../reference/default-thresholds.md) for values.', 'token' => '../reference/default-thresholds.md', 'reason' => 'The example is written from a rule page, not this contributor guide.'],
        ['rule' => 'section', 'file' => 'CHANGELOG.md', 'line' => '- `@qmx-threshold` refuses a `channel:level` pair instead of retuning the whole rule. A threshold addresses the producing rule and does not distinguish levels (ADR 0024 §2); the pair is captured by the directive grammar so that it can be named and refused, where before the pattern stopped at the `:` and quietly applied the left half to every level. Set a per-level boundary with the nested configuration key or `--rule-opt RULE:level.option=value`. The same widening applies to the three `@qmx-ignore` forms, where a pair used to be truncated to the bare channel — a suppression silently broader than the one that was written.', 'token' => '§2', 'reason' => 'A released changelog entry preserves its historical citation.'],
        ['rule' => 'record-id', 'file' => 'tests/Core/Unit/SourceText/SourceBytesTest.php', 'line' => 'yield \'three-byte boundaries\' => ["\\u{800}\\u{D7FF}\\u{E000}\\u{FFFF}"];', 'token' => 'E000', 'reason' => 'The Unicode escape names a valid code-point boundary in source-byte test data.'],
    ];

    private static string $projectRoot;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = \dirname(__DIR__, 2);
    }

    #[Test]
    public function itKeepsExecutableSourcesIndependentFromPlanningRecords(): void
    {
        $rows = self::referenceRows();
        $covered = [];
        $problems = [];
        foreach (self::REFERENCE_EXCEPTIONS as $exception) {
            $matches = [];
            foreach ($rows as $i => $row) {
                if ($row['rule'] === $exception['rule'] && $row['file'] === $exception['file']
                    && $row['line_text'] === $exception['line'] && $row['token'] === $exception['token']) {
                    $matches[] = $i;
                }
            }
            if (\count($matches) !== 1) {
                $problems[] = $exception['file'] . ': stale or ambiguous exception: ' . $exception['reason'];
            } else {
                $covered[$matches[0]] = true;
            }
        }
        foreach ($rows as $i => $row) {
            if (!isset($covered[$i])) {
                $problems[] = \sprintf('%s %s:%d %s #%d: %s', $row['rule'], $row['file'], $row['line'], $row['token'], $row['occurrence'], $row['line_text']);
            }
        }
        self::assertSame([], $problems, implode("\n", $problems));
    }

    #[Test]
    public function itRecognizesPlanningChronologyOnlyInsideComments(): void
    {
        self::assertSame('', self::commentContent('slash', 'const X12 = 1;'));
        self::assertSame('', self::commentContent('slash', 'const marker = "// P5 is data";'));
        self::assertMatchesRegularExpression(
            self::RECORD_ID_FORMS[0]['pattern'],
            self::commentContent('slash', '// P5 was the implementation package.'),
        );
        self::assertMatchesRegularExpression(
            self::RECORD_ID_FORMS[0]['pattern'],
            self::commentContent('hash', "# \u{0420}5 was the implementation package."),
        );
        self::assertMatchesRegularExpression(
            self::RECORD_ID_FORMS[0]['pattern'],
            self::commentContent('hash', 'value = 1 # P5 was the implementation package.'),
        );
        self::assertMatchesRegularExpression(self::MD_PATTERN, 'See 01-freeze-kind.md.');
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
            if (is_link(self::$projectRoot . '/' . $path)) {
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

    private static function commentContent(string $syntax, string $content): string
    {
        return implode("\n", array_map(static fn(array $span): string => substr($content, $span[0], $span[1] - $span[0]), self::commentSpans($syntax, $content)));
    }

    /**
     * The files this control judges: what a fresh clone receives, less the
     * symbolic links and vendored directory segments.
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
     * The three directory names are vendored output, matched
     * as whole segments: `str_contains('/vendor/')` misses a `vendor/` at the
     * repository root, which is exactly where it would be.
     */
    private static function isExcluded(string $path): bool
    {
        if (is_link(self::$projectRoot . '/' . $path)) {
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
            // Carried whole rather than restated: only run()'s own message
            // says which of its failures this was.
            throw new LogicException('git ls-files did not complete: ' . $exception->getMessage(), 0, $exception);
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

    /** @return list<array{rule: string, file: string, line: int, token: string, line_text: string, occurrence: int}> */
    private static function referenceRows(): array
    {
        $tracked = array_fill_keys(self::trackedPaths(), true);
        $published = [];
        foreach (array_keys($tracked) as $path) {
            if (is_link(self::$projectRoot . '/' . $path)) {
                $target = readlink(self::$projectRoot . '/' . $path);
                if ($target === false) {
                    throw new LogicException('Cannot read tracked symbolic link: ' . $path);
                }
                $resolved = self::normalizePath(\dirname($path) . '/' . $target);
                if ($resolved !== null) {
                    $published[$resolved][] = $path;
                }
            }
        }
        $site = self::siteRoot();
        $rows = [];
        $seen = [];
        foreach (self::judgedFiles() as $file) {
            $content = file_get_contents(self::$projectRoot . '/' . $file);
            if ($content === false) {
                throw new LogicException('Cannot read tracked file: ' . $file);
            }
            if (str_contains(substr($content, 0, 8000), "\0")) {
                continue;
            }
            $markdown = str_ends_with($file, '.md');
            $syntax = $markdown ? null : self::commentSyntax($file, $content);
            $comments = $syntax === null ? [] : self::commentSpans($syntax, $content);
            $prose = $markdown ? [[0, \strlen($content)]] : $comments;
            if (!$markdown && $syntax === null) {
                preg_match_all('~^[ \t]*#[^\n]*~m', $content, $hashLines, \PREG_OFFSET_CAPTURE);
                foreach ($hashLines[0] as [$text, $offset]) {
                    $prose[] = [$offset, $offset + \strlen($text)];
                }
            }
            $add = static function (string $rule, string $token, int $offset) use (&$rows, &$seen, $file, $content): void {
                $line = self::lineAt($content, $offset);
                $key = serialize([$rule, $file, $line, $token]);
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                $rows[] = ['rule' => $rule, 'file' => $file, 'line' => substr_count(substr($content, 0, $offset), "\n") + 1,
                    'token' => $token, 'line_text' => $line, 'occurrence' => $seen[$key]];
            };
            if (!str_starts_with($file, 'docs/adr/')) {
                $targets = [];
                $labels = [];
                if ($markdown) {
                    preg_match_all('~\[([^\]\n]*)\]\(\s*<?([^)\s>#]+\.md)(?:#[^)\s]*)?>?\s*(?:"[^"]*")?\)|^\s*\[[^\]]+\]:\s*<?([^\s>#]+\.md)~m', $content, $links, \PREG_OFFSET_CAPTURE);
                    foreach ($links[1] as [$text, $offset]) {
                        if ($offset >= 0) {
                            $labels[] = [$offset, $offset + \strlen($text)];
                        }
                    }
                    foreach ([2, 3] as $group) {
                        foreach ($links[$group] as [$text, $offset]) {
                            if ($offset >= 0 && $text !== '') {
                                $targets[$offset] = true;
                            }
                        }
                    }
                }
                preg_match_all('~(?:https?|ftp)://\S+~', $content, $urls, \PREG_OFFSET_CAPTURE);
                $urlSpans = array_map(static fn(array $hit): array => [$hit[1], $hit[1] + \strlen($hit[0])], $urls[0]);
                preg_match_all(self::MD_PATTERN, $content, $paths, \PREG_OFFSET_CAPTURE);
                foreach ($paths[1] as [$token, $offset]) {
                    if (!self::inSpans($offset, $prose) || self::inSpans($offset, $urlSpans)
                        || str_starts_with(basename($token), '.')
                        || ($markdown && !isset($targets[$offset]) && self::inSpans($offset, $labels))) {
                        continue;
                    }
                    if (!self::referenceResolves($file, $token, isset($targets[$offset]), $tracked, $published, $site)) {
                        $add('md', $token, $offset);
                    }
                }
                preg_match_all('~§~', $content, $sections, \PREG_OFFSET_CAPTURE);
                foreach ($sections[0] as [, $offset]) {
                    preg_match('~^§\s?(?:"[^"\n]{1,60}"|[A-Za-z]?\d+(?:\.\d+)*[a-z]?|[A-Z][A-Za-z]+(?:\s[A-Z][A-Za-z]+)*)?~u', mb_scrub(substr($content, $offset, 80), 'UTF-8'), $item);
                    $add('section', $item[0] ?? '§', $offset);
                }
                preg_match_all('~docs/internal/plans/[A-Za-z0-9._/-]*~', $content, $concrete, \PREG_OFFSET_CAPTURE);
                foreach ($concrete[0] as [$token, $offset]) {
                    if (!self::inSpans($offset, $prose)) {
                        $add('plan-path', $token, $offset);
                    }
                }
            }
            foreach (self::RECORD_ID_FORMS as $form) {
                $spans = $form['scope'] === 'comments' ? $comments : [[0, \strlen($content)]];
                foreach ($spans as [$start, $end]) {
                    if (preg_match_all($form['pattern'], substr($content, $start, $end - $start), $ids, \PREG_OFFSET_CAPTURE) === false) {
                        throw new LogicException($file . ': comments cannot be examined: ' . preg_last_error_msg());
                    }
                    foreach ($ids[0] as [$token, $offset]) {
                        $add('record-id', $token, $start + $offset);
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<string, bool> $tracked
     * @param array<string, list<string>> $published
     */
    private static function referenceResolves(string $file, string $token, bool $linkTarget, array $tracked, array $published, string $site): bool
    {
        $candidates = [];
        if (str_starts_with($token, '/')) {
            $candidates[] = self::normalizePath($token);
        } elseif (str_starts_with($token, './') || str_starts_with($token, '../') || $linkTarget) {
            foreach ([$file, ...($published[$file] ?? [])] as $location) {
                $candidates[] = self::normalizePath(\dirname($location) . '/' . $token);
            }
        } else {
            $candidates = [self::normalizePath(\dirname($file) . '/' . $token), self::normalizePath($token), self::normalizePath($site . '/' . $token)];
        }
        foreach ($candidates as $candidate) {
            if ($candidate !== null && isset($tracked[$candidate])) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePath(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    private static function siteRoot(): string
    {
        $candidates = [];
        $configured = getenv('QMX_MKDOCS');
        if ($configured !== false && str_contains($configured, '/')) {
            $directory = \dirname($configured);
            if (!str_starts_with($directory, '/')) {
                $directory = self::$projectRoot . '/' . $directory;
            }
            $candidates = [$directory . '/python', $directory . '/python3'];
        }
        $candidates[] = self::$projectRoot . '/website/.venv/bin/python';
        $candidates[] = self::$projectRoot . '/website/.venv/bin/python3';
        $searchPath = getenv('PATH');
        foreach (explode(\PATH_SEPARATOR, $searchPath === false ? '' : $searchPath) as $directory) {
            $candidates[] = $directory . '/python3';
        }
        $python = array_find($candidates, static fn(string $path): bool => is_file($path) && is_executable($path));
        if ($python === null) {
            throw new LogicException('Reading the site root requires the docs dependencies: install website/requirements.txt in the docs Python environment.');
        }
        $script = <<<'PYTHON'
import json, signal, sys
signal.alarm(10)
try:
    import yaml
    with open(sys.argv[1], encoding="utf-8") as stream:
        document = yaml.compose(stream, Loader=yaml.BaseLoader)
    if not isinstance(document, yaml.MappingNode):
        raise ValueError("the site configuration must be a mapping")
    values = [value for key, value in document.value if key.value == "docs_dir"]
    if len(values) > 1:
        raise ValueError("docs_dir must be declared once")
    if values:
        value = values[0]
        if not isinstance(value, yaml.ScalarNode) or value.tag != "tag:yaml.org,2002:str" or not value.value:
            raise ValueError("only a literal scalar docs_dir is supported")
        directory = value.value
    else:
        directory = "docs"
    print(json.dumps(directory))
except Exception as error:
    sys.exit("Cannot read the site root with the docs dependencies: " + str(error))
PYTHON;
        $result = ChildProcess::run([$python, '-c', $script, self::$projectRoot . '/website/mkdocs.yml'], self::$projectRoot);
        if ($result['exitCode'] !== 0) {
            throw new LogicException('Cannot read the site root with the docs dependencies: ' . $result['stderr']);
        }
        $directory = json_decode($result['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_string($directory) || $directory === '') {
            throw new LogicException('The docs loader returned no literal docs_dir.');
        }
        $root = self::normalizePath('website/' . $directory);
        if ($root === null) {
            throw new LogicException('The site root is outside the repository.');
        }

        return $root;
    }

    /** @param list<array{int, int}> $spans */
    private static function inSpans(int $offset, array $spans): bool
    {
        return array_any($spans, static fn(array $span): bool => $offset >= $span[0] && $offset < $span[1]);
    }

    private static function lineAt(string $content, int $offset): string
    {
        $start = strrpos(substr($content, 0, $offset), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($content, "\n", $offset);

        return trim(substr($content, $start, ($end === false ? \strlen($content) : $end) - $start));
    }

    /** @return list<array{int, int}> */
    private static function commentSpans(string $syntax, string $content): array
    {
        $spans = [];
        if ($syntax === 'php') {
            $offset = 0;
            foreach (token_get_all($content) as $token) {
                $text = \is_array($token) ? $token[1] : $token;
                if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                    $spans[] = [$offset, $offset + \strlen($text)];
                }
                $offset += \strlen($text);
            }

            return $spans;
        }
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
            if ($character === "'" || $character === '"' || ($syntax === 'slash' && $character === '`')) {
                $quote = $character;
                $triple = $syntax === 'hash' && substr($content, $offset, 3) === str_repeat($character, 3);
                if ($triple) {
                    $offset += 2;
                }
                continue;
            }
            if (($syntax === 'hash' && $character === '#') || ($syntax === 'slash' && substr($content, $offset, 2) === '//')) {
                $end = strpos($content, "\n", $offset);
                $end = $end === false ? $length : $end;
                $spans[] = [$offset, $end];
                $offset = $end;
            } elseif ($syntax === 'slash' && substr($content, $offset, 2) === '/*') {
                $end = strpos($content, '*/', $offset + 2);
                $end = $end === false ? $length - 2 : $end;
                $spans[] = [$offset, $end + 2];
                $offset = $end + 1;
            }
        }

        return $spans;
    }

}
