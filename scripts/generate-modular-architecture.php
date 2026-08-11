#!/usr/bin/env php
<?php

declare(strict_types=1);

const GENERATED_ARTIFACTS = [
    'documentation-ownership.tsv',
    'manifest-enforcement-summary.tsv',
    'production-cross-owner-imports.tsv',
    'production-extension-families.tsv',
    'production-ownership.tsv',
    'production-phase-participants.tsv',
    'production-reporting-classification.tsv',
    'production-state-services.tsv',
    'test-fixture-directories.tsv',
    'test-ownership.tsv',
    'test-phpunit-discovery.txt',
    'test-phpunit-suites.txt',
];

$arguments = $_SERVER['argv'] ?? [];
$check = in_array('--check', $arguments, true);
$failAfterArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--fail-after-publish='),
));
if (count($failAfterArguments) > 1) {
    fwrite(STDERR, "Only one --fail-after-publish value is allowed.\n");
    exit(2);
}
$failAfterPublish = $failAfterArguments === []
    ? null
    : filter_var(substr($failAfterArguments[0], strlen('--fail-after-publish=')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($failAfterArguments !== [] && $failAfterPublish === false) {
    fwrite(STDERR, "--fail-after-publish requires a positive integer.\n");
    exit(2);
}
$unknown = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => $argument !== '--check' && !str_starts_with($argument, '--fail-after-publish='),
));
if ($unknown !== []) {
    fwrite(STDERR, 'Unknown argument: ' . implode(', ', $unknown) . "\n");
    exit(2);
}

$root = dirname(__DIR__);
$targetDirectory = $root . '/docs/internal/generated/modular-architecture';
$stageDirectory = sys_get_temp_dir() . '/qmx-modular-architecture-' . bin2hex(random_bytes(12));
$stageGenerated = $stageDirectory . '/generated';
$stageQmx = $stageDirectory . '/qmx.yaml';

$outputs = [];
$failure = null;
try {
    if (!mkdir($stageDirectory, 0700) || !mkdir($stageGenerated, 0700)) {
        fail('Cannot create staging directories.');
    }
    $outputs[] = runGenerator([
        PHP_BINARY,
        $root . '/scripts/generate-modular-architecture-production-inventory.php',
        '--output-directory=' . $stageGenerated,
        '--qmx-output=' . $stageQmx,
    ], $root);
    $outputs[] = runGenerator([
        PHP_BINARY,
        $root . '/scripts/generate-modular-architecture-test-inventory.php',
        '--output-directory=' . $stageGenerated,
    ], $root);

    assertExactFileSet($stageGenerated, GENERATED_ARTIFACTS, 'staged generated artifacts');
    if (!is_file($stageQmx)) {
        fail('Production generator did not render staged qmx.yaml.');
    }

    if ($check) {
        assertExactFileSet($targetDirectory, GENERATED_ARTIFACTS, 'committed generated artifacts');
        assertSameFile($stageQmx, $root . '/qmx.yaml');
        foreach (GENERATED_ARTIFACTS as $name) {
            assertSameFile($stageGenerated . '/' . $name, $targetDirectory . '/' . $name);
        }
    } else {
        publishTransaction(
            $stageQmx,
            $stageGenerated,
            $root . '/qmx.yaml',
            $targetDirectory,
            GENERATED_ARTIFACTS,
            $failAfterPublish === false ? null : $failAfterPublish,
        );
    }

    $output = implode('', $outputs);
    if ($check) {
        $output = preg_replace('/^Generated/m', 'Checked', $output) ?? $output;
    }
    fwrite(STDOUT, $output);
} catch (Throwable $error) {
    $failure = $error;
} finally {
    foreach (GENERATED_ARTIFACTS as $name) {
        $path = $stageGenerated . '/' . $name;
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
    if (is_file($stageQmx) || is_link($stageQmx)) {
        unlink($stageQmx);
    }
    if (is_dir($stageGenerated)) {
        rmdir($stageGenerated);
    }
    if (is_dir($stageDirectory)) {
        rmdir($stageDirectory);
    }
}
if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}

/** @param list<string> $command */
function runGenerator(array $command, string $workingDirectory): string
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory);
    if (!is_resource($process)) {
        fail('Cannot start generator: ' . implode(' ', $command));
    }
    fclose($pipes[0]);
    [$stdout, $stderr] = drainProcessPipes($pipes[1], $pipes[2]);
    $exitCode = proc_close($process);
    if ($stdout === false || $stderr === false || $exitCode !== 0) {
        fail(sprintf(
            "Generator failed with exit %d: %s\n%s",
            $exitCode,
            implode(' ', $command),
            trim(($stdout === false ? '' : $stdout) . ($stderr === false ? '' : $stderr)),
        ));
    }

    return $stdout;
}

/** @param resource $stdoutPipe
 * @param resource $stderrPipe
 *
 * @return array{string, string}
 */
function drainProcessPipes($stdoutPipe, $stderrPipe): array
{
    stream_set_blocking($stdoutPipe, false);
    stream_set_blocking($stderrPipe, false);
    $streams = [(int) $stdoutPipe => ['stream' => $stdoutPipe, 'index' => 0], (int) $stderrPipe => ['stream' => $stderrPipe, 'index' => 1]];
    $output = ['', ''];
    while ($streams !== []) {
        $read = array_column($streams, 'stream');
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, null) === false) {
            fail('Cannot read generator output streams.');
        }
        foreach ($read as $stream) {
            $key = (int) $stream;
            $chunk = stream_get_contents($stream);
            if ($chunk === false) {
                fail('Cannot read generator output stream.');
            }
            $output[$streams[$key]['index']] .= $chunk;
            if (feof($stream)) {
                fclose($stream);
                unset($streams[$key]);
            }
        }
    }

    return [$output[0], $output[1]];
}

/** @param list<string> $expected */
function assertExactFileSet(string $directory, array $expected, string $label): void
{
    if (!is_dir($directory)) {
        fail("Missing {$label} directory: {$directory}");
    }
    $actual = [];
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        $actual[] = $entry->getFilename();
    }
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);
    if ($actual !== $expected) {
        $missing = array_values(array_diff($expected, $actual));
        $orphan = array_values(array_diff($actual, $expected));
        fail(sprintf('%s mismatch; missing=[%s] orphan=[%s]', $label, implode(', ', $missing), implode(', ', $orphan)));
    }
}

function assertSameFile(string $rendered, string $current): void
{
    $expected = file_get_contents($rendered);
    $actual = is_file($current) ? file_get_contents($current) : false;
    if ($expected === false || $actual !== $expected) {
        fail('Generated artifact is stale: ' . $current);
    }
}

function publishAtomically(string $source, string $target): void
{
    $contents = file_get_contents($source);
    if ($contents === false) {
        fail('Cannot read staged artifact: ' . $source);
    }
    $temporary = $target . '.tmp.' . getmypid();
    try {
        if (file_put_contents($temporary, $contents) === false || !rename($temporary, $target)) {
            fail('Cannot publish generated artifact: ' . $target);
        }
    } finally {
        if (is_file($temporary) || is_link($temporary)) {
            unlink($temporary);
        }
    }
}

/**
 * @param list<string> $expected
 */
function publishTransaction(
    string $stageQmx,
    string $stageGenerated,
    string $targetQmx,
    string $targetDirectory,
    array $expected,
    ?int $failAfterPublish,
): void {
    $expectedSet = array_fill_keys($expected, true);
    $orphans = [];
    if (is_dir($targetDirectory)) {
        foreach (new DirectoryIterator($targetDirectory) as $entry) {
            if ($entry->isDot() || isset($expectedSet[$entry->getFilename()])) {
                continue;
            }
            if (!$entry->isFile() || $entry->isLink()) {
                fail('Refusing non-regular orphan generated artifact before publication: ' . $entry->getPathname());
            }
            $orphans[] = $entry->getPathname();
        }
    } elseif (file_exists($targetDirectory) || !is_writable(dirname($targetDirectory))) {
        fail('Generated-artifact target directory cannot be created safely.');
    }
    sort($orphans, SORT_STRING);

    $operations = [[$stageQmx, $targetQmx]];
    foreach ($expected as $name) {
        $operations[] = [$stageGenerated . '/' . $name, $targetDirectory . '/' . $name];
    }
    $ledger = [];
    foreach ($operations as [$source, $target]) {
        if (!is_file($source) || !is_readable($source)) {
            fail('Staged publication source is not readable: ' . $source);
        }
        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            fail('Refusing non-regular publication target: ' . $target);
        }
        $parent = dirname($target);
        if (is_dir($parent) && !is_writable($parent)) {
            fail('Publication target directory is not writable: ' . $parent);
        }
        $temporary = $target . '.tmp.' . getmypid();
        $rollbackTemporary = $target . '.rollback.' . getmypid();
        if (file_exists($temporary) || is_link($temporary) || file_exists($rollbackTemporary) || is_link($rollbackTemporary)) {
            fail('Refusing publication with a pre-existing transaction temporary for: ' . $target);
        }
        $ledger[$target] = snapshotFile($target);
    }
    foreach ($orphans as $orphan) {
        $ledger[$orphan] = snapshotFile($orphan);
    }

    $directoryExisted = is_dir($targetDirectory);
    try {
        if (!$directoryExisted && !mkdir($targetDirectory, 0777)) {
            fail('Cannot create generated-artifact target directory.');
        }
        $published = 0;
        foreach ($operations as [$source, $target]) {
            publishAtomically($source, $target);
            ++$published;
            if ($failAfterPublish === $published) {
                fail("Injected publication failure after {$published} replacement(s).");
            }
        }
        foreach ($orphans as $orphan) {
            if (!unlink($orphan)) {
                fail('Cannot remove orphan generated artifact: ' . $orphan);
            }
        }
        assertExactFileSet($targetDirectory, $expected, 'published generated artifacts');
    } catch (Throwable $error) {
        restoreLedger($ledger);
        if (!$directoryExisted && is_dir($targetDirectory)) {
            rmdir($targetDirectory);
        }
        fail('Publication transaction rolled back: ' . $error->getMessage());
    }
}

/** @return array{exists: bool, contents: string, mode: int} */
function snapshotFile(string $path): array
{
    if (!is_file($path)) {
        return ['exists' => false, 'contents' => '', 'mode' => 0];
    }
    $contents = file_get_contents($path);
    $permissions = fileperms($path);
    if ($contents === false || $permissions === false) {
        fail('Cannot snapshot publication target: ' . $path);
    }

    return ['exists' => true, 'contents' => $contents, 'mode' => $permissions & 0777];
}

/** @param array<string, array{exists: bool, contents: string, mode: int}> $ledger */
function restoreLedger(array $ledger): void
{
    $failures = [];
    foreach ($ledger as $path => $snapshot) {
        try {
            if (!$snapshot['exists']) {
                if ((is_file($path) || is_link($path)) && !unlink($path)) {
                    $failures[] = $path;
                }
                continue;
            }
            $temporary = $path . '.rollback.' . getmypid();
            if (file_put_contents($temporary, $snapshot['contents']) === false
                || !chmod($temporary, $snapshot['mode'])
                || !rename($temporary, $path)
            ) {
                $failures[] = $path;
            }
            if (is_file($temporary) || is_link($temporary)) {
                unlink($temporary);
            }
        } catch (Throwable) {
            $failures[] = $path;
        }
    }
    if ($failures !== []) {
        fail('Rollback failed for: ' . implode(', ', array_unique($failures)));
    }
}

function fail(string $message): never
{
    throw new RuntimeException($message);
}
