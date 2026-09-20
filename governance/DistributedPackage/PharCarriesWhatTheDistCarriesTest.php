<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DistributedPackage;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `box.json`'s payload against the dist package's, so the two enumerations of
 * "what ships" cannot drift apart silently.
 *
 * There are three such enumerations. `.gitattributes`'s `export-ignore` shapes
 * the composer dist, `box.json` shapes the phar, and `.dockerignore` shapes the
 * image. Nothing derives one from another: `git archive` carries no `vendor/`,
 * so the phar cannot simply be the dist, and the lists are maintained side by
 * side. This control holds the first two against each other in both directions,
 * because each direction fails differently and only one of them is loud.
 *
 * A file in the phar that the dist excludes is the quiet one. `export-ignore`
 * exists to keep something out of a consumer's hands; a phar that carries it
 * anyway reverses that decision without restating it.
 *
 * A file in the dist that the phar omits is usually correct — a consumer
 * running an archive has no use for `composer.lock` or `README.md` — so the
 * omissions are declared below with their reason rather than tolerated as a
 * class. Adding a load-bearing file to the dist and forgetting `box.json` then
 * fails here instead of at a user's first run.
 *
 * `vendor/` is outside the comparison on purpose: it is in the phar and absent
 * from the dist by construction, which is the whole reason the phar cannot be
 * derived from `git archive`.
 *
 * **What this control cannot see.** It resolves `box.json` itself rather than
 * reading a built artifact, so it judges the configuration's meaning, not the
 * archive's contents. That keeps it free of a build step and a network fetch,
 * and it costs the case where box resolves the same configuration differently
 * than this file does — a box upgrade changing `directories` semantics would
 * pass here and diverge in the artifact. The artifact-level comparison belongs
 * to the job that builds one.
 */
final class PharCarriesWhatTheDistCarriesTest extends TestCase
{
    /**
     * Dist entries the phar deliberately omits, each with the reason it is not
     * an oversight. A path leaving this list must leave it in the same change
     * that adds it to `box.json`.
     *
     * @var array<string, string>
     */
    private const array DIST_ONLY = [
        'AGENTS.md' => 'working rules for this repository, meaningless to a consumer',
        'CHANGELOG.md' => 'read on the release page, not from inside an archive',
        'LICENSE' => 'carried by the release, and a phar is not a redistributable source package',
        'README.md' => 'read on the repository, not from inside an archive',
        'action.yml' => 'the GitHub Action reads it from a checkout of this repository',
        'composer.json' => 'box writes the archive\'s own autoloader; a consumer installs nothing',
        'composer.lock' => 'same',
        'qmx-baseline.json' => 'this repository\'s own ratchet snapshot, not a consumer\'s',
        'qmx.yaml' => 'this repository\'s own dogfooding configuration, not a consumer\'s',
        'qmx.yaml.example' => 'a sample to copy out of the repository, not to carry in the archive',
    ];

    #[Test]
    public function itCarriesNothingTheDistributionKeepsBack(): void
    {
        $phar = self::pharPayload();
        $dist = self::distPayload();

        self::assertNotSame([], $phar, 'Resolved no payload out of box.json, so every comparison here would be vacuous.');
        self::assertNotSame([], $dist, 'The dist package is empty, so every comparison here would be vacuous.');

        $onlyInPhar = array_values(array_diff($phar, $dist));

        self::assertSame(
            [],
            $onlyInPhar,
            \sprintf(
                "box.json carries a file the dist package excludes, so an export-ignore decision is reversed in the phar "
                . "without being restated:\n%s",
                implode("\n", $onlyInPhar),
            ),
        );
    }

    #[Test]
    public function itOmitsOnlyWhatIsDeclaredOmitted(): void
    {
        $phar = self::pharPayload();
        $dist = self::distPayload();

        $onlyInDist = array_values(array_diff($dist, $phar));
        $declared = array_keys(self::DIST_ONLY);
        sort($declared, \SORT_STRING);

        $undeclared = array_values(array_diff($onlyInDist, $declared));
        $stale = array_values(array_diff($declared, $onlyInDist));

        self::assertSame(
            [],
            $undeclared,
            \sprintf(
                "The dist package carries a file the phar does not, and nothing says why. Add it to box.json, or to "
                . "DIST_ONLY with the reason a consumer running an archive does not need it:\n%s",
                implode("\n", $undeclared),
            ),
        );

        self::assertSame(
            [],
            $stale,
            \sprintf(
                "DIST_ONLY names a path that is no longer omitted, so the reason beside it is describing nothing:\n%s",
                implode("\n", $stale),
            ),
        );
    }

    /**
     * What `box.json` puts in the archive, `vendor/` aside.
     *
     * `main` is included because box adds the entry point whether or not any
     * other setting names it, and it is a file the dist ships too.
     *
     * @return list<string>
     */
    private static function pharPayload(): array
    {
        $root = self::projectRoot();

        /** @var array{main?: string, directories?: list<string>, files?: list<string>} $config */
        $config = json_decode((string) file_get_contents($root . '/box.json'), true, 512, \JSON_THROW_ON_ERROR);

        $paths = [];

        if (isset($config['main'])) {
            $paths[] = $config['main'];
        }

        foreach ($config['files'] ?? [] as $file) {
            $paths[] = $file;
        }

        foreach ($config['directories'] ?? [] as $directory) {
            $absolute = $root . '/' . $directory;

            if (!is_dir($absolute)) {
                self::fail(\sprintf('box.json names the directory "%s", which does not exist.', $directory));
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            ) as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $paths[] = $directory . '/' . str_replace('\\', '/', substr($file->getPathname(), \strlen($absolute) + 1));
            }
        }

        sort($paths, \SORT_STRING);

        return array_values(array_unique($paths));
    }

    /**
     * What a consumer receives from the composer dist, `vendor/` aside.
     *
     * `git archive` rather than `git check-attr`, for the reason the sibling
     * control records: an `export-ignore` row naming a directory marks the
     * directory and not the files beneath it, so reading attributes per file
     * reports excluded files as shipped. `--worktree-attributes` judges the
     * working copy, so a row added and not yet committed is judged rather than
     * ignored; the tree archived is still HEAD's, which is correct, because a
     * file nobody committed is a file no consumer receives.
     *
     * The listing comes from `tar`, not from reading the archive's headers
     * here. tar's name field holds 100 bytes and git spills anything longer
     * into a pax extension record, so a hand-rolled reader reports those paths
     * as their basename. Two files under `src/` are already past the limit, and
     * a reader that mangles a name does not fail — it silently compares the
     * wrong string.
     *
     * @return list<string>
     */
    private static function distPayload(): array
    {
        $root = self::projectRoot();
        $archive = (string) tempnam(sys_get_temp_dir(), 'qmx-dist-');

        try {
            self::capture(['git', '-C', $root, 'archive', '--worktree-attributes', '--format=tar', '--output=' . $archive, 'HEAD']);
            $listing = self::capture(['tar', '-tf', $archive]);
        } finally {
            @unlink($archive);
        }

        $paths = [];

        foreach (explode("\n", $listing) as $name) {
            $name = trim($name);

            if ($name === '' || str_ends_with($name, '/') || $name === 'pax_global_header') {
                continue;
            }

            $paths[] = $name;
        }

        sort($paths, \SORT_STRING);

        return array_values(array_unique($paths));
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** @param list<string> $command */
    private static function capture(array $command): string
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!\is_resource($process)) {
            self::fail('Could not run ' . implode(' ', $command));
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);

        if ($status !== 0) {
            self::fail(\sprintf("%s exited %d:\n%s", implode(' ', $command), $status, $stderr));
        }

        return $stdout;
    }
}
