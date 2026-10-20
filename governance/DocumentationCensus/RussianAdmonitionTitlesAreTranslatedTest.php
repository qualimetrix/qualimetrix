<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DocumentationCensus;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Material renders an omitted admonition title as its English type name.
 * A strict site build accepts that output, so Russian pages need a source
 * census to keep their visible headings in Russian.
 */
final class RussianAdmonitionTitlesAreTranslatedTest extends TestCase
{
    #[Test]
    public function itGivesRenderedAdmonitionsRussianOrExplicitlyEmptyTitles(): void
    {
        $root = \dirname(__DIR__, 2);
        $listing = new Process(['git', '-C', $root, 'ls-files', '-z', '--', 'website/docs']);
        $listing->run();
        self::assertTrue($listing->isSuccessful(), $listing->getErrorOutput());

        $pages = array_values(array_filter(
            explode("\0", $listing->getOutput()),
            static fn(string $path): bool => str_ends_with($path, '.ru.md'),
        ));
        self::assertNotEmpty($pages, 'No tracked Russian documentation pages were found.');

        $failures = [];
        $judged = 0;
        foreach ($pages as $page) {
            $content = file_get_contents($root . '/' . $page);
            self::assertIsString($content, "Cannot read {$page}.");

            $fence = null;
            $lines = preg_split('/\r\n|\r|\n/', $content);
            self::assertIsArray($lines, "Cannot split {$page} into lines.");
            foreach ($lines as $index => $line) {
                if (preg_match('/^\s*(`{3,}|~{3,})(.*)$/', $line, $fenceMatch) === 1) {
                    $marker = $fenceMatch[1];
                    if ($fence === null) {
                        $fence = $marker;
                    } elseif ($marker[0] === $fence[0]
                        && \strlen($marker) >= \strlen($fence)
                        && trim($fenceMatch[2]) === '') {
                        $fence = null;
                    }

                    continue;
                }

                if ($fence !== null
                    || preg_match('/^\s*(?:!!!|\?\?\?\+?)\s+[a-z][\w-]*(?:\s+(.*))?$/i', $line, $admonition) !== 1) {
                    continue;
                }

                ++$judged;
                $title = trim($admonition[1] ?? '');
                if ($title === '""' || preg_match('/[А-Яа-яЁё]/u', $title) === 1) {
                    continue;
                }

                $failures[] = \sprintf('%s:%d: expected a Russian or explicitly empty admonition title: %s', $page, $index + 1, trim($line));
            }
        }

        self::assertGreaterThan(0, $judged, 'No rendered admonitions were found.');
        self::assertSame([], $failures, implode("\n", $failures));
    }
}
