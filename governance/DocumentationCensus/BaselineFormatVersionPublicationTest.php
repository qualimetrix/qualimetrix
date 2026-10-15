<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DocumentationCensus;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\DocumentReading\BaselineFormatVersion;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * A version bump can leave a document or a newly introduced producer stating
 * another format. Product regressions cannot reach those independent claims.
 * This census binds every declared publication to the current constant, then
 * refuses an undeclared current-version spelling in tracked text.
 *
 * BaselineVersionSweep owns the population and lexical limits. The census
 * does not see numbers written as words or ordinals, version words beyond
 * its window, or historical, test and generated text. Generated declarations
 * name their writers; listing a hand-written file there would hide a claim.
 * New unrelated uses of the same number can require an exact exception;
 * exceptions become stale when the current number or their source changes.
 */
final class BaselineFormatVersionPublicationTest extends TestCase
{
    /** @var list<array{path: string, pattern: string}> */
    private const array PUBLICATIONS = [
        ['path' => 'docs/ARCHITECTURE.md', 'pattern' => '~versioned v(\d+) root baseline~'],
        ['path' => 'docs/ARCHITECTURE.md', 'pattern' => '~The version (\d+) baseline compares~'],
        ['path' => 'src/Analysis/Policy/Baseline/README.md', 'pattern' => '~Only version (\d+) is loadable~'],
        ['path' => 'src/Analysis/Policy/Baseline/README.md', 'pattern' => '~time, change version to (\d+) and explicitly add~'],
        ['path' => 'src/Analysis/Policy/Baseline/README.md', 'pattern' => '~^## File Contract \(version (\d+)\)~m'],
        ['path' => 'src/Analysis/Policy/Baseline/README.md', 'pattern' => '~^  "version": (\d+),~m'],
        ['path' => 'src/Analysis/Policy/Baseline/README.md', 'pattern' => '~\| `version` +\| Exactly `(\d+)`~'],
        ['path' => 'website/docs/usage/baseline.md', 'pattern' => '~A version (\d+) baseline records~'],
        ['path' => 'website/docs/usage/baseline.md', 'pattern' => '~Only v(\d+) is loadable~'],
        ['path' => 'website/docs/usage/baseline.md', 'pattern' => '~version to (\d+) and explicitly add~'],
        ['path' => 'website/docs/usage/baseline.ru.md', 'pattern' => '~Baseline версии (\d+) хранит~u'],
        ['path' => 'website/docs/usage/baseline.ru.md', 'pattern' => '~Загружается только v(\d+)~u'],
        ['path' => 'website/docs/usage/baseline.ru.md', 'pattern' => '~на (\d+) и явно добавь~u'],
    ];

    private const string HISTORY_PATH = 'src/Analysis/Policy/Baseline/README.md';

    /** @var list<array{file: string, line: string, token: string, reason: string}> */
    private const array EXCEPTIONS = [
        [
            'file' => 'scripts/finding-gate/ReportRecords.php',
            'line' => "if (!\\in_array(\$document['version'] ?? null, [13, 14], true) || !\\is_array(\$document['entries'] ?? null)) {",
            'token' => '14',
            'reason' => 'The corpus comparator accepts both supported captured schemas, independently of the current product format.',
        ],
        [
            'file' => 'scripts/finding-gate/ReportRecords.php',
            'line' => 'if (!$native instanceof stdClass || !\\in_array($native->version ?? null, [13, 14], true)',
            'token' => '14',
            'reason' => 'The corpus comparator accepts both supported captured schemas, independently of the current product format.',
        ],
        [
            'file' => 'input-doors/doors-reconciled.tsv',
            'line' => "config\t(qmx.yaml)\tformat\tconfig-root\t\tyes\t\t14\tREFUSES\tbinding-0\tB+me\tme: YAML format: zzznope -> exit 3",
            'token' => '14',
            'reason' => 'This is the configuration key ordinal beside a format option, not a baseline version.',
        ],
    ];

    #[Test]
    public function itBindsEveryCurrentVersionStatementToItsPublication(): void
    {
        $root = \dirname(__DIR__, 2);
        $listing = ChildProcess::run(['git', 'ls-files', '-z'], $root);
        self::assertSame(0, $listing['exitCode'], $listing['stderr']);
        $paths = array_values(array_filter(explode("\0", $listing['stdout']), static fn(string $path): bool => $path !== ''));
        self::assertNotEmpty($paths, 'git listed no tracked text to judge.');
        $content = [];
        $hits = [];
        foreach ($paths as $path) {
            if (!BaselineVersionSweep::isSwept($path) || is_link($root . '/' . $path)) {
                continue;
            }
            $bytes = file_get_contents($root . '/' . $path);
            self::assertIsString($bytes, 'Cannot read tracked path: ' . $path);
            if (str_contains(substr($bytes, 0, 8000), "\0")) {
                continue;
            }
            $content[$path] = $bytes;
            foreach (BaselineVersionSweep::hits($bytes, BaselineFormatVersion::CURRENT) as $hit) {
                $hits[] = ['file' => $path, ...$hit];
            }
        }
        self::assertNotEmpty($content, 'The version census examined no text.');
        $problems = [];
        $covered = [];
        foreach (self::PUBLICATIONS as ['path' => $path, 'pattern' => $pattern]) {
            $bytes = $content[$path] ?? '';
            $count = preg_match_all($pattern, $bytes, $matches, \PREG_OFFSET_CAPTURE);
            if ($count !== 1) {
                $problems[] = $path . ': stale or ambiguous publication: ' . $pattern;
                continue;
            }
            [$version, $offset] = $matches[1][0];
            if ((int) $version !== BaselineFormatVersion::CURRENT) {
                $problems[] = \sprintf('%s: publication states %s; current version is %d.', $path, $version, BaselineFormatVersion::CURRENT);
            }
            $publicationHits = 0;
            foreach ($hits as $i => $hit) {
                if ($hit['file'] === $path && $offset >= $hit['offset'] && $offset < $hit['offset'] + \strlen($hit['token'])) {
                    $covered[$i] = true;
                    ++$publicationHits;
                }
            }
            if ((int) $version === BaselineFormatVersion::CURRENT && $publicationHits !== 1) {
                $problems[] = $path . ': publication must cover exactly one swept version token: ' . $pattern;
            }
        }
        $history = $content[self::HISTORY_PATH] ?? '';
        preg_match_all('~^- \*\*Version (\d+)\*\*:~m', $history, $entries, \PREG_OFFSET_CAPTURE);
        $last = $entries[1] === [] ? null : $entries[1][array_key_last($entries[1])];
        if ($last === null || (int) $last[0] !== BaselineFormatVersion::CURRENT) {
            $problems[] = self::HISTORY_PATH . ': the last version-history entry must name the current version.';
        } else {
            foreach ($hits as $i => $hit) {
                if ($hit['file'] === self::HISTORY_PATH && $hit['offset'] === $last[1]) {
                    $covered[$i] = true;
                }
            }
        }
        foreach (self::EXCEPTIONS as $exception) {
            $matches = [];
            foreach ($hits as $i => $hit) {
                if ($hit['file'] === $exception['file'] && $hit['token'] === $exception['token']
                    && self::lineAt($content[$hit['file']], $hit['offset']) === $exception['line']) {
                    $matches[] = $i;
                }
            }
            if (\count($matches) !== 1) {
                $problems[] = $exception['file'] . ': stale or ambiguous version exception: ' . $exception['reason'];
            } else {
                $covered[$matches[0]] = true;
            }
        }
        foreach ($hits as $i => $hit) {
            if (!isset($covered[$i])) {
                $problems[] = \sprintf('%s:%d: undeclared statement of the current baseline version: %s', $hit['file'], substr_count(substr($content[$hit['file']], 0, $hit['offset']), "\n") + 1, $hit['token']);
            }
        }
        foreach (BaselineVersionSweep::GENERATED as $generated => $writer) {
            if (!array_any($paths, static fn(string $path): bool => $path === $generated || (str_ends_with($generated, '/') && str_starts_with($path, $generated)))) {
                $problems[] = $generated . ': stale generated row: ' . $writer;
            }
        }
        self::assertSame([], $problems, implode("\n", $problems));
    }

    private static function lineAt(string $content, int $offset): string
    {
        $start = strrpos(substr($content, 0, $offset), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($content, "\n", $offset);

        return trim(substr($content, $start, ($end === false ? \strlen($content) : $end) - $start));
    }
}
