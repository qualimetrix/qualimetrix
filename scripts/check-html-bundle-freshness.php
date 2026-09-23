#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Checks that the committed `html-report/dist/report.min.js` — the bundle
 * {@see \Qualimetrix\Reporting\Formatter\Html\HtmlFormatter} inlines into
 * every `--format=html` report — was actually built from the current
 * `html-report/src/` sources.
 *
 * Nothing else in `composer check` ties the two together.
 * `html-report/tests/main.test.js` (run by `composer test:js`) exercises
 * `src/main.js` directly, and `governance/DistributedPackage/HtmlReportShipsOnlyWhatItReadsTest`
 * only checks which files ship, not whether the shipped bundle agrees with
 * the sources that produced it. A `src/main.js` edit committed without
 * `composer build:js` would leave every one of those green while the shipped
 * report keeps running the old bundle.
 *
 * **Runs `vite build` directly, not `npm run build`.** The `build` script is
 * `build:app && build:d3`, and `build:d3` (`scripts/bundle-d3.js`) writes
 * `dist/d3.min.js` in place — the exact tracked-file write this check must
 * not perform. `build:app` alone (`vite build`) is what produces
 * `report.min.js`, so this script invokes vite's own CLI with `--outDir`
 * pointed at a scratch directory instead.
 *
 * The build is deterministic for a fixed `vite`/`esbuild`/Node version: two
 * builds from the same sources produce byte-identical output (verified by
 * hand while writing this check). A version drift between environments is
 * therefore the one way this script could disagree with itself across
 * machines. It **is** guarded, for the two packages that determine the bytes:
 * {@see toolchainDriftReport()} compares the installed `vite`/`esbuild`
 * against the versions `package-lock.json` pins before building anything.
 * `composer install:js` skips `npm ci` whenever `node_modules` already
 * exists, so a lockfile bump does not by itself update an existing checkout;
 * left unguarded, a stale local toolchain would build a byte-different
 * bundle, this check would call it "stale", and a developer following that
 * advice would commit a bundle CI (which always runs `npm ci` first) then
 * rejects. Refusing on drift instead of building routes that developer to the
 * actual fix, `npm ci`, rather than to a rebuild that cannot pass CI.
 */

use Qualimetrix\Subprocess\ChildProcess;

require_once __DIR__ . '/subprocess/ChildProcess.php';

/**
 * The packages whose installed build determines `report.min.js`'s bytes.
 * `rollup` is vite's bundler but is not compared here: vite vendors its own
 * copy for the build path this script exercises, so its `node_modules` entry
 * does not affect `vite build`'s output the way an out-of-sync `vite` or
 * `esbuild` does.
 */
const TOOLCHAIN_PACKAGES = ['vite', 'esbuild'];

/**
 * Compares the installed `vite`/`esbuild` (what `vite build` actually runs)
 * against the versions `package-lock.json` pins (what CI's `npm ci` installs).
 * Returns null when they agree, or a human-readable mismatch report.
 */
function toolchainDriftReport(string $htmlReportDir): ?string
{
    $lockPath = $htmlReportDir . '/package-lock.json';
    $lockContents = is_file($lockPath) ? file_get_contents($lockPath) : false;

    if ($lockContents === false) {
        return sprintf('Failed to read %s.', $lockPath);
    }

    $lock = json_decode($lockContents, true);

    if (!is_array($lock)) {
        return sprintf('Failed to parse %s as JSON.', $lockPath);
    }

    $mismatches = [];

    foreach (TOOLCHAIN_PACKAGES as $package) {
        $pinned = $lock['packages']['node_modules/' . $package]['version'] ?? null;
        $installedManifest = $htmlReportDir . '/node_modules/' . $package . '/package.json';
        $installed = null;

        if (is_file($installedManifest)) {
            $installedContents = file_get_contents($installedManifest);
            $installedJson = $installedContents !== false ? json_decode($installedContents, true) : null;
            $installed = is_array($installedJson) ? ($installedJson['version'] ?? null) : null;
        }

        if ($pinned === null || $installed === null || $installed !== $pinned) {
            $mismatches[] = sprintf(
                '%s: package-lock.json pins %s, node_modules has %s',
                $package,
                $pinned ?? 'no pin found',
                $installed ?? 'not installed',
            );
        }
    }

    return $mismatches === [] ? null : implode('; ', $mismatches);
}

function checkHtmlBundleFreshness(): int
{
    $root = dirname(__DIR__);
    $htmlReportDir = $root . '/html-report';
    $vite = $htmlReportDir . '/node_modules/.bin/vite';
    $shippedBundle = $htmlReportDir . '/dist/report.min.js';

    if (!is_file($vite)) {
        fwrite(STDERR, sprintf(
            "%s is missing. Run `cd html-report && npm ci` (or `npm install`) first, then re-run this check.\n",
            $vite,
        ));

        return 2;
    }

    if (!is_file($shippedBundle)) {
        fwrite(STDERR, sprintf(
            "%s does not exist. Run `composer build:js` to generate it.\n",
            $shippedBundle,
        ));

        return 2;
    }

    $driftReport = toolchainDriftReport($htmlReportDir);

    if ($driftReport !== null) {
        fwrite(STDERR, sprintf(
            "The installed html-report/node_modules build toolchain does not match"
            . " html-report/package-lock.json, so a rebuild would not be comparable to what"
            . " CI's `npm ci` produces: %s. Run `npm ci` in html-report to install the pinned"
            . " versions, then re-run this check — do not run `composer build:js` yet.\n",
            $driftReport,
        ));

        return 3;
    }

    $scratchDir = sys_get_temp_dir() . '/qmx-html-bundle-freshness-' . bin2hex(random_bytes(12));

    if (!mkdir($scratchDir, 0o777, true) && !is_dir($scratchDir)) {
        fwrite(STDERR, sprintf("Failed to create scratch directory %s.\n", $scratchDir));

        return 2;
    }

    try {
        $result = ChildProcess::run(
            [$vite, 'build', '--outDir', $scratchDir],
            $htmlReportDir,
        );

        if ($result['exitCode'] !== 0) {
            fwrite(STDERR, sprintf(
                "`vite build --outDir %s` exited %d.\nstdout:\n%s\nstderr:\n%s\n",
                $scratchDir,
                $result['exitCode'],
                $result['stdout'],
                $result['stderr'],
            ));

            return 2;
        }

        $freshBundle = $scratchDir . '/report.min.js';

        if (!is_file($freshBundle)) {
            fwrite(STDERR, sprintf("vite build did not produce %s.\n", $freshBundle));

            return 2;
        }

        $shippedContents = file_get_contents($shippedBundle);
        $freshContents = file_get_contents($freshBundle);

        if ($shippedContents === false || $freshContents === false) {
            fwrite(STDERR, "Failed to read the shipped or the rebuilt bundle.\n");

            return 2;
        }

        if ($shippedContents !== $freshContents) {
            fwrite(STDERR, sprintf(
                "%s is stale: rebuilding html-report/src/ with `vite build` produces %d byte(s) that differ"
                . " from the %d byte(s) currently shipped. Run `composer build:js` and commit the result.\n",
                $shippedBundle,
                strlen($freshContents),
                strlen($shippedContents),
            ));

            return 1;
        }

        fwrite(STDOUT, sprintf("%s matches a fresh build of html-report/src/.\n", $shippedBundle));

        return 0;
    } finally {
        removeDirectory($scratchDir);
    }
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $entries = scandir($directory);

    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . '/' . $entry;

        if (is_dir($path)) {
            removeDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

// Include-safe: requiring this file from a test only defines the functions
// above, it does not run the check.
if (realpath((string) ($_SERVER['argv'][0] ?? '')) === realpath(__FILE__)) {
    exit(checkHtmlBundleFreshness());
}
