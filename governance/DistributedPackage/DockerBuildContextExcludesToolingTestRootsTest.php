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
 * as the LAST line matching it — not necessarily in the nested-test-root
 * block, which is why `governance/` (covered at `.dockerignore:43`, in the
 * "Test outputs and coverage" block) satisfies it. This is deliberately a
 * stronger requirement than "Docker's glob semantics would exclude it":
 * reproducing Docker's own pattern matching here would need to encode the
 * root-anchored versus double-star-anchored distinction this repository's
 * own comments already struggled to state correctly twice, so the control
 * demands the unambiguous form this file already uses for every currently-
 * registered key — a literal, exact line — rather than trusting a second
 * glob implementation to agree with Docker's.
 *
 * "Last line matching it" exists because Docker resolves `.dockerignore`
 * patterns last-match-wins: a `!key` line added below a key's exclusion row
 * un-excludes it (round 3 of review measured this against a real build), and
 * a control that only asked "is the exact line present somewhere" could not
 * see that. This control tracks, per judged key, the last line among exactly
 * `key` and `!key` — nothing more elaborate. A negated double-star pattern
 * broad enough to reach the key without naming it (a hypothetical
 * `!` + double-star + `/tests/`, say) is not detected, because catching that
 * would mean reproducing Docker's glob semantics, which this control has
 * already declined to do for the positive direction.
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
 * check that can no longer refuse would not have. The extraction that builds
 * the judged set is witnessed a second, independent way (an arrow count) so
 * an entry written in a form the entry regex does not read (double quotes, a
 * non-string-literal value, a trailing comment) fails loudly instead of
 * silently narrowing the set the rest of this test judges.
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

        $uncovered = [];
        foreach ($judged as $key) {
            $status = self::lastMatchStatus($key, $lines);
            if ($status !== 'positive') {
                $uncovered[] = \sprintf('%s (%s)', $key, $status === 'negated' ? 'last matching line is a ! negation' : 'no matching line');
            }
        }

        self::assertSame(
            [],
            $uncovered,
            \sprintf(
                "The docker build context is not known to exclude these tooling test roots — add an exact line to"
                . " .dockerignore for each (as the last line matching it), or an entry to EXCUSED_KEYS naming the"
                . " pattern that covers it instead:\n%s",
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

    /**
     * `key` counts as excluded only if the LAST `.dockerignore` line equal to
     * either `key` or `!key` is the positive form — Docker's own last-match-
     * wins rule, applied to exactly this one pattern shape per key.
     *
     * @param list<string> $lines
     */
    private static function lastMatchStatus(string $key, array $lines): ?string
    {
        $status = null;
        foreach ($lines as $line) {
            if ($line === $key) {
                $status = 'positive';
            } elseif ($line === '!' . $key) {
                $status = 'negated';
            }
        }

        return $status;
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

        // Second witness, independent of the entry regex above: every entry
        // in this flat string=>string map has exactly one `=>`, and no
        // comment in this block contains one (verified by reading it) — so
        // the raw count must equal what the regex matched. An entry written
        // in a form the regex does not read (double-quoted key or value, a
        // non-string-literal value, a trailing comment before the comma)
        // still contributes its `=>` to this count, so a mismatch here means
        // the entry regex silently dropped a real entry rather than that the
        // map is genuinely smaller.
        $arrowCount = substr_count($match[1], '=>');
        self::assertSame(
            $arrowCount,
            \count($entries[1]),
            \sprintf(
                'TOOLING_TEST_ROOT_OWNERS has %d "=>" occurrences in its block but the entry regex matched only %d'
                . ' — some entry is written in a form (quoting, a non-literal value, a trailing comment) this test'
                . ' does not read, and would silently drop out of the set the rest of this test judges. Widen the'
                . ' regex or read the missed entry by hand before trusting the count below.',
                $arrowCount,
                \count($entries[1]),
            ),
        );

        $keys = array_values(array_filter(
            $entries[1],
            static fn(string $key): bool => str_ends_with($key, '/'),
        ));
        sort($keys, \SORT_STRING);

        return $keys;
    }

    /** @return list<string> non-comment, non-blank lines of .dockerignore, trimmed, in file order */
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
