<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Integration\Identity;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * No production visitor may cut the traversal short.
 *
 * php-parser stops calling the remaining visitors for a subtree when one of
 * them returns a traversal-control constant. Declaration numbering now rests on
 * the registrar and every producer seeing the same nodes, so a visitor that
 * skipped a subtree for the visitors after it would silently give them a
 * different lexical context and a different closure counter than the registrar.
 * The invariant used to be a convention; this is what makes it checkable.
 */
final class TraversalCompletenessTest extends TestCase
{
    private const array FORBIDDEN = [
        'DONT_TRAVERSE_CHILDREN',
        'DONT_TRAVERSE_CURRENT_AND_CHILDREN',
        'STOP_TRAVERSAL',
        'REMOVE_NODE',
    ];

    #[Test]
    public function itFindsNoTraversalControlReturnInProductionVisitors(): void
    {
        $offenders = [];
        foreach (self::sourceFiles() as $file) {
            $source = (string) file_get_contents($file);
            foreach (self::FORBIDDEN as $constant) {
                if (str_contains($source, $constant)) {
                    $offenders[] = substr($file, \strlen(\dirname(__DIR__, 6)) + 1) . ': ' . $constant;
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(\dirname(__DIR__, 6) . '/src', FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
