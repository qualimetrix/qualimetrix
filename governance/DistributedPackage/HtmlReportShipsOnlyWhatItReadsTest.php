<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DistributedPackage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * What a consumer receives under the HTML report's tree, against what the
 * formatter reads from it.
 *
 * The report is an npm project living at the repository root, outside the
 * PSR-4 tree, so without the `export-ignore` rows beside it the dist package
 * would carry every one of its entries: the eight vitest files, the sources
 * they test, the lockfile, the vite config and the directory's own README. A
 * consumer needs none of them and cannot tell they arrived. Measured at 34 on
 * the tree that closed the relocation, also 34 on the tree that closed stage
 * 01 (the relocation both deleted a file and added a README, so the total
 * held); the count moves whenever the viewer gains or loses a file, so
 * re-derive it rather than quoting this line.
 *
 * The expectation is not a list. It is read out of
 * {@see \Qualimetrix\Reporting\Formatter\Html\HtmlFormatter}, which names the
 * four assets it loads, so adding a fifth load-bearing asset and forgetting to
 * ship it fails here rather than at a consumer's first `--format=html`. A
 * pinned list would have to be edited in the same breath as the formatter and
 * would therefore never catch that.
 *
 * Why this group is neither `PackageVersion` nor `DeclaredDependencies`. The
 * three ask different questions and move on different edits. This one asks
 * which files reach a consumer. `PackageVersion` asks which version this
 * package states about itself, and whether every place stating one agrees.
 * `DeclaredDependencies` asks whether `require` covers what the shipped code
 * names — which no control here can reach, because `git archive` carries no
 * `vendor/`, so the packages it judges are not in the payload at all.
 *
 * An earlier note here said the first two were worth merging once a third
 * control "of either kind" appeared. A third arrived and the merge is
 * rejected. The condition counted controls, and a count is evidence about
 * size, not about subjects: several groups under `governance/` hold a single
 * control each, so this tree does not draw boundaries by how full a directory
 * is. Worse, whether that condition fired is itself arguable — `require` is
 * something the package declares about itself, so the new control reads into
 * `PackageVersion`'s kind or into neither depending on who is asked, and a
 * trigger that ambiguous decides nothing. What the three do share is "the
 * artifact", a predicate that admits anything that ships and would leave this
 * name a container rather than a subject.
 *
 * Also rejected: folding `PackageVersion` into `DeclaredDependencies` because
 * both read `composer.json`'s `require`. Reading one file is not a shared
 * subject — raising `require.php` touches no import, and a new import touches
 * no version — and the two ask different things: that two spellings of a
 * declared floor agree, versus that a declaration covers what the code names.
 * Also rejected: splitting `PackageVersion`, whose halves guard the floor and
 * the reported version; both are a version this package states about itself,
 * and separating them buys a registration pair for no new distinction.
 *
 * The condition to revisit is therefore a property and not a count: when one
 * edit has to change controls in two of these groups for the same reason, or
 * when a new control cannot be placed because it belongs to two of them, the
 * boundary is in the wrong place and the pair it spans should merge.
 */
final class HtmlReportShipsOnlyWhatItReadsTest extends TestCase
{
    private const string TREE = 'html-report';

    #[Test]
    public function itShipsEveryAssetTheFormatterReadsAndNothingElse(): void
    {
        $read = self::assetsTheFormatterReads();

        self::assertNotSame([], $read, 'Read no asset out of the formatter, so every comparison below would be vacuous.');

        $shipped = self::shippedFiles();
        $unshipped = array_values(array_diff($read, $shipped));
        $extra = array_values(array_diff($shipped, $read));

        self::assertSame(
            [],
            $unshipped,
            \sprintf(
                "The formatter reads an asset the dist package does not carry, so --format=html fails for a consumer and not here:\n%s",
                implode("\n", $unshipped),
            ),
        );

        self::assertSame(
            [],
            $extra,
            \sprintf(
                "The dist package carries a file under %s that the formatter never reads. A consumer cannot use it and cannot tell it arrived; add an export-ignore row beside the others:\n%s",
                self::TREE,
                implode("\n", $extra),
            ),
        );
    }

    /**
     * The paths the formatter loads, spelled relative to the tree.
     *
     * @return list<string>
     */
    private static function assetsTheFormatterReads(): array
    {
        $source = (string) file_get_contents(
            self::projectRoot() . '/src/Reporting/Formatter/Html/HtmlFormatter.php',
        );

        preg_match_all('#\$templateDir \. \'(/[^\']+)\'#', $source, $matches);

        $paths = array_map(
            static fn(string $suffix): string => self::TREE . $suffix,
            $matches[1],
        );
        sort($paths, \SORT_STRING);

        return array_values(array_unique($paths));
    }

    /**
     * What a consumer actually receives under the tree.
     *
     * The oracle is `git archive` rather than `git check-attr`, because the two
     * answer different questions and only one of them is the deliverable. An
     * `export-ignore` row naming a directory marks the directory, not the files
     * beneath it: `check-attr` on `html-report/src/tree.js` reports the
     * attribute unset while `git archive` omits the file with the directory it
     * lives in.
     * Reading attributes per file therefore listed every excluded file as
     * shipped. `--worktree-attributes` makes the answer true of the working
     * copy, so a row added and not yet committed is judged rather than ignored.
 *
 * The tree it archives is HEAD's, and that is the right one rather than a
 * limitation: a file nobody committed is a file no consumer receives. A probe
 * that planted an untracked file here stayed green and was wrong about what it
 * had planted, not about the control.
     *
     * @return list<string>
     */
    private static function shippedFiles(): array
    {
        $root = self::projectRoot();
        $listing = self::capture(['git', '-C', $root, 'archive', '--worktree-attributes', '--format=tar', 'HEAD', self::TREE]);

        $paths = [];

        for ($offset = 0; $offset + 512 <= \strlen($listing); $offset += 512) {
            $header = substr($listing, $offset, 512);
            $name = rtrim(substr($header, 0, 100), "\0");

            if ($name === '') {
                continue;
            }

            $size = (int) octdec(trim(rtrim(substr($header, 124, 12), "\0")));
            $offset += (int) (ceil($size / 512) * 512);

            // git writes a pax_global_header entry of its own; anything that is
            // not under the tree is tar's bookkeeping, not a file a consumer gets.
            if (!str_ends_with($name, '/') && str_starts_with($name, self::TREE . '/')) {
                $paths[] = $name;
            }
        }

        if ($paths === []) {
            self::fail('The dist package carries nothing under ' . self::TREE . ', so this control judges an empty set.');
        }

        sort($paths, \SORT_STRING);

        return $paths;
    }

    /** @param list<string> $command */
    private static function capture(array $command): string
    {
        try {
            $result = ChildProcess::run($command);
        } catch (RuntimeException $exception) {
            // Carried whole rather than restated: only run()'s own message
            // says which of its failures this was.
            self::fail($command[0] . ' did not complete, so nothing here was checked: ' . $exception->getMessage());
        }

        self::assertSame(
            0,
            $result['exitCode'],
            implode(' ', $command) . ' failed, so nothing here was checked.'
                . ($result['stderr'] !== '' ? "\n" . $result['stderr'] : ''),
        );

        return $result['stdout'];
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
