<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\System\ScratchPathIsolation\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `uniqid()` is unique inside one process and says nothing about another, and
 * the scratch paths this suite builds live in the shared system temp directory
 * — outside any clone. So two runs of the same case, in different processes,
 * can be handed one directory: measured on
 * `DirectivesCommandTest::itLeavesTheBannedChannelInsideEveryStageAfterSuppression`,
 * four concurrent copies collided in eight of 120 runs, each collision failing
 * one copy on a baseline the other had already written.
 *
 * The probe stand runs its 116 mutations one at a time, so it cannot collide
 * with itself and a sequential `composer check` cannot see this at all. What
 * sees it is a second run alongside the first — which is exactly what any
 * attempt to run this suite in parallel is. A green sequential barrier is no
 * evidence here, and that is why the invariant is a test rather than a habit.
 *
 * The rule takes no exception. `uniqid('', true)` is stronger and still derives
 * from the clock; admitting it would make this guard a rule with a footnote,
 * and the footnote is where the next collision would live. One idiom,
 * `bin2hex(random_bytes())`, and nothing to remember.
 */
final class ScratchPathsCarryRealEntropyTest extends TestCase
{
    private const ROOTS = ['tests', 'scripts'];

    #[Test]
    public function itFindsNoClockDerivedIdentifierInTheTree(): void
    {
        $offenders = [];

        foreach (self::ROOTS as $root) {
            foreach (self::phpFilesIn(\dirname(__DIR__, 4) . '/' . $root) as $file) {
                // The one file the rule cannot read: it names the forbidden
                // call twice, in the condition below and in the message. It
                // builds no scratch path, so there is nothing here to miss.
                if ($file === __FILE__) {
                    continue;
                }

                $contents = file_get_contents($file);
                self::assertIsString($contents, $file);

                foreach (explode("\n", $contents) as $index => $line) {
                    if (!str_contains($line, 'uniqid') || self::isComment($line)) {
                        continue;
                    }

                    $offenders[] = \sprintf('%s:%d', $file, $index + 1);
                }
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "uniqid() names a scratch path in %d place(s). Use bin2hex(random_bytes(6)):\n%s",
            \count($offenders),
            implode("\n", $offenders),
        ));
    }

    /**
     * Proves the scan reaches the tree at all: a rule that reads nothing
     * reports no offender either, and would pass forever.
     */
    #[Test]
    public function itReadsTheWholeTreeItJudges(): void
    {
        $counted = 0;

        foreach (self::ROOTS as $root) {
            $counted += \count(self::phpFilesIn(\dirname(__DIR__, 4) . '/' . $root));
        }

        self::assertGreaterThan(500, $counted);
        self::assertNotSame([], self::phpFilesIn(\dirname(__DIR__, 4) . '/scripts'));
    }

    /** @return list<string> */
    private static function phpFilesIn(string $directory): array
    {
        $files = [];
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $directory,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));

        /** @var SplFileInfo $entry */
        foreach ($walk as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function isComment(string $line): bool
    {
        $trimmed = ltrim($line);

        return str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '/*');
    }
}
