#!/usr/bin/env php
<?php

declare(strict_types=1);

use Qualimetrix\Subprocess\ChildProcess;

require_once __DIR__ . '/subprocess/ChildProcess.php';

/**
 * Measures the viewer's fixture through the real CLI. Only the project timestamp
 * and installation version are normalized; metric bags and findings are kept.
 */
function htmlPayloadFixtureContent(): string
{
    $root = dirname(__DIR__);
    $fixture = $root . '/tests/Reporting/Fixtures/HtmlPayload';
    $scratch = sys_get_temp_dir() . '/qmx-html-payload-' . bin2hex(random_bytes(8));
    if (!mkdir($scratch . '/src', 0o700, true)) {
        throw new RuntimeException('Cannot create the HTML payload scratch project.');
    }

    try {
        foreach (['composer.json', 'qmx.yaml', 'src/Types.php'] as $path) {
            if (!copy($fixture . '/' . $path, $scratch . '/' . $path)) {
                throw new RuntimeException('Cannot copy HTML payload input: ' . $path);
            }
        }
        htmlPayloadCommand($root, $scratch, ['baseline:generate', 'baseline.json', 'src', '--workers=0', '--no-cache']);
        $growth = file_get_contents($fixture . '/Growth.php');
        if ($growth === false || substr_count($growth, '__INVALID_BYTE__') !== 1) {
            throw new RuntimeException('The HTML payload growth input requires one byte placeholder.');
        }
        if (file_put_contents($scratch . '/src/Growth.php', str_replace('__INVALID_BYTE__', "\xFF", $growth)) === false) {
            throw new RuntimeException('Cannot write the HTML payload growth input.');
        }
        $html = htmlPayloadCommand($root, $scratch, ['check', 'src', '--baseline=baseline.json', '--format=html', '--workers=0', '--no-cache']);
        $document = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR);
        $data = $document->getElementById('report-data');
        $text = $data?->textContent;
        if ($text === null) {
            throw new RuntimeException('The HTML report has no report-data element.');
        }
        $payload = json_decode($text, false, 512, \JSON_THROW_ON_ERROR);
        if (!$payload instanceof stdClass || !isset($payload->project, $payload->tree, $payload->summary)) {
            throw new RuntimeException('The HTML report has no complete viewer payload.');
        }
        $payload->project->generatedAt = '2000-01-01T00:00:00+00:00';
        $payload->project->qmxVersion = '<version>';
        $content = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
        if (str_contains($content, $scratch)) {
            throw new RuntimeException('The HTML payload unexpectedly publishes its temporary project path.');
        }

        return $content;
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($scratch);
    }
}

/** @param list<string> $arguments */
function htmlPayloadCommand(string $root, string $directory, array $arguments): string
{
    $command = [\PHP_BINARY, $root . '/bin/qmx', ...$arguments];
    $environment = getenv();
    unset($environment['QMX_ASCII']);
    $result = ChildProcess::run($command, $directory, environment: $environment);
    fwrite(STDERR, 'Measured ' . json_encode($command, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES) . ' in ' . $directory . ' (exit ' . $result['exitCode'] . ")\n");
    if ($result['exitCode'] < 0 || $result['exitCode'] > 2) {
        throw new RuntimeException('HTML payload measurement failed (exit ' . $result['exitCode'] . '): ' . $result['stderr']);
    }

    return $result['stdout'];
}

function generateHtmlPayloadFixture(): int
{
    $arguments = array_slice($_SERVER['argv'] ?? [], 1);
    if ($arguments !== [] && $arguments !== ['--check']) {
        fwrite(STDERR, "Usage: php scripts/generate-html-payload-fixture.php [--check]\n");

        return 2;
    }
    $path = dirname(__DIR__) . '/html-report/tests/fixtures/payload.json';
    try {
        $content = htmlPayloadFixtureContent();
        if ($arguments === ['--check']) {
            if (!is_file($path) || file_get_contents($path) !== $content) {
                fwrite(STDERR, "The HTML payload fixture is stale. Run php scripts/generate-html-payload-fixture.php.\n");

                return 1;
            }
            fwrite(STDOUT, "The HTML payload fixture is current.\n");

            return 0;
        }
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Cannot write the HTML payload fixture.');
        }
        fwrite(STDOUT, "Wrote the HTML payload fixture.\n");

        return 0;
    } catch (Throwable $failure) {
        fwrite(STDERR, $failure->getMessage() . "\n");

        return 2;
    }
}

if (realpath((string) ($_SERVER['argv'][0] ?? '')) === realpath(__FILE__)) {
    exit(generateHtmlPayloadFixture());
}
