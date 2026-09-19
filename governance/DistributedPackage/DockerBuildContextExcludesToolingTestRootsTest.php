<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DistributedPackage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether every directory-shaped tooling test root is kept out of the docker
 * build context.
 *
 * `.dockerignore` carries an explicit, hand-maintained list of nested tooling
 * test roots (round 1 of the viewer relocation review found the comment
 * above that list claiming it "mirrors `TOOLING_TEST_ROOT_OWNERS`" while
 * three new keys were absent from it; round 2 found the reworded claim still
 * false, by `governance/`, which is excluded by its own row in an earlier
 * block rather than by the nested-test-root list). Nothing read both files
 * together, so either divergence was silent. This is that read.
 *
 * The judged set is every directory-shaped key of `TOOLING_TEST_ROOT_OWNERS`
 * (every key ending in `/` — `classifyOwner()`'s own reading of that map, see
 * `scripts/generate-modular-architecture-test-inventory.php`). The rule is:
 * each one needs an EXACT line in `.dockerignore`, equal to the key itself,
 * somewhere in the file — not necessarily in the nested-test-root block,
 * which is why `governance/` (covered at `.dockerignore:43`, in the "Test
 * outputs and coverage" block) satisfies it. This is deliberately a stronger
 * requirement than "Docker's glob semantics would exclude it": reproducing
 * Docker's own pattern matching here would need to encode the root-anchored
 * versus double-star-anchored distinction this repository's own comments
 * already struggled to state correctly twice, so the control demands the
 * unambiguous form this file already uses for every currently-registered
 * key — a literal, exact line — rather than trusting a second glob
 * implementation to agree with Docker's.
 *
 * No key is excused from this today. `EXCUSED_KEYS` exists for the day a key
 * is legitimately covered only by a broader existing pattern (a future
 * `foo/tests/` landing under a directory some other rule already excludes
 * wholesale, say) rather than by its own line; an entry there must name the
 * covering pattern and the reason, the same way `NON_MANIFEST_TEST_OWNERS`
 * in the generator names its exceptions rather than silently widening a
 * filter.
 *
 * This is a set-difference assertion, not a presence check: it fails if ANY
 * judged key lacks its line, not merely if all of them do — the shape a
 * check that can no longer refuse would not have.
 */
final class DockerBuildContextExcludesToolingTestRootsTest extends TestCase
{
    /**
     * @var array<string, string> key => the reason it needs no exact line of
     *                            its own, and the pattern that covers it
     *                            instead
     */
    private const array EXCUSED_KEYS = [];

    #[Test]
    public function itExcludesEveryDirectoryShapedToolingTestRootFromTheDockerBuildContext(): void
    {
        $keys = self::directoryShapedToolingTestRootKeys();
        self::assertNotSame(
            [],
            $keys,
            'Read no directory-shaped key out of TOOLING_TEST_ROOT_OWNERS, so every comparison below would be vacuous.',
        );

        $lines = self::dockerignoreLines();
        self::assertNotSame([], $lines, 'Read no pattern out of .dockerignore, so every comparison below would be vacuous.');

        $judged = array_values(array_diff($keys, array_keys(self::EXCUSED_KEYS)));

        $uncovered = array_values(array_filter(
            $judged,
            static fn(string $key): bool => !\in_array($key, $lines, true),
        ));

        self::assertSame(
            [],
            $uncovered,
            \sprintf(
                "The docker build context is not known to exclude these tooling test roots — add an exact line to"
                . " .dockerignore for each, or an entry to EXCUSED_KEYS naming the pattern that covers it instead:\n%s",
                implode("\n", $uncovered),
            ),
        );

        $staleExcusals = array_values(array_diff(array_keys(self::EXCUSED_KEYS), $keys));
        self::assertSame(
            [],
            $staleExcusals,
            \sprintf(
                'EXCUSED_KEYS names a key no longer present as a directory-shaped TOOLING_TEST_ROOT_OWNERS key: %s',
                implode(', ', $staleExcusals),
            ),
        );
    }

    /** @return list<string> */
    private static function directoryShapedToolingTestRootKeys(): array
    {
        $source = (string) file_get_contents(
            self::projectRoot() . '/scripts/generate-modular-architecture-test-inventory.php',
        );

        if (preg_match('#const TOOLING_TEST_ROOT_OWNERS = \[(.*?)\n\];#s', $source, $match) !== 1) {
            self::fail('Could not find the TOOLING_TEST_ROOT_OWNERS declaration to read its keys from.');
        }

        preg_match_all("#'([^']+)'\\s*=>\\s*'[^']+',#", $match[1], $entries);

        $keys = array_values(array_filter(
            $entries[1],
            static fn(string $key): bool => str_ends_with($key, '/'),
        ));
        sort($keys, \SORT_STRING);

        return $keys;
    }

    /** @return list<string> non-comment, non-blank lines of .dockerignore, trimmed */
    private static function dockerignoreLines(): array
    {
        $raw = (string) file_get_contents(self::projectRoot() . '/.dockerignore');

        $lines = [];
        foreach (explode("\n", $raw) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $lines[] = $trimmed;
        }

        return $lines;
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
