<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\PlanningRecords;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The plan index (`docs/internal/plans/README.md`) must list every active
 * plan directory exactly once, and executable sources must reach no planning
 * record: a plan path, a plan-local filename, or a package-chronology marker
 * inside a comment.
 *
 * **Known gap: `$roots` is not every root that holds executable source.**
 * Measured on the tree that retired the test-structure campaign: `tools/` (9
 * PHP files), `finding-gate/` (73), `input-doors/` (24) and `promise-effect/`
 * (18) carry code this control never reads -- 124 files. The extensions list
 * below already covers `py`, so the omission is the root list, not the filter.
 *
 * This is not hypothetical. `defect-ledger/reproduce-commit-reach.py`
 * hard-coded a concrete plan path and quoted, three lines above it, the very
 * refusal it was violating; it survived every run because its root is not
 * here. It was deleted with the campaign rather than fixed, so the gap now
 * guards nothing that is known to break -- which is exactly when a widening
 * is cheap. Widening it changes governance coverage for four roots at once,
 * so it wants its own package and its own planted proof per root.
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
        $roots = ['bin', 'governance', 'scripts', 'src', 'tests'];
        $dependencies = [];
        $self = __FILE__;
        $patterns = [
            'concrete plan path' => '~docs/internal/plans/(?!README\.md\b)[A-Za-z0-9._/-]+~',
            'plan-local filename' => self::PLAN_LOCAL_REFERENCE_PATTERN,
        ];

        foreach ($roots as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                self::$projectRoot . '/' . $root,
                FilesystemIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (!$file->isFile() || !\in_array($file->getExtension(), ['md', 'php', 'py', 'js', 'mjs', 'json', 'yaml', 'yml', 'tsv'], true)) {
                    continue;
                }

                if ($file->getPathname() === $self
                    || str_contains($file->getPathname(), '/node_modules/')
                    || str_contains($file->getPathname(), '/vendor/')
                    || str_contains($file->getPathname(), '/dist/')) {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                \assert($content !== false);
                $relativePath = substr($file->getPathname(), \strlen(self::$projectRoot) + 1);

                foreach ($patterns as $kind => $pattern) {
                    preg_match_all($pattern, $content, $matches, \PREG_OFFSET_CAPTURE);

                    foreach ($matches[0] as [$match, $offset]) {
                        $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                        $dependencies[] = "{$relativePath}:{$line}: {$kind}: {$match}";
                    }
                }

                $commentContent = self::commentContent($file->getExtension(), $content);
                preg_match_all(self::PACKAGE_CHRONOLOGY_PATTERN, $commentContent, $matches);

                foreach ($matches[0] as $match) {
                    $dependencies[] = "{$relativePath}: package chronology in comment: {$match}";
                }
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($dependencies)),
            "Executable sources depend on planning records or package chronology:\n" . implode("\n", $dependencies),
        );
    }

    #[Test]
    public function itRecognizesPlanningChronologyOnlyInsideComments(): void
    {
        self::assertSame('', self::commentContent('js', 'const X12 = 1;'));
        self::assertSame('', self::commentContent('js', 'const marker = "// P5 is data";'));
        self::assertMatchesRegularExpression(
            self::PACKAGE_CHRONOLOGY_PATTERN,
            self::commentContent('js', '// P5 was the implementation package.'),
        );
        self::assertMatchesRegularExpression(
            self::PACKAGE_CHRONOLOGY_PATTERN,
            self::commentContent('py', "# \u{0420}5 was the implementation package."),
        );
        self::assertMatchesRegularExpression(
            self::PACKAGE_CHRONOLOGY_PATTERN,
            self::commentContent('py', 'value = 1 # P5 was the implementation package.'),
        );
        self::assertMatchesRegularExpression(self::PLAN_LOCAL_REFERENCE_PATTERN, 'See 01-freeze-kind.md.');
    }

    private function readFile(string $relativePath): string
    {
        $path = self::$projectRoot . '/' . $relativePath;
        self::assertFileExists($path, "Documentation file not found: {$relativePath}");

        $content = file_get_contents($path);
        \assert($content !== false);

        return $content;
    }

    private static function commentContent(string $extension, string $content): string
    {
        if ($extension === 'php') {
            $comments = [];

            foreach (token_get_all($content) as $token) {
                if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                    $comments[] = $token[1];
                }
            }

            return implode("\n", $comments);
        }

        return match ($extension) {
            'py' => self::hashCommentsOutsideStrings($content),
            'js', 'mjs' => self::javascriptCommentsOutsideStrings($content),
            default => '',
        };
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
