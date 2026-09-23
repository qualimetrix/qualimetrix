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
 * machines; it is not guarded against here, the same way no other generated-
 * artifact freshness check in this repository pins its toolchain version.
 */

use Qualimetrix\Subprocess\ChildProcess;

require_once __DIR__ . '/subprocess/ChildProcess.php';

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
