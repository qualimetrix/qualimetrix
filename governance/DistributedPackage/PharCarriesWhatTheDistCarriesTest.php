<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DistributedPackage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        'README.md' => 'read on the repository, not from inside an archive',
        'action.yml' => 'the GitHub Action reads it from a checkout of this repository',
        'composer.json' => 'box writes the archive\'s own autoloader; a consumer installs nothing',
        'composer.lock' => 'same',
        'qmx-baseline.json' => 'this repository\'s own ratchet snapshot, not a consumer\'s',
        'qmx.yaml' => 'this repository\'s own dogfooding configuration, not a consumer\'s',
        'qmx.yaml.example' => 'a sample to copy out of the repository, not to carry in the archive',
        'src/.gitkeep' => "box resolves directories through Finder, which ignores dot-files; this one holds no code",
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
     * Keys this control knows how to resolve. Anything else in `box.json`
     * fails rather than being skipped: a key that adds payload and is not
     * modelled here is invisible in exactly the direction the control exists
     * to watch.
     *
     * @var list<string>
     */
    private const array KNOWN_KEYS = [
        'main', 'output', 'compression', 'check-requirements', 'compactors',
        'directories', 'files', 'finder',
    ];

    /**
     * What `box.json` puts in the archive, `vendor/` aside.
     *
     * Resolved from the committed tree rather than from disk. Both sides of
     * the comparison then answer about one tree: reading `directories` off the
     * filesystem while the dist side reads `HEAD` made an uncommitted file
     * under `src/` look like a phar carrying something the dist excludes —
     * a red control naming the wrong cause, on the ordinary path where
     * `composer check` runs before the commit.
     *
     * Dot-files are dropped because box resolves `directories` through
     * Symfony's Finder, which ignores them by default. That rule is reproduced
     * here rather than inherited, and it is the one place this control models
     * box's behaviour instead of reading it; before it was reproduced, the
     * control believed `src/.gitkeep` shipped and the two sides balanced
     * because the dist carries it too.
     *
     * `main` is included because box adds the entry point whether or not any
     * other setting names it, and it is a file the dist ships too.
     *
     * @return list<string>
     */
    private static function pharPayload(): array
    {
        $root = self::projectRoot();

        /** @var array<string, mixed> $config */
        $config = json_decode((string) file_get_contents($root . '/box.json'), true, 512, \JSON_THROW_ON_ERROR);

        $unknown = array_values(array_diff(array_keys($config), self::KNOWN_KEYS));

        self::assertSame(
            [],
            $unknown,
            \sprintf(
                "box.json carries a key this control does not resolve, so whatever it adds to the archive is "
                . "outside every comparison below. Teach the control the key, or remove it:\n%s",
                implode("\n", $unknown),
            ),
        );

        self::assertSame(
            [['in' => ['vendor'], 'notPath' => ['#^bin/#'], 'ignoreVCS' => true]],
            $config['finder'] ?? null,
            'The finder block is excluded from this comparison on the grounds that it names only vendor/. '
            . 'It no longer does, so the exclusion is now hiding payload.',
        );

        $paths = [];

        if (isset($config['main']) && \is_string($config['main'])) {
            $paths[] = $config['main'];
        }

        /** @var list<string> $files */
        $files = $config['files'] ?? [];

        foreach ($files as $file) {
            $paths[] = $file;
        }

        /** @var list<string> $directories */
        $directories = $config['directories'] ?? [];

        foreach ($directories as $directory) {
            $tracked = self::committedFilesUnder($directory);

            self::assertNotSame(
                [],
                $tracked,
                \sprintf('box.json names the directory "%s", and HEAD carries no file under it.', $directory),
            );

            foreach ($tracked as $path) {
                if (self::hasDotSegment($path)) {
                    continue;
                }

                $paths[] = $path;
            }
        }

        sort($paths, \SORT_STRING);

        return array_values(array_unique($paths));
    }

    /**
     * Files `HEAD` carries under a directory, spelled from the repository root.
     *
     * @return list<string>
     */
    private static function committedFilesUnder(string $directory): array
    {
        $listing = self::capture([
            'git', '-C', self::projectRoot(), 'ls-tree', '-r', '--name-only', 'HEAD', '--', $directory,
        ]);

        $paths = [];

        foreach (explode("\n", $listing) as $path) {
            $path = trim($path);

            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** Finder's default: a path is ignored when any segment of it starts with a dot. */
    private static function hasDotSegment(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (str_starts_with($segment, '.')) {
                return true;
            }
        }

        return false;
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
