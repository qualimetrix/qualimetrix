<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxDirectiveAudit\EnumeratedSite;
use QmxDirectiveAudit\ThresholdDirectiveScan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * The seeded fixtures `composer directives:narrow-control` measures its
 * heterogeneous half over must stay out of `src/` and out of the enumeration
 * `composer directives:audit` judges the tree against. Split off from
 * `ThresholdPopulationAgreementTest` (which keeps the product-behaviour cases):
 * both methods here quantify over the whole tree rather than fixed input.
 *
 * The library has no PSR-4 entry, the same as `scripts/finding-gate/`, so this
 * test loads it the way its own scripts do.
 */
final class SeededFixtureIsolationTest extends TestCase
{
    /** The seeded tree `composer directives:narrow-control` measures its heterogeneous half over. */
    private const string SEEDED_FIXTURE = 'tests/Analysis/Policy/Inline/Fixtures/NarrowControl';

    public static function setUpBeforeClass(): void
    {
        foreach (['EnumeratedSite', 'ThresholdDirectiveScan'] as $part) {
            require_once \dirname(__DIR__, 2) . '/scripts/directive-audit/' . $part . '.php';
        }
    }

    /**
     * No seeded fixture file, of any directive form, is also sitting under
     * `src/`.
     *
     * By content, so the identity does not depend on the parser of any one tag:
     * `EveryChannelSuppression.php` carries a `@qmx-ignore *` and no threshold
     * at all, and the enumeration-shaped barrier above cannot see it. A copy of
     * it under `src/` would silence every rule on its class in the ratchet
     * without a single measurement moving.
     *
     * What this catches is a copy, not a rewrite: a leaked file someone then
     * edited is out of its reach, and no cheap check has that reach. It is the
     * mistake the planting probe in `directives:controls` makes, and the one a
     * misplaced `git mv` or an over-broad `cp -R` makes.
     */
    #[Test]
    public function itKeepsEverySeededFixtureFileOutOfSrc(): void
    {
        $root = \dirname(__DIR__, 2);

        $seeded = self::phpFileHashes($root . '/' . self::SEEDED_FIXTURE);
        self::assertNotSame([], $seeded, 'the seeded fixture holds no PHP file, so this case proves nothing');

        // Both maps are keyed by the hash, so the intersection is by content and
        // its values name where the copy landed.
        $leaked = array_values(array_intersect_key(self::phpFileHashes($root . '/src'), $seeded));

        self::assertSame([], $leaked, 'a seeded fixture file is sitting under src/');
    }

    /**
     * The seeded directives stay out of the tree's own measurement.
     *
     * `NarrowControl` exists to make the narrow/full comparison face a
     * population it could disagree over: a dead directive, an overrun boundary,
     * a masking coalition and three refusals, all authored on purpose. None of
     * them is a statement about this project's code, and the enumeration over
     * `src/` — the measure `composer directives:audit` judges the tree against
     * — must not carry them.
     *
     * The enumerator has no exclusion mechanism, so what keeps the two apart is
     * the target `src`, which is a convention rather than a barrier. This case
     * is the barrier. It compares by the site's own content rather than by its
     * path, so it still reddens when the fixture is moved under `src/`, which is
     * exactly the mistake a convention permits.
     *
     * It sees `@qmx-threshold` and nothing else, because that is all the
     * enumeration it guards can see. The seeded suppression is covered by
     * {@see itKeepsEverySeededFixtureFileOutOfSrc()} instead, which is a
     * different barrier for a different reason: a leaked `@qmx-ignore *` would
     * not disturb any enumeration at all, it would silence every rule on its
     * class in `check`, in the suppression snapshot and in the ratchet.
     *
     * @throws RuntimeException
     */
    #[Test]
    public function itKeepsTheSeededDirectivesOutOfTheEnumerationOverSrc(): void
    {
        $root = \dirname(__DIR__, 2);

        $seeded = array_map(self::contentIdentity(...), ThresholdDirectiveScan::overTree($root, self::SEEDED_FIXTURE));
        self::assertNotSame([], $seeded, 'the seeded fixture carries no directive, so this case proves nothing');

        $enumerated = array_map(self::contentIdentity(...), ThresholdDirectiveScan::overTree($root, 'src'));

        self::assertSame(
            [],
            array_values(array_unique(array_intersect($enumerated, $seeded))),
            'a seeded directive reached the enumeration over src/',
        );
    }

    /**
     * Every PHP file under a tree, as sha256 => path.
     *
     * Keyed by the hash so two callers can be intersected on content; the path
     * is the value only so a failure names where the copy is.
     *
     * @return array<string, string>
     */
    private static function phpFileHashes(string $tree): array
    {
        $hashes = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $hash = hash_file('sha256', $file->getPathname());

            if ($hash === false) {
                throw new RuntimeException(\sprintf('unreadable: %s', $file->getPathname()));
            }

            $hashes[$hash] = $file->getPathname();
        }

        return $hashes;
    }

    /**
     * A site by what it says rather than by where it is: file name, line,
     * target and values.
     *
     * The directory is deliberately not part of it. An identity carrying the
     * path would report two copies of one fixture as two different sites, and
     * the mistake this case guards against is exactly a copy that moved.
     */
    private static function contentIdentity(EnumeratedSite $site): string
    {
        return \sprintf('%s:%d:%s:%s', basename($site->file), $site->line, $site->target, $site->values);
    }
}
