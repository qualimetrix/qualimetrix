<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DocumentationCensus;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\DocumentReading\BaselineFormatVersion;

/**
 * A version bump can leave the baseline's published instructions on an older
 * format. Product regressions cannot reach those independent claims.
 *
 * These declarations cover the current baseline contract, migration guidance
 * and format history in the owning README, architecture and bilingual usage
 * pages. New publication locations must be declared here; unrelated prose and
 * numbers outside these declarations are deliberately outside the control.
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

    #[Test]
    public function itBindsEveryCurrentVersionStatementToItsPublication(): void
    {
        $root = \dirname(__DIR__, 2);
        $content = [];
        foreach (self::PUBLICATIONS as ['path' => $path]) {
            if (!isset($content[$path])) {
                $bytes = file_get_contents($root . '/' . $path);
                self::assertIsString($bytes, 'Cannot read baseline publication: ' . $path);
                $content[$path] = $bytes;
            }
        }
        $problems = [];
        foreach (self::PUBLICATIONS as ['path' => $path, 'pattern' => $pattern]) {
            $bytes = $content[$path] ?? '';
            $count = preg_match_all($pattern, $bytes, $matches, \PREG_OFFSET_CAPTURE);
            if ($count !== 1) {
                $problems[] = $path . ': stale or ambiguous publication: ' . $pattern;
                continue;
            }
            $version = $matches[1][0][0];
            if ((int) $version !== BaselineFormatVersion::CURRENT) {
                $problems[] = \sprintf('%s: publication states %s; current version is %d.', $path, $version, BaselineFormatVersion::CURRENT);
            }
        }
        $history = $content[self::HISTORY_PATH] ?? '';
        preg_match_all('~^- \*\*Version (\d+)\*\*:~m', $history, $entries, \PREG_OFFSET_CAPTURE);
        $last = $entries[1] === [] ? null : $entries[1][array_key_last($entries[1])];
        if ($last === null || (int) $last[0] !== BaselineFormatVersion::CURRENT) {
            $problems[] = self::HISTORY_PATH . ': the last version-history entry must name the current version.';
        }
        self::assertSame([], $problems, implode("\n", $problems));
    }
}
