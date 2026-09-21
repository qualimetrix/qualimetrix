<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DistributedPackage;

use RuntimeException;

/**
 * A scratch tree under the temporary directory: created, and removed again
 * without deleting anything outside itself.
 *
 * Both halves carry a reason that is invisible in the code doing it, which is
 * why each is written once and used by every site in this group. This docblock
 * describes the input the two must survive; what any one control asserts about
 * its own tree stays with that control.
 *
 * ## What removal has to survive
 *
 * The tree is whatever `cp -R`, `tar -x` and a control's own fixture writing
 * put there, and none of those inputs is enumerated here. Two properties of it
 * are therefore assumed rather than checked, and the removal is written to
 * survive both:
 *
 * - **A directory symlink.** `is_dir()` answers true for one, so the obvious
 *   recursion descends through it and deletes what it addresses instead of the
 *   link. Every link *inside* the tree is treated as a leaf. This is a property
 *   of the input, not a count of the links some particular tree holds today —
 *   `vendor/` on this machine is proxies rather than links, and the archive
 *   carries none, but neither fact is one this code may rely on.
 * - **A directory it cannot read.** `scandir()` then returns `false`, which
 *   `(array)` turns into `[false]` rather than into nothing; left alone, the
 *   entry becomes `$path . '/'` and the recursion re-enters the same directory,
 *   one slash longer each turn. Measured, it does not run away: it unwinds once
 *   the path outgrows `PATH_MAX`, or sooner under a nesting guard such as
 *   Xdebug's — but it gets there having emitted thousands of warnings and it
 *   leaves the tree standing. Refusing replaces both with one named message.
 *
 * Other trees in this repository are removed by a recursion spelled much like
 * this one, and those spellings do not agree: at least one carries the
 * `scandir()` check this one had to be given, and omits the symlink guard. That
 * disagreement is the argument for one definition here rather than a claim
 * about how many copies exist — nothing counts them, and a count in prose would
 * be wrong on the next one written. Reaching them is out of scope: every
 * support class in `governance/` is used only inside its own group, and
 * crossing that is a separate decision about where such a control may live.
 *
 * ## What creation has to provide
 *
 * A resolved path, because on macOS the temporary directory is reached through
 * a symlink, and a caller comparing this prefix against a path PHP reports from
 * inside the tree would otherwise fail for a reason unrelated to its subject.
 *
 * And a name nothing else will take: two agents, two terminals, or a test run
 * beside a bench share `sys_get_temp_dir()`.
 * {@see \Qualimetrix\Governance\TestSuiteHygiene\ScratchPathsCarryRealEntropyTest}
 * sweeps the tree for one bad spelling (`uniqid()`) and has no positive
 * requirement to offer — it does not know which lines build a scratch path at
 * all — so the requirement lives here, where the name is built.
 */
final class ScratchTree
{
    /**
     * An empty directory under the temporary directory, by its resolved path.
     *
     * @param string $prefix names the owning control, so a leftover tree says
     *                       what abandoned it
     *
     * @throws RuntimeException when the directory cannot be created or resolved
     */
    public static function create(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));

        // Suppressed because the next line says the same thing by name and
        // this group runs under `failOnWarning`: the raw diagnostic would turn
        // a refusal that is working into a second, louder failure.
        if (!@mkdir($path, 0777, true)) {
            throw new RuntimeException('Could not create ' . $path . ', so nothing here was checked.');
        }

        $resolved = realpath($path);

        if ($resolved === false) {
            throw new RuntimeException('Could not resolve ' . $path . ', so nothing here was checked.');
        }

        return $resolved;
    }

    /**
     * Removes `$path` and everything below it, every link inside it a leaf.
     *
     * `$path` itself is not tested for being a link, because the only thing
     * that produces one of these is {@see self::create()}, which resolves what
     * it returns.
     *
     * Does nothing when `$path` is not a directory. Nothing calls it that way
     * today; it stays because removal runs from a `finally`, where turning
     * "there was no tree" into a second failure would replace a control's
     * verdict with a remark about the cleanup.
     *
     * @throws RuntimeException when a directory cannot be read, `$path` itself
     *                          so the caller learns the tree is still there
     *                          rather than silently leaking it. Raised from a
     *                          `finally`, PHP keeps the original failure as
     *                          this one's previous
     */
    public static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = @scandir($path);

        if ($entries === false) {
            throw new RuntimeException('Could not read ' . $path . ', so the scratch tree is only partly removed.');
        }

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;

            is_dir($child) && !is_link($child) ? self::remove($child) : unlink($child);
        }

        rmdir($path);
    }
}
